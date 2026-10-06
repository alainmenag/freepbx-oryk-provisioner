<?php

// src/Users.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * Extension/Users: saving, deleting and listing them.
 *
 * A user is not a table of this module's. It is a FreePBX extension, and
 * the pjsip device that is that extension's own -- device id, extension and
 * User Manager username are one number. The extension is what makes it a
 * user: one whose device has been deleted is listed still, without what the
 * device held, and a save gives it a device back. Everything about it lives
 * in Core, User Manager, Voicemail, the CDR database and the custom endpoint
 * file. See ARCHITECTURE.md, "Users".
 *
 * store() and remove() are sequences over the collaborators below, ported from
 * oryk_connect's DeviceManager with the other kinds taken out.
 */
class Users extends Service
{
	/**
	 * Where users are read from: every extension, with its own device beside
	 * it when it has one -- the pjsip device numbered like it. `d.id` is NULL
	 * on an extension whose device has been deleted. Interpolated into SQL,
	 * so a literal: nothing in it may come from a request.
	 */
	const FROM = "FROM users u LEFT JOIN devices d ON d.id = u.extension AND d.tech = 'pjsip' AND d.user = u.extension";

	/**
	 * Which extensions are users, over FROM: all but one whose number is held
	 * by a device of another kind, which this module could not give a device.
	 */
	const SHAPE = "NOT EXISTS (SELECT 1 FROM devices o WHERE o.id = u.extension AND NOT (o.tech = 'pjsip' AND o.user = u.extension))";

	/**
	 * Which clients are a user's, over FROM and `clients pc`: the ones on any
	 * device of its extension, and any still naming the extension itself.
	 * Clients::listClients() says the same for `&extension=`.
	 */
	const CLIENT_OF = "(pc.device_id = u.extension OR pc.device_id IN (SELECT ud.id FROM devices ud WHERE ud.user = u.extension))";

	/** A user's context, over FROM: NULL without a device. Interpolated, so a literal. */
	const CONTEXT_EXPR = "(SELECT s.data FROM sip s WHERE s.id = u.extension AND s.keyword = 'context' LIMIT 1)";

	/** Driver settings written on every save, over the defaults and the form alike. */
	const FORCED = [
		'media_encryption' => 'sdes',
		'media_encryption_optimistic' => 'yes',
	];

	/** What the editor posts, keyed by the device keyword each is stored under. */
	const FIELDS = [
		'description' => 'name',
		'email' => 'email',
		'from_domain' => 'from_domain',
		'secret' => 'secret',
	];

	/**
	 * Keywords kept on a device that no driver default names, carried over on
	 * a save whether or not the form posted them.
	 */
	const CUSTOM = ['email', 'from_domain'];

	/**
	 * Stored device settings a save works out for itself rather than carrying
	 * over: they name the number or the name, and either may just have changed.
	 */
	const DERIVED = ['id', 'tech', 'devicetype', 'account', 'dial', 'mailbox', 'user', 'description', 'callerid'];

	/**
	 * What a new open-provisioning username may be. signupUsername() also refuses
	 * only digits (findByExtension() would take it for that extension's account)
	 * and IP addresses (they read as one in the security log).
	 */
	const SIGNUP_USERNAME = '/\A[A-Za-z0-9._@-]{1,64}\z/';

	/**
	 * Names a sign-up may not take, lowercase: the roles and shared mailboxes a
	 * stranger could take before the real account is made. Matched by
	 * reservedUsername(), on the whole name and on the part before an `@`.
	 */
	const RESERVED = [
		// administration
		'admin', 'administrator', 'root', 'sysadmin', 'superuser', 'system', 'sys', 'owner',
		'manager', 'master', 'webmaster', 'hostmaster', 'postmaster', 'security', 'abuse', 'noc', 'it',
		// front of house
		'reception', 'receptionist', 'frontdesk', 'front-desk', 'front.desk', 'front_desk', 'operator',
		'attendant', 'switchboard', 'main', 'office', 'lobby',
		// shared mailboxes
		'support', 'help', 'helpdesk', 'service', 'info', 'contact', 'sales', 'billing', 'accounts',
		'accounting', 'finance', 'hr', 'marketing', 'orders', 'noreply', 'no-reply',
		// telephony, and the PBX itself
		'pbx', 'freepbx', 'asterisk', 'sip', 'voip', 'ucp', 'voicemail', 'fax', 'conference', 'paging',
		'intercom', 'queue', 'ringgroup', 'emergency', 'oryk', 'provisioner', 'provisioning',
		// placeholders
		'guest', 'user', 'test', 'demo', 'default', 'null', 'anonymous', 'unknown', 'nobody',
	];

	/** admin, sysadmin or root followed by a separator or a digit: admin2, admin.ny, root_1 -- not rootsmith. */
	const RESERVED_PREFIX = '/^(admin|sysadmin|root)[._0-9-]/';

	/** The MySQL named lock every save and every open-provisioning sign-up holds. */
	const LOCK = 'oryk_provisioner_users';

	/** Seconds to wait for LOCK before giving up. */
	const LOCK_WAIT = 30;

	/** @var int How many withLock() calls this request is inside: the lock is taken once. */
	private $lockDepth = 0;

	/** @var NumberAllocator */
	private $numbers;

	/** @var ExtensionRenumberer */
	private $renumberer;

	/** @var ExtensionManager */
	private $extensions;

	/** @var UsermanManager */
	private $userman;

	/** @var VoicemailManager */
	private $voicemail;

	/** @var UcpAssignments */
	private $ucp;

	/** @var CdrHistory */
	private $cdr;

	/** @var EndpointSettings */
	private $endpoints;

	/** @var Clients */
	private $clients;

	/** @var RealtimeBridge|null Null where nothing is bridged: the tests. */
	private $bridge;

	/** @var Settings|null Made when first asked for; see settings(). */
	private $settings;

	/**
	 * @param object              $freepbx    FreePBX application instance.
	 * @param NumberAllocator     $numbers    Number allocation.
	 * @param ExtensionRenumberer $renumberer Renumbering.
	 * @param ExtensionManager    $extensions Core extensions.
	 * @param UsermanManager      $userman    User Manager accounts.
	 * @param VoicemailManager    $voicemail  Mailboxes.
	 * @param UcpAssignments      $ucp        UCP assignments.
	 * @param CdrHistory          $cdr        Call history.
	 * @param EndpointSettings    $endpoints  Custom pjsip endpoint settings.
	 * @param Clients             $clients    Provisioner clients pointing at a user.
	 * @param RealtimeBridge|null $bridge     Sign-ups live before Apply Config.
	 */
	public function __construct(
		$freepbx,
		NumberAllocator $numbers,
		ExtensionRenumberer $renumberer,
		ExtensionManager $extensions,
		UsermanManager $userman,
		VoicemailManager $voicemail,
		UcpAssignments $ucp,
		CdrHistory $cdr,
		EndpointSettings $endpoints,
		Clients $clients,
		?RealtimeBridge $bridge = null
	) {
		parent::__construct($freepbx);

		$this->numbers = $numbers;
		$this->renumberer = $renumberer;
		$this->extensions = $extensions;
		$this->userman = $userman;
		$this->voicemail = $voicemail;
		$this->ucp = $ucp;
		$this->cdr = $cdr;
		$this->endpoints = $endpoints;
		$this->clients = $clients;
		$this->bridge = $bridge;
	}

	/**
	 * Rows for the Users table.
	 *
	 * Subqueries rather than a join and GROUP BY, so ONLY_FULL_GROUP_BY holds
	 * whether or not `devices.id` has the unique key install() tries to add.
	 *
	 * @param array<int, string>|null $extensions Users to keep, or null for
	 *                                           all: a navigator scope.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	public function listUsers($extensions = null)
	{
		// Written into the statement, so only these; anything else sorts by number.
		$sortable = [
			'extension' => 'u.extension + 0',
			'name' => 'name',
			'email' => 'email',
			'clients' => 'clients',
			'secure' => 'secure',
			'context' => 'context',
			'last_seen' => 'last_seen',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['extension'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$where = 'WHERE ' . self::SHAPE;

		if ($extensions !== null) {
			$where .= ' AND ' . $this->inClause('u.extension', $extensions, 'extension', $params);
		}

		// Whitelisted: anything else is the whole list. Both values are bound.
		$filter = (string) ($_REQUEST['filter'] ?? '');

		if ($filter === 'lobby' || $filter === 'expired') {
			$where .= ' AND ' . self::CONTEXT_EXPR . ' = :lobby';
			$params[':lobby'] = $this->lobbyContext();
		}

		if ($filter === 'expired') {
			$days = $this->expireDays();
			$where .= $days > 0 ? ' AND ' . $this->expiredExpr() : ' AND 0';

			if ($days > 0) {
				$params[':days'] = $days;
			}
		}

		if ($search !== '') {
			$where .= " AND (u.extension LIKE :search
				OR u.name LIKE :search
				OR d.description LIKE :search
				OR EXISTS (SELECT 1 FROM sip e WHERE e.id = u.extension AND e.keyword = 'email' AND e.data LIKE :search))";
			$params[':search'] = '%' . $search . '%';
		}

		$countStmt = $this->db->prepare('SELECT COUNT(*) ' . self::FROM . " $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$stmt = $this->db->prepare(
			"SELECT " . $this->columns() . "
			" . self::FROM . "
			$where
			ORDER BY $sort $order
			LIMIT :limit OFFSET :offset"
		);
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
		}
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmt->execute();

		return [
			'total' => $total,
			'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
		];
	}

	/**
	 * One user, for the editor.
	 *
	 * @param mixed $extension Extension number.
	 *
	 * @return array<string, mixed>|null The user, or null when it is not one.
	 */
	public function userRow($extension)
	{
		$extension = trim((string) $extension);

		if ($extension === '' || !ctype_digit($extension)) {
			return null;
		}

		try {
			$stmt = $this->db->prepare(
				"SELECT " . $this->columns() . "
				" . self::FROM . "
				WHERE u.extension = :id AND " . self::SHAPE
			);
			$stmt->execute([':id' => $extension]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return null;
		}

		return $row ?: null;
	}

	/**
	 * Every user, for the navigator. Unpaged, like clientChoices().
	 *
	 * @return array<int, array<string, mixed>> Rows: extension, name.
	 */
	public function userChoices()
	{
		try {
			$stmt = $this->db->prepare(
				"SELECT u.extension AS extension, COALESCE(d.description, u.name) AS name
				" . self::FROM . "
				WHERE " . self::SHAPE . "
				ORDER BY u.extension + 0, u.extension"
			);
			$stmt->execute();

			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Save from the editor.
	 *
	 * Only the editor's fields are passed on: store() also takes what open
	 * provisioning sets, and a request may not.
	 *
	 * @param array<string, mixed> $request id (current number, '' for new),
	 *                                      extension, name, email,
	 *                                      from_domain, secret.
	 *
	 * @return array<string, mixed> Status and the number saved, and `reload`
	 *                              (Apply Config is pending); or a message.
	 */
	public function saveUser($request)
	{
		$request = is_array($request) ? $request : [];
		$input = array_intersect_key($request, array_flip(array_merge(['id', 'extension'], array_values(self::FIELDS))));

		try {
			$id = $this->store($input);
		} catch (\Exception $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}

		return ['status' => true, 'id' => $id, 'reload' => true];
	}

	/**
	 * Delete from the editor, or Overview's Delete All.
	 *
	 * @param mixed $extension Extension number.
	 *
	 * @return array<string, mixed> Status and `reload`, or a message when it was refused.
	 */
	public function deleteUser($extension)
	{
		if (!$this->userRow($extension)) {
			return ['status' => false, 'message' => _('That user no longer exists.')];
		}

		$this->remove($extension);

		return ['status' => true, 'reload' => true];
	}

	/**
	 * What a user has in FreePBX beside its extension, for Overview.
	 *
	 * Says what remove() will do, not what exists: an account this module does
	 * not own is not listed.
	 *
	 * @param mixed $extension Extension number.
	 *
	 * @return array<string, mixed> account (the owned User Manager username,
	 *                              or ''), account_id (its id there, or 0),
	 *                              mailbox (bool).
	 */
	public function related($extension)
	{
		$extension = (string) $extension;
		$account = $this->userman->ownedAccount($extension);

		return [
			'account' => $account ? (string) $account['username'] : '',
			'account_id' => $account ? (int) $account['id'] : 0,
			'mailbox' => $this->voicemail->hasMailbox($extension),
		];
	}

	/**
	 * The devices on an extension other than its own.
	 *
	 * @param string $extension Extension number.
	 *
	 * @return array<int, string> Device ids.
	 */
	private function otherDevices($extension)
	{
		try {
			$stmt = $this->db->prepare('SELECT id FROM devices WHERE user = ? AND id <> ?');
			$stmt->execute([$extension, $extension]);

			return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * The FreePBX devices on a user's extension, for Overview.
	 *
	 * The user's own device -- the one whose id is the extension -- is the
	 * one this module saves; `own` says which. remove() deletes them all.
	 *
	 * @param mixed $extension Extension number.
	 *
	 * @return array<string, mixed> total, rows: id, tech, description, own,
	 *                              and clients, how many point at it.
	 */
	public function listDevices($extension)
	{
		$extension = (string) $extension;

		if (!$this->userRow($extension)) {
			return ['total' => 0, 'rows' => []];
		}

		try {
			$stmt = $this->db->prepare(
				"SELECT id, tech, description,
					(SELECT COUNT(*) FROM `{$this->clientsTable}` pc WHERE pc.device_id = devices.id) AS clients
				FROM devices WHERE user = ? ORDER BY id = ? DESC, id + 0, id"
			);
			$stmt->execute([$extension, $extension]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return ['total' => 0, 'rows' => []];
		}

		foreach ($rows as &$row) {
			$row['own'] = (string) $row['id'] === $extension ? 1 : 0;
		}
		unset($row);

		return ['total' => count($rows), 'rows' => $rows];
	}

	/**
	 * Delete one device on a user's extension. A client pointing at it is
	 * kept and left with no device (Clients::unassignDevice()), or deleted
	 * with it when asked: the answer to the question the page puts.
	 *
	 * The device and nothing else of the user's: the extension, its account,
	 * mailbox and history stay, which is what tells this from deleteUser().
	 * Deleting the user's own device leaves a user with no device. Looked up
	 * on this extension, so an id posted for a device elsewhere deletes nothing.
	 *
	 * @param mixed $extension   Extension number.
	 * @param mixed $device      Device id, as listDevices() lists it.
	 * @param bool  $withClients True to delete its clients rather than unassign them.
	 *
	 * @return array<string, mixed> Status and `reload`; or a message.
	 */
	public function deleteDevice($extension, $device, $withClients = false)
	{
		$extension = (string) $extension;
		$device = (string) $device;

		if (!$this->userRow($extension)) {
			return ['status' => false, 'message' => _('That user no longer exists.')];
		}

		try {
			$stmt = $this->db->prepare('SELECT COUNT(*) FROM devices WHERE id = ? AND user = ?');
			$stmt->execute([$device, $extension]);
			$found = (int) $stmt->fetchColumn() > 0;
		} catch (\Exception $e) {
			$found = false;
		}

		if ($device === '' || !$found) {
			return ['status' => false, 'message' => _('That device is no longer on this extension.')];
		}

		try {
			\FreePBX::Core()->delDevice($device);
		} catch (\Exception $e) {
			$this->logError('unable to delete device ' . $device . ': ' . $e->getMessage());

			return ['status' => false, 'message' => _('The device could not be deleted; see the FreePBX log.')];
		}

		$this->endpoints->forget($device);

		if ($withClients) {
			$this->clients->deleteForDevice($device);
		} else {
			$this->clients->unassignDevice($device);
		}

		// The user's own: a sign-up's bridge rows would outlive it.
		if ($device === $extension && $this->bridge) {
			$this->bridge->remove($device);
		}

		self::pending();

		return ['status' => true, 'reload' => true];
	}

	/**
	 * Delete a device by its id alone: the device a client being deleted
	 * was using. Its extension is looked up, and deleteDevice() does the rest.
	 *
	 * @param mixed $device Device id.
	 *
	 * @return array<string, mixed> Status and `reload`; or a message. A
	 *                              device that has already gone is a success.
	 */
	public function deleteDeviceById($device)
	{
		$device = (string) $device;

		try {
			$stmt = $this->db->prepare('SELECT user FROM devices WHERE id = ?');
			$stmt->execute([$device]);
			$extension = $stmt->fetchColumn();
		} catch (\Exception $e) {
			$extension = false;
		}

		if ($extension === false) {
			return ['status' => true];
		}

		return $this->deleteDevice((string) $extension, $device);
	}

	/**
	 * One page of a user's call history, for Overview.
	 *
	 * @param mixed $extension Extension number.
	 *
	 * @return array<string, mixed> CdrHistory::listCalls(); empty for no such user.
	 */
	public function listCalls($extension)
	{
		return $this->userRow($extension)
			? $this->cdr->listCalls($extension)
			: ['total' => 0, 'rows' => [], 'available' => false];
	}

	/**
	 * Remove a user's call history and recordings and keep the user.
	 *
	 * What a delete does to the history, on its own: CdrHistory::purge(), so
	 * a call with another extension leaves that one's history too.
	 *
	 * @param mixed $extension Extension number.
	 *
	 * @return array<string, mixed> Status, with rows and recordings removed; or a message.
	 */
	public function clearHistory($extension)
	{
		if (!$this->userRow($extension)) {
			return ['status' => false, 'message' => _('That user no longer exists.')];
		}

		return ['status' => true] + $this->cdr->purge($extension);
	}

	/**
	 * One page of a user's voicemail messages, for Overview.
	 *
	 * @param mixed $extension Extension number.
	 *
	 * @return array<string, mixed> total, and rows as
	 *                              VoicemailManager::messagesIn() lists them.
	 */
	public function listVoicemail($extension)
	{
		$messages = $this->userRow($extension)
			? $this->voicemail->messagesIn($this->voicemail->mailboxPath($extension))
			: [];

		return [
			'total' => count($messages),
			'rows' => array_slice($messages, max(0, (int) ($_REQUEST['offset'] ?? 0)), max(1, (int) ($_REQUEST['limit'] ?? 10))),
		];
	}

	/**
	 * Delete one of a user's voicemail messages.
	 *
	 * @param mixed $extension Extension number.
	 * @param mixed $id        The message, as listVoicemail() lists it.
	 *
	 * @return array<string, mixed> Status; or a message.
	 */
	public function deleteVoicemail($extension, $id)
	{
		if (!$this->userRow($extension)) {
			return ['status' => false, 'message' => _('That user no longer exists.')];
		}

		return $this->voicemail->deleteIn($this->voicemail->mailboxPath($extension), (string) $id)
			? ['status' => true]
			: ['status' => false, 'message' => _('That message is no longer there. Refresh the table.')];
	}

	/**
	 * Delete a user's voicemail messages and keep the mailbox and the user.
	 *
	 * @param mixed $extension Extension number.
	 *
	 * @return array<string, mixed> Status and `removed`; or a message.
	 */
	public function clearVoicemail($extension)
	{
		if (!$this->userRow($extension)) {
			return ['status' => false, 'message' => _('That user no longer exists.')];
		}

		return ['status' => true, 'removed' => $this->voicemail->clearIn($this->voicemail->mailboxPath($extension))];
	}

	/**
	 * Delete the expired lobby users an admin was shown: Delete listed.
	 *
	 * Each extension posted is asked again, here, whether it is still expired
	 * -- its phone may have been seen since the list was drawn, its context
	 * may have been changed, or the setting changed -- and is skipped when it
	 * is not.
	 *
	 * @param mixed $extensions Extensions, as posted.
	 *
	 * @return array<string, mixed> status, deleted and skipped counts, `reload`.
	 */
	public function deleteExpired($extensions)
	{
		$days = $this->expireDays();

		if ($days <= 0) {
			return ['status' => false, 'message' => _('Lobby Expiry is off.')];
		}

		$extensions = array_values(array_unique(array_filter(array_map('strval', is_array($extensions) ? $extensions : []), 'ctype_digit')));
		$deleted = 0;
		$skipped = 0;

		try {
			$this->withLock(function () use ($extensions, $days, &$deleted, &$skipped) {
				foreach ($extensions as $extension) {
					$row = $this->userRow($extension);
					$still = $row
						&& (string) $row['context'] === $this->lobbyContext()
						&& self::expired($row['last_seen_age'], $row['signed_up_age'], $days);

					if ($still && $this->remove($extension)) {
						$deleted++;
					} else {
						$skipped++;
					}
				}
			});
		} catch (\Exception $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}

		return ['status' => true, 'deleted' => $deleted, 'skipped' => $skipped, 'reload' => $deleted > 0];
	}

	/**
	 * Whether a lobby user has gone unseen too long.
	 *
	 * Seen: expired once that is more than $days ago. Never seen: once signing
	 * up is. Neither known: never. **expiredExpr() is this in SQL** -- the two
	 * must agree.
	 *
	 * @param int|string|null $seenAge     Seconds since its phone was last answered, or null.
	 * @param int|string|null $signedUpAge Seconds since it signed up, or null.
	 * @param int             $days        ORYK_OPEN_EXPIRE_DAYS; 0 or less is never.
	 *
	 * @return bool True when it is expired.
	 */
	public static function expired($seenAge, $signedUpAge, $days)
	{
		$days = (int) $days;
		$age = $seenAge !== null && $seenAge !== '' ? $seenAge : $signedUpAge;

		if ($days <= 0 || $age === null || $age === '') {
			return false;
		}

		return (int) $age > $days * 86400;
	}

	/**
	 * ORYK_OPEN_EXPIRE_DAYS, as a number.
	 *
	 * @return int Days; 0 is off.
	 */
	private function expireDays()
	{
		return max(0, (int) $this->settings()->get(Settings::OPEN_EXPIRE_DAYS));
	}

	/**
	 * The user an existing User Manager login names: its account's default
	 * extension. Takes no lock and makes nothing -- see signUp() for a username
	 * no account holds.
	 *
	 * @param mixed $username Username offered.
	 * @param mixed $password Password offered.
	 *
	 * @return array{extension: string, created: bool}|null Null when they are
	 *                                                       not a login.
	 *
	 * @throws \InvalidArgumentException When the username or password is unusable.
	 * @throws \RuntimeException         When User Manager is not available, or
	 *                                   the account has no Extension/User.
	 */
	public function findLogin($username, $password)
	{
		$username = (string) $username;
		$password = (string) $password;

		if ($username === '' || $username !== trim($username)) {
			throw new \InvalidArgumentException(_('The username may not be blank or begin or end with a space.'));
		}

		if ($password === '') {
			throw new \InvalidArgumentException(_('The password may not be blank.'));
		}

		// Without it no login is ever found, and every request would make a user
		if (!$this->userman->available()) {
			throw new \RuntimeException(_('User Manager is not available.'));
		}

		$account = $this->userman->authenticate($username, $password);

		if (!$account) {
			return null;
		}

		$extension = (string) ($account['default_extension'] ?? '');

		// A phone needs the device: an extension whose device has gone is not a login.
		$row = $this->userRow($extension);

		if (!$row || empty($row['device'])) {
			throw new \RuntimeException(_('That login has no Extension/User.'));
		}

		return ['extension' => $extension, 'created' => false];
	}

	/**
	 * Make a user for an open-provisioning username no account holds.
	 *
	 * Made as the editor does with a blank Extension, then put in the lobby:
	 * ORYK_OPEN_CONTEXT, the lobby emergency caller id, one contact, and no UCP
	 * login. Its account is given the username and password; the SIP secret
	 * stays Core's generated one. Nothing is reloaded -- see ARCHITECTURE.md,
	 * "Open provisioning".
	 *
	 * Holds LOCK from the username check to the UCP lock-down, so two sign-ups
	 * cannot get the same number and $admit counts what is already written. A
	 * caller that writes more under the same lock (the client, the bridge) wraps
	 * this in withLock() itself.
	 *
	 * @param string        $username Username offered; findLogin() found no login.
	 * @param string        $password Password offered.
	 * @param callable|null $admit    Run once the name is accepted and before
	 *                                anything is written; throws SignupRefused
	 *                                to refuse.
	 *
	 * @return array{extension: string, created: bool}|null Null when the
	 *                                                       username is held
	 *                                                       under another password.
	 *
	 * @throws \InvalidArgumentException When the username is not one a sign-up may take.
	 * @throws SignupRefused             When it is reserved, or $admit refuses.
	 * @throws \Exception                When the user could not be saved.
	 */
	public function signUp($username, $password, ?callable $admit = null)
	{
		$username = (string) $username;
		$password = (string) $password;

		return $this->withLock(function () use ($username, $password, $admit) {
			if ($this->userman->usernameTaken($username)) {
				return null;
			}

			// Only a username being made is held to these; an existing login is not.
			if (!self::signupUsername($username)) {
				throw new \InvalidArgumentException(_('A new username may use only letters, digits, ".", "_", "@" and "-", up to 64 characters, and may not be only digits or an IP address.'));
			}

			if (self::reservedUsername($username, $this->ownDomains())) {
				throw new SignupRefused('reserved', 400, _('That username is reserved.'));
			}

			if ($admit) {
				$admit();
			}

			// No email, even when the username is one: it is unverified, and User
			// Manager would mail it a welcome.
			$extension = $this->store([
				'extension' => '',
				'name' => '',
				'email' => '',
				'context' => $this->lobbyContext(),
				'emergency_cid' => (string) $this->settings()->get(Settings::OPEN_EMERGENCY_CID),
				'lobby' => true,
			]);

			// A user whose login could not be set is one nobody can provision as
			try {
				$this->userman->setLogin($extension, $username, $password);
			} catch (\Exception $e) {
				$this->remove($extension);

				throw $e;
			}

			// Logged, not thrown: the phone still needs its user, and the account
			// is listed under Lobby for an admin to look at.
			if (!$this->userman->denyUcp($extension)) {
				$this->logWarning('could not switch UCP off for sign-up ' . $extension);
			}

			return ['extension' => $extension, 'created' => true];
		});
	}

	/**
	 * Whether a new username is one sign-up may not take.
	 *
	 * RESERVED and RESERVED_PREFIX are asked of the whole name and of the part
	 * before an `@`, whatever the domain; an address at one of $domains, or a
	 * subdomain of one, is reserved whatever comes before it. Case is ignored.
	 * Existing logins are never asked this.
	 *
	 * @param string             $username Username offered.
	 * @param array<int, string> $domains  The PBX's own domains; an IP
	 *                                     address among them is skipped.
	 *
	 * @return bool True when it is reserved.
	 */
	public static function reservedUsername($username, array $domains = [])
	{
		$name = strtolower((string) $username);
		$at = strrpos($name, '@');
		$local = $at === false ? $name : substr($name, 0, $at);
		$domain = $at === false ? '' : substr($name, $at + 1);

		foreach (array_unique([$name, $local]) as $candidate) {
			if (in_array($candidate, self::RESERVED, true) || preg_match(self::RESERVED_PREFIX, $candidate)) {
				return true;
			}
		}

		if ($domain === '') {
			return false;
		}

		foreach ($domains as $own) {
			$own = strtolower(trim((string) $own, " \t."));

			if ($own === '' || filter_var($own, FILTER_VALIDATE_IP) !== false) {
				continue;
			}

			if ($domain === $own || substr($domain, -strlen($own) - 1) === '.' . $own) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The domains a sign-up may not take an address at: ORYK_HOSTNAME and
	 * ORYK_FROM_DOMAIN, whichever are set.
	 *
	 * @return array<int, string> Domains, possibly none.
	 */
	private function ownDomains()
	{
		return array_values(array_filter([
			(string) $this->settings()->get(Settings::HOSTNAME),
			(string) $this->settings()->get(Settings::FROM_DOMAIN),
		], 'strlen'));
	}

	/**
	 * The context open-provisioning sign-ups are put in.
	 *
	 * @return string ORYK_OPEN_CONTEXT, or its default when it is not a context name.
	 */
	public function lobbyContext()
	{
		$context = (string) $this->settings()->get(Settings::OPEN_CONTEXT);

		return preg_match(Settings::CONTEXT_PATTERN, $context) ? $context : 'lobby';
	}

	/**
	 * The module's settings, made when first asked for.
	 *
	 * @return Settings
	 */
	private function settings()
	{
		if ($this->settings === null) {
			$this->settings = new Settings($this->FreePBX);
		}

		return $this->settings;
	}

	/**
	 * Whether open provisioning may make a user with this username:
	 * SIGNUP_USERNAME, not only digits, and not an IP address.
	 *
	 * @param string $username Username offered.
	 *
	 * @return bool True when it may.
	 */
	public static function signupUsername($username)
	{
		$username = (string) $username;

		return preg_match(self::SIGNUP_USERNAME, $username) === 1
			&& !ctype_digit($username)
			&& filter_var($username, FILTER_VALIDATE_IP) === false;
	}

	/**
	 * Store or update a user.
	 *
	 * $input is a copy and nothing here reads the request. A number that is
	 * refused throws before anything is written.
	 *
	 * The number: blank on a new user takes the next free one; blank on an
	 * existing user keeps its own; anything typed has to be free, and a
	 * different one renumbers. A blank secret on an existing user keeps the
	 * stored one.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 *
	 * @return string The number saved under.
	 *
	 * @throws \Exception When the number is refused, the id is not a user, or
	 *                    Core would not write the device.
	 */
	public function store(array $input)
	{
		// Under the lock, so the number generate() or assertAvailable() settles on
		// is still free when the device is written.
		return $this->withLock(function () use ($input) {
			return $this->save($input);
		});
	}

	/**
	 * store() without the lock; only store() calls it.
	 *
	 * @param array<string, mixed> $input Submitted values.
	 *
	 * @return string The number saved under.
	 */
	private function save(array $input)
	{
		$id = trim((string) ($input['id'] ?? ''));
		$requested = trim((string) ($input['extension'] ?? ''));
		$email = array_key_exists('email', $input) ? trim((string) $input['email']) : null;
		$stored = $id === '' ? null : $this->device($id);

		// An extension whose device has been deleted: this save gives it one
		// back on its own number, as a new user's would.
		$bare = $id !== '' && !$stored && $this->extensions->exists($id);

		// Only a user is saved as one: a handset posted here would be deleted
		// and added back as an extension of its own
		if ($id !== '' && !$bare && (!$stored || ($stored['tech'] ?? '') !== 'pjsip' || (string) ($stored['user'] ?? '') !== (string) $stored['id'])) {
			throw new \Exception(_('That user no longer exists.'));
		}

		if ($bare && $requested !== '' && $requested !== $id) {
			throw new \Exception(_('This extension has no device. Save it once to give it one, then change its number.'));
		}

		if ($bare) {
			$uid = $id;
		} elseif ($requested === '') {
			$uid = $stored ? (string) $stored['id'] : $this->numbers->generate();
		} else {
			$uid = $this->numbers->assertAvailable($requested, $id);
		}

		$description = trim((string) ($input['name'] ?? ''));
		$description = $description !== '' ? $description : $uid;
		$renumbered = $stored && (string) $stored['id'] !== (string) $uid;

		// Takes the extension, account, mailbox, UCP access, call history,
		// endpoint section and provisioner clients to the new number
		if ($renumbered) {
			$this->renumberer->renumber($stored['id'], $uid, $description, 'pjsip', $email);
		}

		$generated = \FreePBX::Core()->generateDefaultDeviceSettings('pjsip', $uid, $description, false);

		$flags = 0;
		$defaults = \FreePBX::Core()->getDriver('pjsip')->getDefaultDeviceSettings($uid, $description, $flags);

		// A save starts from what the device already had, not from defaults:
		// the device is deleted and added again below, and an extension made in
		// FreePBX may carry settings this editor has no field for. Only keywords
		// the driver knows, and CUSTOM, are carried, so nothing stray is written
		// to `sip`.
		if ($stored) {
			foreach ($stored as $keyword => $value) {
				if (in_array($keyword, self::DERIVED, true)) {
					continue;
				}

				if (isset($generated[$keyword]) || in_array($keyword, self::CUSTOM, true)) {
					$generated[$keyword] = ['value' => $value, 'flag' => $generated[$keyword]['flag'] ?? 0];
				}
			}

			// A renumbered emergency caller id that was the old number follows it
			if ($renumbered && (string) ($stored['emergency_cid'] ?? '') === (string) $stored['id']) {
				$generated['emergency_cid']['value'] = '';
			}
		}

		foreach (self::FIELDS as $keyword => $field) {
			if (!array_key_exists($field, $input)) {
				continue;
			}

			$value = trim((string) $input[$field]);

			if ($field === 'name') {
				$value = $description;
			}

			// Blank keeps the stored secret, or Core's generated one on a new user
			if ($field === 'secret' && $value === '') {
				continue;
			}

			$generated[$keyword] = ['value' => $value, 'flag' => $generated[$keyword]['flag'] ?? 0];
		}

		// Open provisioning's, never the editor's: saveUser() posts only FIELDS.
		// A new user takes them; an existing one keeps its own, so a context
		// is only ever changed in Extensions.
		if (!$stored && isset($input['context'])
			&& preg_match(Settings::CONTEXT_PATTERN, (string) $input['context'])) {
			$generated['context'] = ['value' => (string) $input['context'], 'flag' => $generated['context']['flag'] ?? 0];
		}

		if (!$stored && trim((string) ($input['emergency_cid'] ?? '')) !== '') {
			$generated['emergency_cid'] = ['value' => trim((string) $input['emergency_cid']), 'flag' => $generated['emergency_cid']['flag'] ?? 0];
		}

		// One phone per set of credentials: a second registration replaces the first
		if (!$stored && !empty($input['lobby'])) {
			$generated['max_contacts'] = ['value' => '1', 'flag' => $generated['max_contacts']['flag'] ?? 0];
			$generated['remove_existing'] = ['value' => 'yes', 'flag' => $generated['remove_existing']['flag'] ?? 0];
		}

		// oryk_connect lists a device by this, so while both are installed a
		// user saved here still reads as an Extension/User there
		$generated['kind'] = ['value' => 'pjsip', 'flag' => $generated['kind']['flag'] ?? 0];

		// Filled before the emergency caller id check: a missing value is not empty
		foreach ($generated as &$setting) {
			$setting['value'] = $setting['value'] ?? '';
			$setting['flag'] = $setting['flag'] ?? 0;
		}

		unset($setting);

		$dial = $defaults['dial'] ?? 'PJSIP';

		$generated['account']['value'] = "$uid";
		$generated['dial']['value'] = "$dial/$uid";
		$generated['mailbox']['value'] = "$uid@device";
		$generated['user']['value'] = "$uid";

		if (isset($generated['emergency_cid']['value']) && empty($generated['emergency_cid']['value'])) {
			$generated['emergency_cid']['value'] = $uid;
		}

		foreach (self::FORCED as $keyword => $value) {
			$generated[$keyword]['value'] = $value;
		}

		// Core expects both keys on every setting, and the ones added above
		// have only a value
		foreach ($generated as $keyword => $setting) {
			$generated[$keyword]['value'] = $setting['value'] ?? '';
			$generated[$keyword]['flag'] = $setting['flag'] ?? 0;
		}

		$this->extensions->ensure($uid, $description);
		$this->userman->ensure($uid, $description, 'pjsip', $email);

		// The three names and the email follow the form on every save
		$this->extensions->syncName($uid, $description);
		$this->userman->sync($uid, $description, $email);
		$this->voicemail->syncEmail($uid, $email);

		// A renumbering has already deleted the row on the old number
		if ($stored && !$renumbered) {
			\FreePBX::Core()->delDevice($stored['id'], true);
		}

		// The old row is already gone, so a refused add is a failed save, not
		// a quiet one
		if (!\FreePBX::Core()->addDevice($uid, 'pjsip', $generated, true)) {
			$this->logError('unable to add device ' . $uid);

			throw new \Exception(sprintf(_('The device for %s could not be written; see the FreePBX log.'), $uid));
		}

		\FreePBX::Core()->processEPM($uid, 'pjsip', true);

		$this->endpoints->apply($uid, $this->endpointExtras((string) ($generated['context']['value'] ?? '')));
		self::pending();

		// A user still live only through the bridge is rewritten there, or its
		// phone keeps the old password or context until Apply Config
		if ($stored && $this->bridge && $this->bridge->has((string) $stored['id'])) {
			$this->bridge->remove((string) $stored['id']);
			$this->bridge->add((string) $uid);
		}

		return (string) $uid;
	}

	/**
	 * What a user's endpoint section carries for its context, beyond the From
	 * Domain every user gets.
	 *
	 * A lobby endpoint may not transfer: a blind transfer is dialled in a
	 * context the transferring phone does not choose. Blank takes the setting
	 * off: the next save of a user moved out of the lobby lifts it.
	 *
	 * @param string $context The user's context.
	 *
	 * @return array<string, string> Settings for EndpointSettings::apply().
	 */
	private function endpointExtras($context)
	{
		return ['allow_transfer' => $context === $this->lobbyContext() ? 'no' : ''];
	}

	/**
	 * Raise FreePBX's Apply Config bar. Nothing in this module reloads.
	 *
	 * @return void
	 */
	private static function pending()
	{
		if (function_exists('needreload')) {
			needreload();
		}
	}

	/**
	 * Delete a user: every device on its extension, the extension, its owned
	 * User Manager account, its UCP assignments and its call history and
	 * recordings.
	 *
	 * An extension whose device has already gone is a user still, and the
	 * rest of it goes the same way.
	 *
	 * Provisioner clients pointing at any of those devices are deleted with
	 * it, their stored logs included -- see Clients::deleteForDevice().
	 *
	 * @param int|string $extension Extension number.
	 *
	 * @return bool True when a user was deleted.
	 */
	public function remove($extension)
	{
		$device = $this->device($extension);

		// A device with this id that is somebody else's: not a user to delete.
		if ($device && (string) ($device['user'] ?? '') !== (string) $device['id']) {
			return false;
		}

		if (!$device && !$this->extensions->exists($extension)) {
			return false;
		}

		$user = (string) $extension;

		if ($device) {
			\FreePBX::Core()->delDevice($user);
		}

		// Nothing in FreePBX knows the custom endpoint file
		$this->endpoints->forget($user);
		$this->clients->deleteForDevice($user);

		// Every other device on the extension goes with it, and its clients:
		// a user is its extension, and nothing of it is left to own them.
		foreach ($this->otherDevices($user) as $other) {
			try {
				\FreePBX::Core()->delDevice($other);
			} catch (\Exception $e) {
				$this->logError('unable to delete device ' . $other . ' of user ' . $user . ': ' . $e->getMessage());

				continue;
			}

			$this->endpoints->forget($other);
			$this->clients->deleteForDevice($other);
		}

		$this->userman->removeOwnedAccount($user);

		$deleted = true;

		try {
			\FreePBX::Core()->delUser($user);
		} catch (\Exception $e) {
			$deleted = false;

			$this->logError('unable to delete user ' . $user . ': ' . $e->getMessage());
		}

		// Only once the extension has gone: one left behind by a failed
		// delete is still in service and still making history
		if ($deleted) {
			$this->ucp->forget($user);
			$this->cdr->purge($user);
		}

		if ($this->bridge) {
			$this->bridge->remove($user);
		}

		self::pending();

		return true;
	}

	/**
	 * A device as Core holds it.
	 *
	 * @param int|string $id Device identifier.
	 *
	 * @return array<string, mixed>|null The device, or null when there is none.
	 */
	private function device($id)
	{
		$match = \FreePBX::Core()->getDevice($id);

		return isset($match['id']) ? $match : null;
	}

	/**
	 * The columns a user row is read with, by the list and the editor alike.
	 *
	 * `secure` is 1 when media encryption is on; `clients` is how many
	 * provisioner clients point at this user.
	 *
	 * @return string A SELECT list over FROM.
	 */
	private function columns()
	{
		return "u.extension AS extension,
			COALESCE(d.description, u.name) AS name,
			d.id IS NOT NULL AS device,
			(SELECT s.data FROM sip s WHERE s.id = u.extension AND s.keyword = 'email' LIMIT 1) AS email,
			(SELECT s.data FROM sip s WHERE s.id = u.extension AND s.keyword = 'from_domain' LIMIT 1) AS from_domain,
			COALESCE((SELECT s.data FROM sip s WHERE s.id = u.extension AND s.keyword = 'media_encryption' LIMIT 1), 'no') <> 'no' AS secure,
			(SELECT COUNT(*) FROM `{$this->clientsTable}` pc WHERE " . self::CLIENT_OF . ") AS clients,
			" . self::CONTEXT_EXPR . " AS context,
			" . $this->lastSeenExpr() . " AS last_seen,
			TIMESTAMPDIFF(SECOND, " . $this->lastSeenExpr() . ", NOW()) AS last_seen_age,
			" . $this->signedUpExpr() . " AS signed_up,
			TIMESTAMPDIFF(SECOND, " . $this->signedUpExpr() . ", NOW()) AS signed_up_age";
	}

	/**
	 * When a user's clients were last answered, the newest of them, over FROM.
	 *
	 * @return string SQL; the table name is the module's own.
	 */
	private function lastSeenExpr()
	{
		return "(SELECT MAX(pc.last_seen) FROM `{$this->clientsTable}` pc WHERE " . self::CLIENT_OF . ")";
	}

	/**
	 * When open provisioning signed a user up: its sign-up client's creation, over FROM.
	 *
	 * @return string SQL; the table name is the module's own.
	 */
	private function signedUpExpr()
	{
		return "(SELECT MIN(pc.created_at) FROM `{$this->clientsTable}` pc WHERE " . self::CLIENT_OF . " AND pc.signup_ip IS NOT NULL)";
	}

	/**
	 * expired() in SQL, for the Expired filter, over FROM with `:days`
	 * bound. **The two must agree**: the list shows what this matches, and
	 * deleteExpired() deletes only what expired() still says yes to.
	 *
	 * @return string SQL.
	 */
	private function expiredExpr()
	{
		$since = 'COALESCE(' . $this->lastSeenExpr() . ', ' . $this->signedUpExpr() . ')';

		return "($since IS NOT NULL AND $since < NOW() - INTERVAL :days DAY)";
	}

	/**
	 * Run $work holding LOCK, a MySQL named lock, so saves and sign-ups from
	 * different requests happen one at a time. Re-entrant within a request: only
	 * the outermost call takes and releases it, so a caller may wrap a store() or
	 * signUp() together with what it writes after, and hold the lock across all
	 * of it.
	 *
	 * @param callable $work What to run.
	 *
	 * @return mixed What $work returns.
	 *
	 * @throws \RuntimeException When the lock is not had within LOCK_WAIT seconds.
	 */
	public function withLock(callable $work)
	{
		if ($this->lockDepth === 0) {
			$stmt = $this->db->prepare('SELECT GET_LOCK(:name, :wait)');
			$stmt->execute([':name' => self::LOCK, ':wait' => self::LOCK_WAIT]);

			if ((int) $stmt->fetchColumn() !== 1) {
				throw new \RuntimeException(_('Another user is being saved; try again.'));
			}
		}

		$this->lockDepth++;

		try {
			return $work();
		} finally {
			$this->lockDepth--;

			if ($this->lockDepth === 0) {
				$this->db->prepare('SELECT RELEASE_LOCK(:name)')->execute([':name' => self::LOCK]);
			}
		}
	}
}
