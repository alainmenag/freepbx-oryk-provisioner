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
	 * The table wants to show whether a client is authenticated, and the one thing
	 * it must not show to do that is the token. A constant because the same
	 * expression is both selected and sorted on.
	 */
	const SECURE_EXPR = "(pc.token IS NOT NULL AND pc.token <> '')";

	/**
	 * How long ago the endpoint last answered this client, in seconds.
	 *
	 * The subtraction is the database's rather than the browser's: a DATETIME is
	 * written in the PBX's own clock and carries no zone, so a browser elsewhere
	 * would report a phone that checked in a minute ago as hours out. NULL when
	 * the client has never been answered, and it stays NULL.
	 */
	const SEEN_AGE_EXPR = 'TIMESTAMPDIFF(SECOND, pc.last_seen, NOW())';

	/** @var Freepbx */
	private $pbx;

	/** @var Profiles */
	private $profiles;

	/** @var Tokens */
	private $tokens;

	/** @var LogRepo */
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
	 * Narrowed to one profile when profile_id is passed, which is how the profile
	 * editor's Clients tab is filled -- the same rows read the same way. The
	 * resource editor's Clients tab asks with resource_id alongside it and gets
	 * them again, each carrying the filename that client asks that resource for.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	public function listClients()
	{
		// Sort column and direction are written into the statement, not bound.
		// Only a heading the table offers is accepted; anything else sorts by MAC.
		$sortable = [
			'mac' => 'pc.mac',
			'device_id' => 'pc.device_id',
			'description' => 'd.description',
			'profile' => 'p.name',
			'secure' => self::SECURE_EXPR,
			'enabled' => 'pc.enabled',
			// Sorted by the timestamp, not the age beside it: same fact one
			// subtraction apart, and the stored column is the one an index can reach.
			'last_seen' => 'pc.last_seen',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['mac'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$clauses = [];

		// An id is honoured as given rather than falling back to everything, so a
		// profile nothing points at comes back empty, not as the whole list.
		if (isset($_REQUEST['profile_id'])) {
			$clauses[] = 'pc.profile_id = :profile_id';
			$params[':profile_id'] = (int) $_REQUEST['profile_id'];
		}

		// Bracketed, now that it is no longer the only thing in there: an
		// unbracketed OR chain ANDed with the profile would match every
		// client whose profile name contains the search.
		if ($search !== '') {
			$clauses[] = "(pc.mac LIKE :search
				OR pc.device_id LIKE :search
				OR d.user LIKE :search
				OR d.description LIKE :search
				OR p.name LIKE :search)";
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
	 * profile is: the editor is a page of its own.
	 *
	 * @param mixed $id Client id.
	 *
	 * @return array<string, mixed>|null The client, or null when there is none.
	 */
	public function clientRow($id)
	{
		// The hash included, so a save leaving the field alone writes back what
		// was there and emptying it is the one way to take a token away. Showing
		// a hash is tolerable; it is still an admin page.
		$stmt = $this->db->prepare(
			"SELECT pc.id, pc.mac, pc.device_id, pc.profile_id, pc.token, pc.enabled, pc.last_seen,
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
	 * `token` is the one field here that is not simply written: what arrives
	 * is either a token to hash or the hash of one, told apart by the colon.
	 * See the note above it, and hashToken().
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function saveClient($request)
	{
		$id = (int) ($request['id'] ?? 0);
		$mac = Mac::normalize($request['mac'] ?? '');

		if ($mac === '') {
			return [
				'status' => false,
				'message' => _('A MAC address is 12 hexadecimal characters, with or without separators.'),
			];
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

		$taken = $this->db->prepare(
			"SELECT id FROM `{$this->clientsTable}` WHERE mac = :mac AND id != :id"
		);
		$taken->execute([':mac' => $mac, ':id' => $id]);

		if ($taken->fetchColumn()) {
			return ['status' => false, 'message' => _('That MAC address is already associated.')];
		}

		// The Token field round-trips: what comes back is either the hash it was
		// shown or something typed over it. **A colon tells the two apart** --
		// `username:password` is a token being set, and a password_hash() carries
		// no colon. The corollary is real: a token typed without a colon is stored
		// as typed and verifies against nothing. An empty box is the token being
		// taken away, and the only way it is.
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
					token = :token, enabled = :enabled
				WHERE id = :id"
			);
			$stmt->execute([
				':mac' => $mac,
				':device_id' => $deviceId,
				':profile_id' => $profileId,
				':token' => $tokenHash,
				':enabled' => $enabled,
				':id' => $id,
			]);

			return ['status' => true, 'id' => $id];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->clientsTable}` (mac, device_id, profile_id, token, enabled)
			VALUES (:mac, :device_id, :profile_id, :token, :enabled)"
		);
		$stmt->execute([
			':mac' => $mac,
			':device_id' => $deviceId,
			':profile_id' => $profileId,
			':token' => $tokenHash,
			':enabled' => $enabled,
		]);

		return ['status' => true, 'id' => (int) $this->db->lastInsertId()];
	}

	/**
	 * Remove a client.
	 *
	 * @param mixed $id Client id.
	 *
	 * @return array<string, mixed> Status of the removal.
	 */
	/**
	 * The clients the navigator lists.
	 *
	 * A MAC and the description of the FreePBX device behind it: the MAC is what a
	 * client *is* and is unreadable, the description is what its owner calls it and
	 * is not unique, so the option carries both and is found by either. Unpaged,
	 * like profileChoices() -- a navigator offers every row there is.
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
	 * The one thing about a client worth changing without opening it, which is why
	 * it is a button on the row. The write is the trait's, shared with a profile;
	 * what is this class's is saying, in this table's own words and before anything
	 * is written, that the client has gone.
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
	 * Called by Endpoint::answer() for every request that went out as a 200, which
	 * is the whole of what "seen" means here. A refusal is not a sighting -- a
	 * last_seen that moved on those would say the client is being served when it
	 * is being refused. **A PUT counts**: the phone was answered with a 200 and is
	 * as much alive for it.
	 *
	 * NOW() rather than a timestamp from PHP, because the column is compared
	 * against NOW() when the age is read.
	 *
	 * `updated_at = updated_at` is the one odd-looking thing here and it is
	 * load-bearing: that column is ON UPDATE CURRENT_TIMESTAMP, so without it every
	 * phone that booted would read as a client somebody had just edited.
	 *
	 * A Render link clicked in the admin counts, because it is the same request.
	 * A MAC that is not one, or one no client answers to, writes nothing. Nothing
	 * in here may fail a request.
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
	 * What it has sent us goes with it, and goes first -- while there is still a
	 * row to say the MAC. The log directory is named after the MAC and nothing
	 * else, so a row deleted without it would leave a directory nothing can
	 * account for.
	 *
	 * Its rows in the provisioning log deliberately stay: they are keyed on the MAC
	 * precisely so they outlive the client and predate it, and deleting a client is
	 * often the prelude to writing it again.
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
