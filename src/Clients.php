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
			'description' => 'd.description',
			'profile' => 'p.name',
			'secure' => self::SECURE_EXPR,
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
			"SELECT id, mac, device_id, profile_id, token
			FROM `{$this->clientsTable}`
			WHERE id = :id"
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
				pc.device_id,
				pc.profile_id,
				d.user AS extension,
				d.description,
				d.tech,
				p.name AS profile_name
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
					token = :token
				WHERE id = :id"
			);
			$stmt->execute([
				':mac' => $mac,
				':device_id' => $deviceId,
				':profile_id' => $profileId,
				':token' => $tokenHash,
				':id' => $id,
			]);

			return ['status' => true, 'id' => $id];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->clientsTable}` (mac, device_id, profile_id, token)
			VALUES (:mac, :device_id, :profile_id, :token)"
		);
		$stmt->execute([
			':mac' => $mac,
			':device_id' => $deviceId,
			':profile_id' => $profileId,
			':token' => $tokenHash,
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
