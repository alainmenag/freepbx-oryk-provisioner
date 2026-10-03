<?php

// src/Users.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * Extension/User devices: saving, deleting and listing them.
 *
 * A user is not a table of this module's. It is a pjsip device that is its
 * own extension -- device id, extension and User Manager username are one
 * number -- and everything about it lives in Core, User Manager, Voicemail,
 * the CDR database and the custom endpoint file. See ARCHITECTURE.md, "Users".
 *
 * store() and remove() are sequences over the collaborators below, ported from
 * oryk_connect's DeviceManager with the other kinds taken out.
 */
class Users extends Service
{
	/**
	 * Which devices are users. Interpolated into SQL, so it is a literal and
	 * nothing in it may come from a request.
	 */
	const SHAPE = "d.tech = 'pjsip' AND d.id = d.user";

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
		Clients $clients
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
	}

	/**
	 * Rows for the Users table.
	 *
	 * Subqueries rather than a join and GROUP BY, so ONLY_FULL_GROUP_BY holds
	 * whether or not `devices.id` has the unique key install() tries to add.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	public function listUsers()
	{
		// Written into the statement, so only these; anything else sorts by number.
		$sortable = [
			'extension' => 'd.id + 0',
			'name' => 'd.description',
			'email' => 'email',
			'clients' => 'clients',
			'secure' => 'secure',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['extension'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$where = 'WHERE ' . self::SHAPE;

		if ($search !== '') {
			$where .= " AND (d.id LIKE :search
				OR d.description LIKE :search
				OR EXISTS (SELECT 1 FROM sip e WHERE e.id = d.id AND e.keyword = 'email' AND e.data LIKE :search))";
			$params[':search'] = '%' . $search . '%';
		}

		$countStmt = $this->db->prepare("SELECT COUNT(*) FROM devices d $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$stmt = $this->db->prepare(
			"SELECT " . $this->columns() . "
			FROM devices d
			$where
			ORDER BY $sort $order
			LIMIT :limit OFFSET :offset"
		);
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
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
				FROM devices d
				WHERE d.id = :id AND " . self::SHAPE
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
				"SELECT d.id AS extension, d.description AS name
				FROM devices d
				WHERE " . self::SHAPE . "
				ORDER BY d.id + 0, d.id"
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
	 * @param array<string, mixed> $request id (current number, '' for new),
	 *                                      extension, name, email,
	 *                                      from_domain, secret.
	 *
	 * @return array<string, mixed> Status and the number saved, or a message.
	 */
	public function saveUser($request)
	{
		try {
			$id = $this->store(is_array($request) ? $request : []);
		} catch (\Exception $e) {
			return ['status' => false, 'message' => $e->getMessage()];
		}

		return ['status' => true, 'id' => $id];
	}

	/**
	 * Delete from the editor or the list.
	 *
	 * @param mixed $extension Extension number.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function deleteUser($extension)
	{
		if (!$this->userRow($extension)) {
			return ['status' => false, 'message' => _('That user no longer exists.')];
		}

		$this->remove($extension);

		return ['status' => true];
	}

	/**
	 * The user a phone's credentials name, made from them when there is none.
	 *
	 * A User Manager login answers with its account's default extension. A
	 * username no account holds makes a user as the editor does with a blank
	 * Extension, and its account is given that username and password. The SIP
	 * secret stays Core's generated one.
	 *
	 * @param mixed $username Username offered.
	 * @param mixed $password Password offered.
	 *
	 * @return array{extension: string, created: bool}|null Null when the
	 *                                                       username is held
	 *                                                       under another password.
	 *
	 * @throws \InvalidArgumentException When the username or password is unusable.
	 * @throws \RuntimeException         When User Manager is not available, or
	 *                                   the account has no Extension/User.
	 * @throws \Exception                When the user could not be saved.
	 */
	public function findOrCreate($username, $password)
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

		if ($account) {
			$extension = (string) ($account['default_extension'] ?? '');

			if (!$this->userRow($extension)) {
				throw new \RuntimeException(_('That login has no Extension/User.'));
			}

			return ['extension' => $extension, 'created' => false];
		}

		if ($this->userman->usernameTaken($username)) {
			return null;
		}

		$extension = $this->store([
			'extension' => '',
			'name' => '',
			'email' => filter_var($username, FILTER_VALIDATE_EMAIL) !== false ? $username : '',
		]);

		// A user whose login could not be set is one nobody can provision as
		try {
			$this->userman->setLogin($extension, $username, $password);
		} catch (\Exception $e) {
			$this->remove($extension);

			throw $e;
		}

		return ['extension' => $extension, 'created' => true];
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
		$id = trim((string) ($input['id'] ?? ''));
		$requested = trim((string) ($input['extension'] ?? ''));
		$email = array_key_exists('email', $input) ? trim((string) $input['email']) : null;
		$stored = $id === '' ? null : $this->device($id);

		// Only a user is saved as one: a handset posted here would be deleted
		// and added back as an extension of its own
		if ($id !== '' && (!$stored || ($stored['tech'] ?? '') !== 'pjsip' || (string) ($stored['user'] ?? '') !== (string) $stored['id'])) {
			throw new \Exception(_('That user no longer exists.'));
		}

		if ($requested === '') {
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

		$this->endpoints->apply($uid);
		$this->reload();

		return (string) $uid;
	}

	/**
	 * Delete a user: the device, and -- once no other device points at the
	 * extension -- the extension, its owned User Manager account, its UCP
	 * assignments and its call history and recordings.
	 *
	 * Provisioner clients pointing at it are deleted with it, their stored
	 * logs included -- see Clients::deleteForDevice().
	 *
	 * @param int|string $extension Extension number.
	 *
	 * @return bool True when a user was deleted.
	 */
	public function remove($extension)
	{
		$device = $this->device($extension);

		if (!$device || (string) ($device['user'] ?? '') !== (string) $device['id']) {
			return false;
		}

		$user = (string) $device['id'];

		\FreePBX::Core()->delDevice($user);

		// Nothing in FreePBX knows the custom endpoint file
		$this->endpoints->forget($user);
		$this->clients->deleteForDevice($user);

		// A handset or softphone still on the extension keeps it alive
		if (!$this->extensions->hasDevices($user)) {
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
		}

		$this->reload();

		return true;
	}

	/**
	 * Apply the pending configuration, the way Apply Config does.
	 *
	 * The flag is raised first so it stays visible if the reload fails, and a
	 * failure is logged rather than thrown so a save is never lost behind it.
	 *
	 * @return bool True when the reload completed.
	 */
	public function reload()
	{
		needreload();

		try {
			$result = \FreePBX::Framework()->doReload();
		} catch (\Throwable $e) {
			$this->logError('reload failed: ' . $e->getMessage());

			return false;
		}

		if (empty($result['status'])) {
			$this->logError('reload failed: ' . ($result['message'] ?? 'unknown error'));

			return false;
		}

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
	 * @return string A SELECT list over `devices d`.
	 */
	private function columns()
	{
		return "d.id AS extension,
			d.description AS name,
			(SELECT s.data FROM sip s WHERE s.id = d.id AND s.keyword = 'email' LIMIT 1) AS email,
			(SELECT s.data FROM sip s WHERE s.id = d.id AND s.keyword = 'from_domain' LIMIT 1) AS from_domain,
			COALESCE((SELECT s.data FROM sip s WHERE s.id = d.id AND s.keyword = 'media_encryption' LIMIT 1), 'no') <> 'no' AS secure,
			(SELECT COUNT(*) FROM `{$this->clientsTable}` pc WHERE pc.device_id = d.id) AS clients";
	}
}
