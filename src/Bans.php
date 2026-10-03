<?php

// src/Bans.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * Who the provisioning endpoint refuses, and who it answers in spite of a ban.
 *
 * One row is up to five subjects -- client, user, MAC, profile, address -- and
 * one row only for any one set of them; a state:
 * `banned` until `expires_at`, or `deny` / `allow` for good. An expired ban
 * stays in the table, out of force, until it is deleted. A row matches a
 * request when every subject it sets matches; a subject left empty matches
 * anything. The endpoint asks check() before it answers anything. See
 * ARCHITECTURE.md, "Bans".
 */
class Bans extends Service
{
	/** @var array<int, true> Rows already counted a hit in this PHP request. */
	private $counted = [];

	/**
	 * Subject => column, most specific first: the order decide() ranks by.
	 *
	 * The columns are interpolated into SQL, so they are only ever read from here.
	 */
	const SUBJECTS = ['client' => 'client_id', 'user' => 'extension', 'mac' => 'mac', 'profile' => 'profile_id', 'ip' => 'ip'];

	/**
	 * Column => what it stores for "any": 0 or '' rather than NULL, so the
	 * unique key over the five counts two rows naming the same subjects as one.
	 * Written into SQL as literals, so they are only ever read from here.
	 */
	const ANY = ['client_id' => 0, 'extension' => '', 'mac' => '', 'profile_id' => 0, 'ip' => ''];

	/**
	 * What a ban does. Only `banned` expires.
	 */
	const STATES = ['banned', 'deny', 'allow'];

	/** What a ban written from the Bans tab was created by. */
	const SOURCE_MANUAL = 'manual';

	/** The longest a temporary ban can be given, in minutes: ten years. */
	const MAX_MINUTES = 5256000;

	/** Seconds until a temporary ban lifts, on the database's clock. */
	const EXPIRES_IN_EXPR = 'TIMESTAMPDIFF(SECOND, NOW(), b.expires_at)';

	/** Seconds since a ban was written, on the same clock. */
	const CREATED_AGE_EXPR = 'TIMESTAMPDIFF(SECOND, b.created_at, NOW())';

	/** Seconds since a ban last decided a request. */
	const LAST_HIT_AGE_EXPR = 'TIMESTAMPDIFF(SECOND, b.last_hit_at, NOW())';

	/**
	 * A row that is in force: anything but a temporary ban that has run out. The
	 * one test of expiry; nothing deletes a row because it has expired.
	 */
	const ACTIVE_EXPR = "(b.state <> 'banned' OR b.expires_at > NOW())";

	/**
	 * One page of the Bans tab, the way bootstrap-table asks for it.
	 *
	 * Expired bans are listed too, with `active` 0.
	 *
	 * @return array<string, mixed> total, rows.
	 */
	public function listBans()
	{
		// Interpolated, so looked up rather than taken from the request. An empty
		// subject sorts first ascending: it is the wider rule.
		$sortable = [
			'ip' => 'b.ip',
			'mac' => 'b.mac',
			'user' => 'b.extension + 0',
			'client' => 'b.client_id',
			'profile' => 'p.name',
			'state' => 'b.state',
			'created_at' => 'b.created_at',
			'hits' => 'b.hits',
			// A permanent row has no expiry and sorts after every temporary one.
			'expires_at' => 'b.expires_at IS NULL, b.expires_at',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['created_at'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'asc' ? 'ASC' : 'DESC';

		// The direction has to reach every column of a composite sort.
		$orderBy = implode(', ', array_map(function ($column) use ($order) {
			return "$column $order";
		}, explode(', ', $sort)));

		$search = (string) ($_REQUEST['search'] ?? '');
		$params = [];
		$where = '';

		if ($search !== '') {
			$where = "WHERE (b.ip LIKE :search
				OR b.mac LIKE :search
				OR b.extension LIKE :search
				OR b.state LIKE :search
				OR b.note LIKE :search
				OR c.mac LIKE :search
				OR cd.description LIKE :search
				OR ud.description LIKE :search
				OR p.name LIKE :search)";
			$params[':search'] = '%' . $search . '%';
		}

		try {
			$count = $this->db->prepare("SELECT COUNT(*) {$this->from()} $where");
			$count->execute($params);
			$total = (int) $count->fetchColumn();

			$stmt = $this->db->prepare(
				"{$this->select()} {$this->from()} $where
				ORDER BY $orderBy, b.id DESC
				LIMIT :limit OFFSET :offset"
			);

			foreach ($params as $key => $value) {
				$stmt->bindValue($key, $value);
			}

			$stmt->bindValue(':limit', (int) ($_REQUEST['limit'] ?? 10), PDO::PARAM_INT);
			$stmt->bindValue(':offset', (int) ($_REQUEST['offset'] ?? 0), PDO::PARAM_INT);
			$stmt->execute();
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			$this->logError('could not list bans: ' . $e->getMessage());

			return ['total' => 0, 'rows' => [], 'message' => _('The bans table could not be read. Has the module been upgraded?')];
		}

		return ['total' => $total, 'rows' => $this->described($rows)];
	}

	/**
	 * One ban as the list draws it, or null when there is no such row (any more).
	 *
	 * @param mixed $id Ban id.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	public function banRow($id)
	{
		if (!ctype_digit((string) $id)) {
			return null;
		}

		try {
			$stmt = $this->db->prepare("{$this->select()} {$this->from()} WHERE b.id = :id");
			$stmt->execute([':id' => (int) $id]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return null;
		}

		return $row ? $this->described([$row])[0] : null;
	}

	/**
	 * Whether a request is refused, and by which row.
	 *
	 * Every row in force that matches is read in one query -- each subject a row
	 * sets is one of the request's, each it leaves empty is anything -- and
	 * decide() picks the one that counts. That row -- allow or not -- is counted
	 * a hit, once per PHP request however often this is asked (open provisioning
	 * asks twice). Fails open: a table that cannot be read refuses nothing, so new
	 * files on a PBX not yet upgraded keep provisioning.
	 *
	 * @param array<string, mixed> $subjects What the request is: ip, mac, client
	 *                                       and profile (ids), user (an
	 *                                       extension, or a list of them). One the request does
	 *                                       not have is absent or empty, and then
	 *                                       only rows leaving it empty match.
	 *
	 * @return array<string, mixed>|null The deciding banned or deny row; null
	 *                                   when nothing refuses, or an allow decides.
	 */
	public function check(array $subjects)
	{
		$subjects = self::subjects($subjects);
		$clauses = [];
		$params = [];

		foreach (self::SUBJECTS as $subject => $column) {
			$any = self::anyLiteral($column);

			if (empty($subjects[$subject])) {
				$clauses[] = "b.$column = $any";

				continue;
			}

			$names = [];

			foreach ($subjects[$subject] as $i => $value) {
				$names[] = ":{$subject}_$i";
				$params[":{$subject}_$i"] = $value;
			}

			$clauses[] = "(b.$column = $any OR b.$column IN (" . implode(', ', $names) . '))';
		}

		try {
			$stmt = $this->db->prepare(
				"SELECT b.id, b.client_id, b.extension, b.mac, b.profile_id, b.ip, b.state
				FROM `{$this->bansTable}` b
				WHERE " . implode(' AND ', $clauses) . ' AND ' . self::ACTIVE_EXPR
			);
			$stmt->execute($params);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return null;
		}

		$row = self::decide($rows);

		if ($row) {
			$this->hit((int) $row['id']);
		}

		return ($row && $row['state'] !== 'allow') ? $row : null;
	}

	/**
	 * The row that counts among the matching rows in force.
	 *
	 * Ranked by the most specific subject each sets (client, user, MAC, profile,
	 * address), then by how many it sets -- so "allow user 1001 from this address" beats
	 * "deny user 1001". A tie goes to the refusal.
	 *
	 * @param array<int, array<string, mixed>> $rows Matching rows: the subject
	 *                                               columns, state.
	 *
	 * @return array<string, mixed>|null The deciding row, or null when none matched.
	 */
	public static function decide(array $rows)
	{
		$best = null;
		$bestRank = null;

		foreach ($rows as $row) {
			$set = self::setSubjects($row);

			if (!$set) {
				continue;
			}

			// Lower is stronger: the most specific subject's place, then more
			// subjects, then a refusal before an allow.
			$rank = [
				array_search($set[0], array_keys(self::SUBJECTS), true),
				-count($set),
				($row['state'] ?? '') === 'allow' ? 1 : 0,
			];

			if ($bestRank === null || $rank < $bestRank) {
				$best = $row;
				$bestRank = $rank;
			}
		}

		return $best;
	}

	/**
	 * What a refused request is told, and what the provisioning log records.
	 *
	 * @param array<string, mixed> $row The deciding row from check().
	 *
	 * @return string The refusal.
	 */
	public static function refusal(array $row)
	{
		return sprintf(
			$row['state'] === 'deny' ? _('Denied by ban #%d (%s).') : _('Banned by ban #%d (%s).'),
			(int) $row['id'],
			self::summary($row)
		);
	}

	/**
	 * A row's subjects in words: "user 1001, address 203.0.113.7".
	 *
	 * @param array<string, mixed> $row The subject columns.
	 *
	 * @return string Summary.
	 */
	public static function summary(array $row)
	{
		$words = [
			'client' => _('client #%s'),
			'user' => _('user %s'),
			'mac' => _('MAC %s'),
			'profile' => _('profile #%s'),
			'ip' => _('address %s'),
		];
		$parts = [];

		foreach (self::setSubjects($row) as $subject) {
			$parts[] = sprintf($words[$subject], (string) $row[self::SUBJECTS[$subject]]);
		}

		return implode(', ', $parts);
	}

	/**
	 * Write a ban, new or existing.
	 *
	 * At least one subject; the rest left empty match anything. A temporary ban
	 * runs `minutes` from this save, so saving one again restarts its clock.
	 *
	 * There is one row per set of subjects, held by the unique key. A new ban
	 * naming exactly what a row already does reopens that row -- its state,
	 * minutes and, when one is given, note are written over it -- and answers
	 * with its id. An existing ban edited to name what another row does is
	 * refused, since that would be two rows becoming one.
	 *
	 * `source` and `jail` say what created the row: blank source is
	 * SOURCE_MANUAL, blank jail none. A new row and an edit write them; a reopen
	 * keeps the row's own, so re-adding a ban does not rewrite who made it.
	 *
	 * @param array<string, mixed> $request id, ip, mac, user, client, profile,
	 *                                      state, minutes, note, source, jail.
	 *
	 * @return array<string, mixed> status, id and reopened, or message.
	 */
	public function saveBan($request)
	{
		$source = strtolower(trim((string) ($request['source'] ?? ''))) ?: self::SOURCE_MANUAL;
		$jail = trim((string) ($request['jail'] ?? ''));
		$jail = $jail === '' ? null : $jail;

		if (!preg_match('/^[a-z0-9_-]{1,32}$/', $source)) {
			return ['status' => false, 'message' => _('A source is up to 32 letters, digits, dashes and underscores.')];
		}

		if ($jail !== null && !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $jail)) {
			return ['status' => false, 'message' => _('A jail is up to 64 letters, digits, dots, dashes and underscores.')];
		}

		$id = (int) ($request['id'] ?? 0);
		$state = (string) ($request['state'] ?? '');
		$note = trim((string) ($request['note'] ?? ''));

		$messages = [
			'ip' => _('Type one IPv4 or IPv6 address. Ranges are not accepted.'),
			'mac' => _('Type a MAC address: twelve hexadecimal digits, with or without separators.'),
			'user' => _('Choose a user.'),
			'client' => _('Choose a client.'),
			'profile' => _('Choose a profile.'),
		];
		$values = [];

		foreach (self::SUBJECTS as $subject => $column) {
			$typed = trim((string) ($request[$subject] ?? ''));
			$values[$column] = $typed === '' ? null : self::value($subject, $typed);

			if ($typed !== '' && $values[$column] === null) {
				return ['status' => false, 'message' => $messages[$subject]];
			}
		}

		if (!array_filter($values, 'is_string')) {
			return ['status' => false, 'message' => _('Fill in at least one of IP Address, MAC Address, User, Client or Profile.')];
		}

		if (!in_array($state, self::STATES, true)) {
			return ['status' => false, 'message' => _('Choose Banned, Deny or Allow.')];
		}

		$minutes = null;

		if ($state === 'banned') {
			$minutes = trim((string) ($request['minutes'] ?? ''));

			if (!ctype_digit($minutes) || (int) $minutes < 1 || (int) $minutes > self::MAX_MINUTES) {
				return ['status' => false, 'message' => sprintf(_('A temporary ban lasts from 1 to %d minutes.'), self::MAX_MINUTES)];
			}

			$minutes = (int) $minutes;
		}

		if (strlen($note) > 255) {
			return ['status' => false, 'message' => _('The note is limited to 255 characters.')];
		}

		$columns = [];

		foreach (self::ANY as $column => $any) {
			$columns[":$column"] = $values[$column] ?? $any;
		}

		try {
			if ($values['client_id'] !== null && !$this->rowCount($this->clientsTable, 'id', (int) $values['client_id'])) {
				return ['status' => false, 'message' => _('That client no longer exists.')];
			}

			if ($values['profile_id'] !== null && !$this->rowCount($this->profilesTable, 'id', (int) $values['profile_id'])) {
				return ['status' => false, 'message' => _('That profile no longer exists.')];
			}

			if ($id) {
				if (!$this->rowCount($this->bansTable, 'id', $id)) {
					return ['status' => false, 'message' => _('That ban has been deleted.')];
				}

				$taken = $this->scopeHolder($columns, $id);

				if ($taken) {
					return ['status' => false, 'message' => sprintf(_('Ban #%d already names exactly that. Open it instead, or delete one of the two.'), $taken)];
				}
			}

			$reopened = !$id && $this->scopeHolder($columns, 0);

			// NOW() rather than PHP's clock: expiry is compared with NOW() too.
			$expires = $minutes === null ? 'NULL' : 'NOW() + INTERVAL :minutes MINUTE';
			$params = $columns + [
				':state' => $state,
				':note' => $note === '' ? null : $note,
				':source' => $source,
				':jail' => $jail,
			];

			if ($minutes !== null) {
				$params[':minutes'] = $minutes;
			}

			if ($id) {
				$stmt = $this->db->prepare(
					"UPDATE `{$this->bansTable}`
					SET client_id = :client_id, extension = :extension, mac = :mac, profile_id = :profile_id, ip = :ip,
						state = :state, note = :note, expires_at = $expires, source = :source, jail = :jail
					WHERE id = :id"
				);
				$stmt->execute($params + [':id' => $id]);
			} else {
				// The key decides, not the look-up above: two saves at once still make
				// one row. LAST_INSERT_ID(id) makes lastInsertId() the row reopened.
				$stmt = $this->db->prepare(
					"INSERT INTO `{$this->bansTable}` (client_id, extension, mac, profile_id, ip, state, note, expires_at, source, jail)
					VALUES (:client_id, :extension, :mac, :profile_id, :ip, :state, :note, $expires, :source, :jail)
					ON DUPLICATE KEY UPDATE
						id = LAST_INSERT_ID(id),
						state = VALUES(state),
						expires_at = VALUES(expires_at),
						note = COALESCE(VALUES(note), note)"
				);
				$stmt->execute($params);
				$id = (int) $this->db->lastInsertId();
			}
		} catch (\PDOException $e) {
			// 23000: an edit that raced another save onto the same subjects.
			if ($e->getCode() === '23000') {
				return ['status' => false, 'message' => _('Another ban already names exactly that.')];
			}

			$this->logError('could not save a ban: ' . $e->getMessage());

			return ['status' => false, 'message' => _('The ban could not be saved. Has the module been upgraded?')];
		} catch (\Exception $e) {
			$this->logError('could not save a ban: ' . $e->getMessage());

			return ['status' => false, 'message' => _('The ban could not be saved. Has the module been upgraded?')];
		}

		$this->logInfo("ban #$id " . ($reopened ? 'reopened' : 'saved') . ': ' . self::summary($values) . " is $state" . ($minutes === null ? '' : " for $minutes minutes"));

		return ['status' => true, 'id' => $id, 'reopened' => $reopened];
	}

	/**
	 * Count one request decided by a row.
	 *
	 * Assigns `updated_at = updated_at`, as Clients::touchClient() does, so a hit
	 * does not read as an edit. Never throws: a count that fails is a count lost.
	 *
	 * @param int $id Ban id.
	 *
	 * @return void
	 */
	private function hit($id)
	{
		if (isset($this->counted[$id])) {
			return;
		}

		$this->counted[$id] = true;

		try {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->bansTable}`
				SET hits = hits + 1, last_hit_at = NOW(), updated_at = updated_at
				WHERE id = :id"
			);
			$stmt->execute([':id' => $id]);
		} catch (\Exception $e) {
			// Counting is the one thing here allowed to go missing.
		}
	}

	/**
	 * The id of the row naming exactly these subjects, other than one.
	 *
	 * @param array<string, mixed> $columns Bound values, `:column` => stored or ANY.
	 * @param int                  $except  Row to leave out, or 0.
	 *
	 * @return int Its id, or 0 when no other row does.
	 */
	private function scopeHolder(array $columns, $except)
	{
		$stmt = $this->db->prepare(
			"SELECT id FROM `{$this->bansTable}`
			WHERE client_id = :client_id AND extension = :extension AND mac = :mac
				AND profile_id = :profile_id AND ip = :ip AND id <> :id"
		);
		$stmt->execute($columns + [':id' => (int) $except]);

		return (int) $stmt->fetchColumn();
	}

	/**
	 * Delete one ban. One that has already gone is a success.
	 *
	 * @param mixed $id Ban id.
	 *
	 * @return array<string, mixed> status.
	 */
	public function deleteBan($id)
	{
		try {
			$stmt = $this->db->prepare("DELETE FROM `{$this->bansTable}` WHERE id = :id");
			$stmt->execute([':id' => (int) $id]);
		} catch (\Exception $e) {
			return ['status' => false, 'message' => _('The ban could not be deleted.')];
		}

		$this->logInfo('ban #' . (int) $id . ' deleted');

		return ['status' => true];
	}

	/**
	 * Every client that has a public address written on it, by that address.
	 *
	 * What the IP column's hint is filled from, and what the editor warns with.
	 *
	 * @return array<string, array<string, mixed>> Keyed by canonical address:
	 *                                             id, label, count.
	 */
	public function clientAddresses()
	{
		try {
			$stmt = $this->db->prepare(
				"SELECT pc.id, pc.mac, pc.public_ip, d.description
					FROM `{$this->clientsTable}` pc
					LEFT JOIN devices d ON d.id = pc.device_id
					WHERE pc.public_ip IS NOT NULL AND pc.public_ip <> ''
					ORDER BY pc.mac"
			);
			$stmt->execute();
			$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return [];
		}

		$byAddress = [];

		foreach ($clients as $client) {
			$ip = self::canonical($client['public_ip']);

			if ($ip === null) {
				continue;
			}

			if (isset($byAddress[$ip])) {
				$byAddress[$ip]['count']++;

				continue;
			}

			$byAddress[$ip] = [
				'id' => (int) $client['id'],
				'label' => self::clientLabel($client),
				'count' => 1,
			];
		}

		return $byAddress;
	}

	/**
	 * A value in the one spelling it is stored and matched by, or null when it
	 * is not one of that subject.
	 *
	 * @param string $subject A key of SUBJECTS.
	 * @param mixed  $value   As typed or posted.
	 *
	 * @return string|null Stored value.
	 */
	public static function value($subject, $value)
	{
		$value = trim((string) $value);

		switch ($subject) {
			case 'ip':
				return self::canonical($value);

			case 'mac':
				$mac = Mac::normalize($value);

				return preg_match('/^[0-9a-f]{12}$/', $mac) ? $mac : null;

			// Users::userRow() takes digits and nothing else.
			case 'user':
				return preg_match('/^[0-9]{1,20}$/', $value) ? $value : null;

			case 'client':
			case 'profile':
				return preg_match('/^[1-9][0-9]{0,9}$/', $value) ? $value : null;
		}

		return null;
	}

	/**
	 * One address in the spelling this module stores and compares by.
	 *
	 * @param mixed $ip An address, however it is written.
	 *
	 * @return string|null Canonical address, or null when it is not one address.
	 */
	public static function canonical($ip)
	{
		$ip = trim((string) $ip);

		if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
			return null;
		}

		$packed = @inet_pton($ip);

		return $packed === false ? null : inet_ntop($packed);
	}

	/**
	 * Subjects of a request, each a list of stored values, the rest dropped.
	 *
	 * @param array<string, mixed> $subjects As passed to check().
	 *
	 * @return array<string, array<int, string>> By subject.
	 */
	public static function subjects(array $subjects)
	{
		$kept = [];

		foreach (array_keys(self::SUBJECTS) as $subject) {
			$values = [];

			foreach ((array) ($subjects[$subject] ?? []) as $value) {
				$value = self::value($subject, $value);

				if ($value !== null) {
					$values[] = $value;
				}
			}

			if ($values) {
				$kept[$subject] = array_values(array_unique($values));
			}
		}

		return $kept;
	}

	/**
	 * A column's "any" as SQL.
	 *
	 * @param string $column A key of ANY.
	 *
	 * @return string `0` or `''`.
	 */
	private static function anyLiteral($column)
	{
		return self::ANY[$column] === 0 ? '0' : "''";
	}

	/**
	 * The subjects a row sets, most specific first.
	 *
	 * @param array<string, mixed> $row The subject columns.
	 *
	 * @return array<int, string> Keys of SUBJECTS.
	 */
	private static function setSubjects(array $row)
	{
		$set = [];

		foreach (self::SUBJECTS as $subject => $column) {
			if (isset($row[$column]) && (string) $row[$column] !== (string) self::ANY[$column]) {
				$set[] = $subject;
			}
		}

		return $set;
	}

	/**
	 * The columns a ban is drawn from.
	 *
	 * @return string SELECT clause.
	 */
	private function select()
	{
		return "SELECT
			b.id, b.client_id, b.extension, b.mac, b.profile_id, b.ip, b.state, b.note, b.created_at, b.expires_at,
			b.hits, b.last_hit_at, b.source, b.jail, " . self::LAST_HIT_AGE_EXPR . " AS last_hit_age,
			" . self::CREATED_AGE_EXPR . " AS created_age,
			" . self::EXPIRES_IN_EXPR . " AS expires_in,
			" . self::ACTIVE_EXPR . " AS active,
			c.mac AS client_mac, cd.description AS client_description,
			ud.id AS user_device, ud.description AS user_name,
			mc.id AS mac_client_id, p.name AS profile_name";
	}

	/**
	 * The bans table, with what each subject names: the client and its device,
	 * the user's device, the client that has the MAC, and the profile.
	 *
	 * @return string FROM clause.
	 */
	private function from()
	{
		return "FROM `{$this->bansTable}` b
			LEFT JOIN `{$this->clientsTable}` c ON c.id = b.client_id
			LEFT JOIN devices cd ON cd.id = c.device_id
			LEFT JOIN devices ud ON ud.id = b.extension
			LEFT JOIN `{$this->clientsTable}` mc ON mc.mac = b.mac
			LEFT JOIN `{$this->profilesTable}` p ON p.id = b.profile_id";
	}

	/**
	 * Rows with what their subjects name put into words: client_label, user_name
	 * (null when no user has that number), ip_client and ip_client_id. A subject
	 * stored as ANY is handed out as null, the one "any" a view tests for.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from select().
	 *
	 * @return array<int, array<string, mixed>> The same rows.
	 */
	private function described(array $rows)
	{
		$addresses = null;

		foreach ($rows as &$row) {
			foreach (self::ANY as $column => $any) {
				if ((string) $row[$column] === (string) $any) {
					$row[$column] = null;
				}
			}

			$row['client_label'] = $row['client_id'] === null ? null : self::clientLabel([
				'id' => $row['client_id'],
				'mac' => $row['client_mac'],
				'description' => $row['client_description'],
			]);

			if ($row['user_device'] === null) {
				$row['user_name'] = null;
			}

			$row['ip_client'] = null;
			$row['ip_client_id'] = null;

			if ($row['ip'] !== null) {
				$addresses = $addresses ?? $this->clientAddresses();
				$client = $addresses[$row['ip']] ?? null;

				if ($client) {
					$row['ip_client'] = $client['count'] > 1
						? sprintf(_('%s and %d more'), $client['label'], $client['count'] - 1)
						: $client['label'];
					$row['ip_client_id'] = $client['id'];
				}
			}
		}
		unset($row);

		return $rows;
	}

	/**
	 * What a client is called on this tab: its device's name, else its MAC.
	 *
	 * @param array<string, mixed> $client id, mac, description.
	 *
	 * @return string Label.
	 */
	private static function clientLabel(array $client)
	{
		return (string) ($client['description'] ?: $client['mac'] ?: ('#' . $client['id']));
	}
}
