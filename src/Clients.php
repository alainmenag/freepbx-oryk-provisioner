<?php

// src/Clients.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * The clients table.
 *
 * A client is a MAC, the FreePBX device it stands for and the profile it
 * is served -- and the MAC is the only required part of that. clientByMac()
 * is the endpoint's way in, and the same row the editor reads.
 */
class Clients extends Service
{
	// Being switched on and off is the same thing here as it is on a
	// profile, so it is written once -- see src/Enabled.php.
	use Enabled;

	/**
	 * Whether a client has a token, as a column.
	 *
	 * The table wants to show whether a client is authenticated, and the one
	 * thing it must not be shown to do that is the token itself -- so the
	 * question is answered in SQL and only the answer travels. It is a
	 * constant because the same expression is both selected and sorted on,
	 * and the two drifting apart would sort the column by something other
	 * than what it displays.
	 */
	const SECURE_EXPR = "(pc.token IS NOT NULL AND pc.token <> '')";

	/**
	 * How long ago the endpoint last answered this client, in seconds.
	 *
	 * The timestamp is what is stored and the age is what is read -- nobody
	 * looking at a list of phones wants to subtract two datetimes in their
	 * head -- and the subtraction is done by the database rather than by the
	 * browser on purpose. A DATETIME column is written in the PBX's own
	 * clock and carries no zone with it, so a browser in another timezone
	 * that worked the age out itself would report a phone that checked in a
	 * minute ago as several hours early or late. Both sides of this
	 * subtraction are the database's clock, so there is no zone in it at all.
	 *
	 * NULL when the client has never been answered, which stays NULL: there
	 * is no age of something that has not happened.
	 */
	const SEEN_AGE_EXPR = 'TIMESTAMPDIFF(SECOND, pc.last_seen, NOW())';

	/**
	 * @var Freepbx
	 */
	private $pbx;

	/**
	 * @var Profiles
	 */
	private $profiles;

	/**
	 * @var Tokens
	 */
	private $tokens;

	/**
	 * @var LogRepo
	 */
	private $logs;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Freepbx $pbx, Profiles $profiles, Tokens $tokens, LogRepo $logs)
	{
		parent::__construct($freepbx);

		$this->pbx = $pbx;
		$this->profiles = $profiles;
		$this->tokens = $tokens;
		$this->logs = $logs;
	}

	/**
	 * Rows for the Clients table.
	 *
	 * Narrowed to one profile when profile_id is passed, which is how the
	 * profile editor's Clients tab is filled: the same rows read the same
	 * way, rather than a second statement that would drift from this one.
	 *
	 * The resource editor's Clients tab asks with resource_id alongside it
	 * and gets the same rows again, each carrying the filename that client
	 * asks that one resource for.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	public function listClients()
	{
		// The sort column and its direction are written into the statement
		// rather than bound, so neither can be taken from the request as it
		// stands. Only what the table offers as a sortable heading is
		// accepted, and anything else sorts by MAC rather than being refused.
		$sortable = [
			'mac' => 'pc.mac',
			'device_id' => 'pc.device_id',
			// The extension is the device's `user` column, which is what the
			// row carries under this name -- the heading's data-field, the key
			// in the row and the key here all have to be the one word, or the
			// column falls back to sorting by MAC without saying so.
			'extension' => 'd.user',
			'description' => 'd.description',
			'profile' => 'p.name',
			'secure' => self::SECURE_EXPR,
			'enabled' => 'pc.enabled',
			// Sorted by the timestamp rather than by the age beside it: they
			// are the same fact one subtraction apart, and the column MySQL
			// can reach an index through is the stored one. A client that has
			// never been seen sorts first ascending, which is where the fleet
			// is read from -- the phones nothing has heard from.
			'last_seen' => 'pc.last_seen',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['mac'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$clauses = [];

		// The same rows twice: every client on the module page, and one
		// profile's own on that profile's Clients tab, which asks with
		// profile_id. An id is honoured as given rather than falling back to
		// everything, so a profile nothing points at comes back empty instead
		// of coming back as the whole list.
		if (isset($_REQUEST['profile_id'])) {
			$clauses[] = 'pc.profile_id = :profile_id';
			$params[':profile_id'] = (int) $_REQUEST['profile_id'];
		}

		// Bracketed, now that it is no longer the only thing in there: an
		// unbracketed OR chain ANDed with the profile would match every
		// client whose profile name contains the search.
		//
		// The addresses are searched on as well as the names, because an
		// address is one of the two things somebody arrives at this list
		// already holding -- a MAC off a label, or an address off a router's
		// lease table -- and the question both times is which phone it is.
		if ($search !== '') {
			$clauses[] = "(pc.mac LIKE :search
				OR pc.device_id LIKE :search
				OR d.user LIKE :search
				OR d.description LIKE :search
				OR p.name LIKE :search
				OR pc.public_ip LIKE :search
				OR pc.private_ip LIKE :search)";
			$params[':search'] = '%' . $search . '%';
		}

		$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

		$from = "FROM `{$this->clientsTable}` pc
			LEFT JOIN devices d ON d.id = pc.device_id
			LEFT JOIN `{$this->profilesTable}` p ON p.id = pc.profile_id";

		$countStmt = $this->db->prepare("SELECT COUNT(*) $from $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$sql = "
			SELECT
				pc.id,
				pc.mac,
				pc.device_id,
				pc.profile_id,
				d.user AS extension,
				d.description AS description,
				p.name AS profile,
				p.enabled AS profile_enabled,
				pc.enabled,
				pc.last_seen,
				pc.public_ip,
				pc.private_ip,
				" . self::SEEN_AGE_EXPR . " AS last_seen_age,
				" . self::SECURE_EXPR . " AS secure
			$from
			$where
			ORDER BY $sort $order
			LIMIT :limit OFFSET :offset
		";

		$stmt = $this->db->prepare($sql);
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
		}
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmt->execute();

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		return [
			'total' => $total,
			'rows' => $rows,
		];
	}

	/**
	 * One client, for the editor.
	 *
	 * Read on the way into the page rather than fetched by it, the same way a
	 * profile is: the editor is a page of its own, so there is nothing left
	 * for it to wait on over AJAX. `getDevice` was that fetch, and went with
	 * the dialog it filled.
	 *
	 * @param mixed $id Client id.
	 *
	 * @return array<string, mixed>|null The client, or null when there is none.
	 */
	public function clientRow($id)
	{
		// The hash included: the editor shows it, so that a save which leaves
		// the field alone writes back what was already there, and so that
		// emptying the field is the one unambiguous way to take a token away.
		// It is a hash rather than the secret, which is what makes showing it
		// tolerable -- but it is on an admin page, and a weak token behind it
		// is a weak token in front of anyone who can open that page.
		$stmt = $this->db->prepare(
			"SELECT pc.id, pc.mac, pc.device_id, pc.profile_id, pc.token, pc.enabled, pc.last_seen,
				pc.public_ip, pc.private_ip,
				" . self::SEEN_AGE_EXPR . " AS last_seen_age
			FROM `{$this->clientsTable}` pc
			WHERE pc.id = :id"
		);
		$stmt->execute([':id' => (int) $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	/**
	 * The client a MAC names, with the FreePBX device and profile behind it.
	 *
	 * One statement rather than three lookups: the whole of what rendering
	 * needs is one row wide.
	 *
	 * @param string $mac Normalised MAC address.
	 *
	 * @return array<string, mixed>|null The row, or null when the MAC is unknown.
	 */
	public function clientByMac($mac)
	{
		$stmt = $this->db->prepare(
			"SELECT
				pc.mac,
				pc.token,
				pc.enabled,
				pc.device_id,
				pc.profile_id,
				pc.public_ip,
				pc.private_ip,
				d.user AS extension,
				d.description,
				d.tech,
				p.name AS profile_name,
				p.enabled AS profile_enabled
			FROM `{$this->clientsTable}` pc
			LEFT JOIN devices d ON d.id = pc.device_id
			LEFT JOIN `{$this->profilesTable}` p ON p.id = pc.profile_id
			WHERE pc.mac = :mac"
		);
		$stmt->execute([':mac' => $mac]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	/**
	 * Create or update a client.
	 *
	 * `token` is the field here that is not simply written: what arrives is
	 * either a token to hash or the hash of one, told apart by the colon. See
	 * the note above it, and hashToken(). The two addresses are the fields
	 * that can be refused -- see address(), which is what lets the Clients
	 * list put one of them in a link.
	 *
	 * The MAC is optional and is the one field here stored as NULL when it is
	 * empty rather than as '' -- see the note above the uniqueness check.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function saveClient($request)
	{
		$id = (int) ($request['id'] ?? 0);

		// Optional, so the empty box and the mistyped one have to be told apart
		// before normalize() flattens both to '': a client nobody has read a
		// label off yet is written without a MAC, and `00156` is a mistake worth
		// saying so about rather than quietly storing as no MAC at all.
		$macTyped = trim((string) ($request['mac'] ?? ''));
		$mac = Mac::normalize($macTyped);

		if ($macTyped !== '' && $mac === '') {
			return [
				'status' => false,
				'message' => _('A MAC address is 12 hexadecimal characters, with or without separators.'),
			];
		}

		// If this is a new client without a MAC, insert a placeholder row to get an ID
		if (!$id && !$mac) {
			$stmt = $this->db->prepare(
				"INSERT INTO `{$this->clientsTable}` (enabled)
				VALUES (:enabled)"
			);
			$stmt->execute([':enabled' => 0]);
			$id = (int) $this->db->lastInsertId();
		}

		if (!$id) {
			return ['status' => false, 'message' => _('Failed to generate a client ID.')];
		}

		// Generate a placeholder MAC for the new client if it doesn't have one.
		if (!$mac) {
			$mac = '02' . str_pad((string) $id, 10, '0', STR_PAD_LEFT);
		}

		if (!$mac) {
			return ['status' => false, 'message' => _('Failed to generate a MAC address.')];
		}

		$deviceId = trim((string) ($request['device_id'] ?? ''));
		$deviceId = $deviceId === '' ? null : $deviceId;

		$profileId = (int) ($request['profile_id'] ?? 0);
		$profileId = $profileId > 0 ? $profileId : null;

		if ($deviceId !== null && !$this->pbx->freepbxDeviceExists($deviceId)) {
			return ['status' => false, 'message' => _('That FreePBX device no longer exists.')];
		}

		if ($profileId !== null && !$this->profiles->profileExists($profileId)) {
			return ['status' => false, 'message' => _('That profile no longer exists.')];
		}

		// Refused rather than stored as typed, and said separately for each so
		// the message names the box to go back to. See address().
		$publicIp = self::address($request['public_ip'] ?? '');
		$privateIp = self::address($request['private_ip'] ?? '');

		if ($publicIp === false) {
			return ['status' => false, 'message' => _('The public address is not an IP address.')];
		}

		if ($privateIp === false) {
			return ['status' => false, 'message' => _('The private address is not an IP address.')];
		}

		// Only when there is a MAC to be taken. Two clients without one are not
		// a collision: neither can be reached by a MAC, so there is nothing for
		// them to be ambiguous between.
		if ($mac !== null) {
			$taken = $this->db->prepare(
				"SELECT id FROM `{$this->clientsTable}` WHERE mac = :mac AND id != :id"
			);
			$taken->execute([':mac' => $mac, ':id' => $id]);

			if ($taken->fetchColumn()) {
				return ['status' => false, 'message' => _('That MAC address is already associated.')];
			}
		}

		// The Token field round-trips: the editor is filled in with what is
		// stored, so what comes back is either the hash it was shown -- left
		// alone, and to be written back as it stands -- or something typed
		// over it. **A colon is what tells the two apart**: `username:password`
		// is a token being set, and a password_hash() carries no colon
		// anywhere in it, so a value with one has not been through this yet.
		//
		// The corollary, and it is a real one: a token typed without a colon
		// is stored as it was typed and verifies against nothing, because
		// verifyToken() hashes what the phone presents and compares. Whatever
		// eventually issues tokens should give out ones with a colon in them.
		//
		// An empty box is therefore the token being taken away, and the only
		// way it is -- there is nothing else an emptied box can mean once the
		// box is filled in from the row.
		//
		// Trimmed because a token is usually pasted, and a trailing newline is
		// not part of what the phone will send.
		$token = trim((string) ($request['token'] ?? ''));
		$tokenHash = null;

		// Enabled unless the page said otherwise -- see enabledSubmitted().
		$enabled = self::enabledSubmitted($request);

		if ($token !== '' && strpos($token, ':') !== false) {
			$tokenHash = $this->tokens->hashToken($token);

			// password_hash() has no failure a caller can act on -- there is
			// no weaker hash to fall back to -- so this is refused rather than
			// stored as something that would never verify.
			if ($tokenHash === null) {
				return ['status' => false, 'message' => _('That token could not be hashed.')];
			}
		} elseif ($token !== '') {
			$tokenHash = $token;
		}

		if ($id) {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->clientsTable}`
				SET mac = :mac, device_id = :device_id, profile_id = :profile_id,
					token = :token, enabled = :enabled,
					public_ip = :public_ip, private_ip = :private_ip
				WHERE id = :id"
			);
			$stmt->execute([
				':mac' => $mac,
				':device_id' => $deviceId,
				':profile_id' => $profileId,
				':token' => $tokenHash,
				':enabled' => $enabled,
				':public_ip' => $publicIp,
				':private_ip' => $privateIp,
				':id' => $id,
			]);

			return ['status' => true, 'id' => $id];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->clientsTable}` (mac, device_id, profile_id, token, enabled, public_ip, private_ip)
			VALUES (:mac, :device_id, :profile_id, :token, :enabled, :public_ip, :private_ip)"
		);
		$stmt->execute([
			':mac' => $mac,
			':device_id' => $deviceId,
			':profile_id' => $profileId,
			':token' => $tokenHash,
			':enabled' => $enabled,
			':public_ip' => $publicIp,
			':private_ip' => $privateIp,
		]);

		return ['status' => true, 'id' => (int) $this->db->lastInsertId()];
	}

	/**
	 * A submitted address, as it is to be stored.
	 *
	 * **Validated rather than trimmed and written**, and that is the invariant
	 * the Clients list depends on: the private address is put into an `href`
	 * on the row, and a value filter_var() has agreed is an address cannot be
	 * a `javascript:` anything. Nothing else on a client is written into a URL
	 * the admin's own browser follows, so nothing else is checked this way.
	 *
	 * An empty box is no address, which is what most clients have. IPv4 and
	 * IPv6 both pass; a hostname does not, deliberately -- the field says what
	 * it holds, and a name would have to be resolved by something to be worth
	 * more than the address it resolves to.
	 *
	 * @param mixed $value Submitted address.
	 *
	 * @return string|null|false The address, null when the box was empty,
	 *                           false when what was typed is not an address.
	 */
	private static function address($value)
	{
		$value = trim((string) $value);

		if ($value === '') {
			return null;
		}

		return filter_var($value, FILTER_VALIDATE_IP) ?: false;
	}

	/**
	 * The clients the navigator lists.
	 *
	 * A MAC and the description of the FreePBX device behind it, off the same
	 * join listClients() reads: the MAC is what a client *is* and is unreadable,
	 * the description is what its owner calls it and is not unique, so the
	 * option carries both and is found by either.
	 *
	 * Unpaged, like profileChoices() beside it. Both are read to fill a control
	 * that offers every row there is, which is what a navigator is for; the
	 * tables are where a list too long to look at is searched and paged.
	 *
	 * @return array<int, array<string, mixed>> Client rows: id, mac, description.
	 */
	public function clientChoices()
	{
		$stmt = $this->db->prepare(
			"SELECT pc.id, pc.mac, d.description
				FROM `{$this->clientsTable}` pc
				LEFT JOIN devices d ON d.id = pc.device_id
				ORDER BY pc.mac"
		);
		$stmt->execute();

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Switch a client on or off.
	 *
	 * The one thing about a client that is worth changing without opening it,
	 * which is why it is a button on the row and not only a field on the
	 * page: switching a phone off is what somebody does to a client they are
	 * not otherwise editing -- a desk that has been emptied, a MAC that is
	 * asking for a configuration it should not be given yet -- and making
	 * them open the editor to do it is making them open the editor to change
	 * nothing else.
	 *
	 * The write is the trait's, and shared with a profile. What is this
	 * class's is the row: a client that has gone is said in this table's own
	 * words, and it is said before anything is written rather than by an
	 * UPDATE that quietly matches nothing.
	 *
	 * @param array<string, mixed> $request id, and the state to put it in.
	 *
	 * @return array<string, mixed> Status, and the state the client is in.
	 */
	public function setClientEnabled($request)
	{
		$id = (int) ($request['id'] ?? 0);

		if (!$id || !$this->clientRow($id)) {
			return ['status' => false, 'message' => _('That client no longer exists.')];
		}

		return $this->setEnabled($this->clientsTable, $request);
	}

	/**
	 * Record that the endpoint has just answered this client.
	 *
	 * Called by Endpoint::answer() for every request that went out as a 200,
	 * which is the whole of what "seen" means here: the phone asked, and it
	 * was given what it asked for. A refusal is not a sighting -- a phone
	 * that has been switched off, or is asking for a file its profile does
	 * not serve, is reaching the PBX and getting nothing, and a last_seen
	 * that moved on those would say the client is being served when it is
	 * being refused. The refusals are on the Logs tab, where they are the
	 * question rather than the answer.
	 *
	 * **A PUT counts.** A phone uploading its boot log to a resource its
	 * profile takes was answered with a 200 like any other request, and it is
	 * as much proof the phone is alive as fetching a file is.
	 *
	 * NOW() rather than a timestamp from PHP: the column is compared against
	 * NOW() when the age is read, and a row written from a clock that is not
	 * the one it will be measured against is a row that can be in the future.
	 *
	 * One column, and nothing else on the row is read -- the same posture
	 * setEnabled() takes, and for the same reason: this is written by a
	 * request being answered, not by anybody editing a client.
	 *
	 * `updated_at = updated_at` is the one odd-looking thing in the
	 * statement and it is load-bearing: that column is
	 * ON UPDATE CURRENT_TIMESTAMP, so without assigning it to itself every
	 * phone that booted would read as a client somebody had just edited.
	 * When a row was last changed and when its phone was last heard from are
	 * two different facts and this writes only the second.
	 *
	 * A Render link clicked in the admin goes through the same endpoint and
	 * counts, because it is the same request: the module has no way to tell a
	 * browser asking on a phone's behalf from the phone, and inventing one
	 * would mean trusting a User-Agent or a flag on the URL. It is the
	 * posture the provisioning log already takes, and the row it leaves there
	 * carries the browser's User-Agent for anyone who needs to tell the two
	 * apart.
	 *
	 * A MAC that is not one, or one no client answers to, writes nothing.
	 * The second is the ordinary case rather than an error: a phone fetching
	 * firmware by name reaches the endpoint with no client behind it at all,
	 * and there is no row for it to have been seen on.
	 *
	 * Nothing in here may fail a request, which is the rule the provisioning
	 * log is written under and for the same reason -- the phone has already
	 * been answered by the time this runs, and a module upgraded without its
	 * install step has a clients table with no such column.
	 *
	 * @param mixed $mac MAC address, written however it was written.
	 *
	 * @return void
	 */
	public function touchClient($mac)
	{
		$mac = Mac::normalize($mac);

		if ($mac === '') {
			return;
		}

		try {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->clientsTable}`
				SET last_seen = NOW(), updated_at = updated_at
				WHERE mac = :mac"
			);
			$stmt->execute([':mac' => $mac]);
		} catch (\Exception $e) {
			$this->log('oryk_provisioner: could not record when a client was last seen', $e->getMessage(), 'WARNING');
		}
	}

	/**
	 * Remove a client.
	 *
	 * Nothing points at a client the way a client points at a profile, so
	 * there is nothing to refuse this for.
	 *
	 * What it has sent us goes with it, and goes first -- while there is
	 * still a row to say the MAC. The log directory is named after the MAC
	 * and nothing else, so a row deleted without it would leave a directory
	 * on the PBX that nothing on the system can account for, holding the boot
	 * logs of a phone the module has forgotten. That is the order and the
	 * reason deleteResource() has for an uploaded file.
	 *
	 * Its rows in the provisioning log deliberately stay. They are keyed on
	 * the MAC rather than on this id precisely so that they outlive the
	 * client and predate it -- a phone's requests from before anybody wrote
	 * its client are the run of 404s that says what it has been asking for,
	 * and deleting the client is often the prelude to writing it again.
	 * Clearing them is its own button on the Logs tab.
	 *
	 * @param mixed $id Client id.
	 *
	 * @return array<string, mixed> Status of the removal.
	 */
	public function deleteClient($id)
	{
		$client = $this->clientRow($id);

		if ($client) {
			$this->logs->removeClientLogs($client['mac']);
		}

		$stmt = $this->db->prepare("DELETE FROM `{$this->clientsTable}` WHERE id = :id");
		$stmt->execute([':id' => (int) $id]);

		return ['status' => true];
	}
}
