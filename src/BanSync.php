<?php

// src/BanSync.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * Keeping IP bans and fail2ban in step, both ways.
 *
 * run() is the minute job; reconcile() is the same thing for one address, which
 * a save on the Bans tab calls so a change reaches fail2ban at once; lift()
 * takes a row's copy back out of fail2ban before it is deleted or changed.
 * Every decision is plan(), which is pure. See ARCHITECTURE.md, "Syncing with
 * fail2ban".
 */
class BanSync extends Service
{
	/** What a row made by the sync says it was created by. */
	const SOURCE = 'fail2ban';

	/**
	 * Days prune() keeps a sync-made row after it expires. Bounds the table; an
	 * address back after this starts its Times count again.
	 */
	const KEEP_DAYS = 30;

	/** @var Fail2ban */
	private $fail2ban;

	/** @var BanEscalation|null What makes a repeat Banned ban a Deny; null for never. */
	private $escalation;

	/**
	 * @param object             $freepbx    FreePBX application instance.
	 * @param Fail2ban           $fail2ban   The helper, through sudo.
	 * @param BanEscalation|null $escalation ORYK_BAN_DENY_AFTER, or null for never.
	 */
	public function __construct($freepbx, Fail2ban $fail2ban, ?BanEscalation $escalation = null)
	{
		parent::__construct($freepbx);

		$this->fail2ban = $fail2ban;
		$this->escalation = $escalation;
	}

	/**
	 * One run of the minute job: every IP-only row against everything fail2ban has.
	 *
	 * Does nothing when fail2ban cannot be read -- no answer is not "no bans",
	 * and taken as one it would expire every row fail2ban made.
	 *
	 * @return array<string, mixed> ok, and what was done (counts), or error.
	 */
	public function run()
	{
		return $this->sync(null);
	}

	/**
	 * The same run for one address, after the Bans tab changed a row naming it.
	 *
	 * @param string $ip Canonical address.
	 *
	 * @return array<string, mixed> See run().
	 */
	public function reconcile($ip)
	{
		$ip = Bans::canonical($ip);

		return $ip === null ? ['ok' => false, 'error' => 'not an address'] : $this->sync($ip);
	}

	/**
	 * Take a row's copy out of fail2ban, and say it has none.
	 *
	 * For a row about to be deleted, or changed so its copy no longer fits. Only
	 * a row with `synced_at` set has a copy. Never throws.
	 *
	 * @param array<string, mixed>|null $row The row as Bans::banRow() reads it.
	 *
	 * @return bool True when there was nothing to lift, or it was lifted; false
	 *              when a copy is still there -- the helper failed, or the sync
	 *              is paused -- and Bans keeps the row, marked deleted, for the
	 *              minute job to finish.
	 */
	public function lift($row)
	{
		if (!$row || !self::ipOnly($row) || empty($row['synced_at'])) {
			return true;
		}

		if (!$this->fail2ban->enabled()) {
			return false;
		}

		$ip = (string) $row['ip'];

		// A ban the sync manages is fail2ban's own, in the jail it came from.
		if ($row['state'] === 'allow') {
			$answer = $this->fail2ban->unignore($ip);
		} elseif (!empty($row['managed']) && !empty($row['jail'])) {
			$answer = $this->fail2ban->unban((string) $row['jail'], $ip);
		} else {
			$answer = $this->fail2ban->unban(self::jailFor($row['state']), $ip);
		}

		if (empty($answer['ok'])) {
			$this->logWarning("fail2ban: could not lift ban #{$row['id']} ($ip): " . (string) ($answer['error'] ?? ''));

			return false;
		}

		$this->logInfo("fail2ban: lifted ban #{$row['id']} ($ip, {$row['state']})");
		$this->markSynced([(int) $row['id']], false);

		return true;
	}

	/**
	 * The module's jail a refusing row of ours is kept in.
	 *
	 * @param string $state banned or deny.
	 *
	 * @return string BANNED_JAIL or DENY_JAIL.
	 */
	public static function jailFor($state)
	{
		return $state === 'deny' ? Fail2ban::DENY_JAIL : Fail2ban::BANNED_JAIL;
	}

	/**
	 * After the Bans tab saved a row: lift the old copy if it no longer fits,
	 * then bring the new one in step. Never throws, never fails the save.
	 *
	 * @param array<string, mixed>|null $old The row before, or null when new.
	 * @param array<string, mixed>|null $new The row as saved.
	 *
	 * @return void
	 */
	public function afterSave($old, $new)
	{
		if (!$this->fail2ban->enabled()) {
			return;
		}

		try {
			$moved = $old && $new && (
				$old['ip'] !== $new['ip'] || !self::ipOnly($new) || $old['state'] !== $new['state']
			);

			if ($old && (!$new || $moved)) {
				$this->lift($old);
			}

			if ($new && self::ipOnly($new)) {
				$this->reconcile((string) $new['ip']);
			}
		} catch (\Exception $e) {
			$this->logWarning('fail2ban: ' . $e->getMessage());
		}
	}

	/**
	 * Whether a row names an address and nothing else: the only kind fail2ban takes.
	 *
	 * @param array<string, mixed> $row Subjects as Bans::banRow() hands them out
	 *                                  (null for "any").
	 *
	 * @return bool True when it is IP-only.
	 */
	public static function ipOnly(array $row)
	{
		return !empty($row['ip'])
			&& empty($row['client_id']) && empty($row['extension']) && empty($row['mac']) && empty($row['profile_id']);
	}

	/**
	 * What one run does, from what fail2ban has and what the table says.
	 *
	 * Pure, so every case is tested. The module's two jails, `banned` and
	 * `deny`, are mirrored exactly: each holds the rows of ours in force in that
	 * state and nothing else, so a row that expires, changes state or is deleted
	 * is lifted by the same rule. Every other jail is fail2ban's own and is
	 * followed into the table. A row in force that the sync does not manage --
	 * `managed` 0: made on the Bans tab, or a fail2ban ban a person has since
	 * saved -- is never changed by it. A row marked deleted has its copy lifted
	 * and is then purged.
	 *
	 * @param array<string, mixed>              $listed What Fail2ban::listAll() answered.
	 * @param array<string, array<string, mixed>> $rows IP-only rows by canonical
	 *                                                  address: id, state, managed,
	 *                                                  jail, active, synced, deleted,
	 *                                                  expires_at, started_at (epochs
	 *                                                  or null).
	 * @param array<int, string>                $own    The PBX's own addresses: never pushed.
	 * @param string|null                       $only   One address to look at, or null for all.
	 *
	 * @return array<string, array<int, mixed>> insert, update, expire, synced,
	 *                                          unsynced, ban, unban, ignore,
	 *                                          unignore, purge.
	 */
	public static function plan(array $listed, array $rows, array $own = [], $only = null)
	{
		$plan = [
			'insert' => [], 'update' => [], 'expire' => [], 'synced' => [], 'unsynced' => [],
			'ban' => [], 'unban' => [], 'ignore' => [], 'unignore' => [], 'purge' => [],
		];

		$module = [Fail2ban::BANNED_JAIL, Fail2ban::DENY_JAIL];
		$jails = array_values(array_map('strval', (array) ($listed['jails'] ?? [])));
		$byIp = [];
		$inModule = array_fill_keys($module, []);

		foreach ((array) ($listed['bans'] ?? []) as $ban) {
			$ip = Bans::canonical($ban['ip'] ?? '');

			if ($ip === null || ($only !== null && $ip !== $only)) {
				continue;
			}

			$permanent = !empty($ban['permanent']);
			$ban = [
				'jail' => (string) $ban['jail'],
				'banned_at' => (int) $ban['banned_at'],
				'permanent' => $permanent,
				'expires_at' => $permanent || !isset($ban['expires_at']) ? null : (int) $ban['expires_at'],
			];

			if (isset($inModule[$ban['jail']])) {
				$inModule[$ban['jail']][$ip] = true;
			} else {
				$byIp[$ip][] = $ban;
			}
		}

		// An address is on the ignore list when every jail has it there.
		$ignored = [];

		foreach ($jails as $jail) {
			foreach ((array) ($listed['ignore'][$jail] ?? []) as $entry) {
				$ip = Bans::canonical($entry);

				if ($ip !== null) {
					$ignored[$ip][$jail] = true;
				}
			}
		}

		$everywhere = function ($ip) use ($ignored, $jails) {
			return $jails && count($ignored[$ip] ?? []) === count($jails);
		};

		$want = array_fill_keys($module, []);

		// Whose copy an unwanted ban in the module's jails is, for the rows that
		// can still have one: not in force, or deleted.
		$owner = [];

		foreach ($rows as $ip => $row) {
			if ($only !== null && $ip !== $only) {
				continue;
			}

			$id = (int) $row['id'];
			$active = !empty($row['active']);
			$synced = !empty($row['synced']);
			$mine = empty($row['managed']);
			$bans = $byIp[$ip] ?? [];

			if (!empty($row['deleted'])) {
				// Its copy comes out -- from the module's jails by the mirror below
				// -- and then the row goes. Nothing is imported onto it meanwhile.
				unset($byIp[$ip]);

				if ($row['state'] === 'allow' && $synced) {
					$plan['unignore'][] = [$ip, $id];
				} elseif (!$mine && $synced) {
					foreach ($bans as $ban) {
						if ($ban['jail'] === (string) $row['jail']) {
							$plan['unban'][] = [$ban['jail'], $ip, $id];
						}
					}
				}

				$plan['purge'][] = $id;
				$owner[$ip] = $id;

				continue;
			}

			if ($active && $row['state'] === 'allow') {
				// fail2ban never holds an address allowed here.
				foreach ($bans as $ban) {
					$plan['unban'][] = [$ban['jail'], $ip, null];
				}

				unset($byIp[$ip]);

				if (self::local($ip, $own)) {
					continue;
				}

				if (!$everywhere($ip)) {
					$plan['ignore'][] = [$ip, $id];
				} elseif ($synced) {
					$plan['synced'][] = $id;
				}

				continue;
			}

			if ($active && $mine) {
				// A refusing row made here. fail2ban's own bans on the address are
				// not imported: the row is in force and is not the sync's to change.
				unset($byIp[$ip]);

				if (self::local($ip, $own)) {
					continue;
				}

				$jail = self::jailFor($row['state']);
				$want[$jail][$ip] = true;

				if (isset($inModule[$jail][$ip])) {
					$plan['synced'][] = $id;
				} else {
					$plan['ban'][] = [$jail, $ip, $id];
				}

				continue;
			}

			if ($active) {
				// The sync's own row: refreshed below, or expired if fail2ban let it go.
				if (!$bans) {
					$plan['expire'][] = $id;
				}

				continue;
			}

			// Not in force: a copy in the module's jails is lifted by the mirror,
			// which says so; a fail2ban ban made since revives the row below.
			$copy = isset($inModule[Fail2ban::BANNED_JAIL][$ip]) || isset($inModule[Fail2ban::DENY_JAIL][$ip]);

			if ($synced && !$copy) {
				$plan['unsynced'][] = $id;
			}

			$owner[$ip] = $id;
		}

		foreach ($inModule as $jail => $ips) {
			foreach ($ips as $ip => $yes) {
				if (!isset($want[$jail][$ip])) {
					$plan['unban'][] = [$jail, $ip, $owner[$ip] ?? null];
				}
			}
		}

		foreach ($byIp as $ip => $bans) {
			if (!$bans) {
				continue;
			}

			$best = self::longest($bans);
			$row = $rows[$ip] ?? null;

			if ($row === null) {
				$plan['insert'][] = ['ip' => $ip] + $best;

				continue;
			}

			$revive = empty($row['active']);
			$again = $revive || (int) $row['started_at'] !== $best['banned_at'];

			$plan['update'][] = ['id' => (int) $row['id'], 'revive' => $revive, 'times' => $again ? 1 : 0] + $best;
		}

		return $plan;
	}

	/**
	 * The ban that lasts longest of several on one address: a permanent one,
	 * else the latest to expire, else the latest made.
	 *
	 * @param array<int, array<string, mixed>> $bans jail, banned_at, permanent, expires_at.
	 *
	 * @return array<string, mixed> The one kept.
	 */
	public static function longest(array $bans)
	{
		usort($bans, function ($a, $b) {
			return [$b['permanent'], (int) $b['expires_at'], $b['banned_at']]
				<=> [$a['permanent'], (int) $a['expires_at'], $a['banned_at']];
		});

		return $bans[0];
	}

	/**
	 * Whether an address must never be pushed: loopback, unspecified, or the PBX's own.
	 *
	 * @param string             $ip  Canonical address.
	 * @param array<int, string> $own The PBX's own addresses.
	 *
	 * @return bool True when it is.
	 */
	public static function local($ip, array $own)
	{
		if (Bans::loopback($ip)) {
			return true;
		}

		foreach ($own as $address) {
			if ($ip === Bans::canonical($address)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Read, plan and apply, for every address or one.
	 *
	 * @param string|null $only Canonical address, or null.
	 *
	 * @return array<string, mixed> See run().
	 */
	private function sync($only)
	{
		if (!$this->fail2ban->enabled()) {
			return ['ok' => true, 'paused' => true];
		}

		$listed = $this->fail2ban->listAll();

		if (empty($listed['ok'])) {
			return ['ok' => false, 'error' => (string) ($listed['error'] ?? 'fail2ban could not be read')];
		}

		try {
			$plan = self::plan($listed, $this->rows($only), $this->ownAddresses(), $only);
			list($done, $denied) = $this->apply($plan);

			// A row made Deny is the person's now, so it moves into `deny` --
			// at once rather than on the next run. It cannot be made Deny twice.
			foreach ($denied as $ip) {
				if ($ip !== '') {
					$this->sync($ip);
				}
			}

			if ($only === null) {
				$done['pruned'] = $this->prune();
			}

			return ['ok' => true] + $done + ['denied' => count($denied)];
		} catch (\Exception $e) {
			$this->logError('fail2ban sync: ' . $e->getMessage());

			return ['ok' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * The IP-only rows, keyed by address, as plan() reads them.
	 *
	 * Times are epochs on the database's clock, the clock FROM_UNIXTIME()
	 * writes them back in.
	 *
	 * @param string|null $only Canonical address, or null for all.
	 *
	 * @return array<string, array<string, mixed>> Rows by address.
	 */
	private function rows($only)
	{
		$stmt = $this->db->prepare(
			"SELECT b.id, b.ip, b.state, b.managed, b.jail,
				" . Bans::ACTIVE_EXPR . " AS active,
				b.synced_at IS NOT NULL AS synced,
				b.deleted_at IS NOT NULL AS deleted,
				UNIX_TIMESTAMP(b.expires_at) AS expires_at,
				UNIX_TIMESTAMP(b.started_at) AS started_at
			FROM `{$this->bansTable}` b
			WHERE b.client_id = 0 AND b.extension = '' AND b.mac = '' AND b.profile_id = 0 AND b.ip <> ''"
			. ($only === null ? '' : ' AND b.ip = :ip')
		);
		$stmt->execute($only === null ? [] : [':ip' => $only]);

		$rows = [];

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$rows[(string) $row['ip']] = $row;
		}

		return $rows;
	}

	/**
	 * Carry out a plan: fail2ban first, then the rows that say what fail2ban now has.
	 *
	 * A row is only marked as having a copy in fail2ban once the helper said
	 * yes. Every write assigns `updated_at = updated_at` -- a sync is not an
	 * edit -- and is guarded against a row that changed since it was read.
	 *
	 * @param array<string, array<int, mixed>> $plan From plan().
	 *
	 * @return array<int, mixed> What was done, by kind; and the rows a ban
	 *                           back in force made Deny (BanEscalation::apply()).
	 */
	private function apply(array $plan)
	{
		$synced = $plan['synced'];
		$unsynced = $plan['unsynced'];
		$done = ['banned' => 0, 'unbanned' => 0, 'ignored' => 0, 'imported' => 0, 'expired' => 0, 'unignored' => 0, 'purged' => 0];

		// Rows a lift failed for: a deleted one among them is kept for the next run.
		$failed = [];

		foreach ($plan['ban'] as list($jail, $ip, $id)) {
			if ($this->said($this->fail2ban->ban($jail, $ip), "banned $ip in $jail (ban #$id)")) {
				$synced[] = $id;
				$done['banned']++;
			}
		}

		foreach ($plan['unban'] as list($jail, $ip, $id)) {
			if ($this->said($this->fail2ban->unban($jail, $ip), "unbanned $ip from $jail" . ($id ? " (ban #$id)" : ''))) {
				if ($id) {
					$unsynced[] = $id;
				}

				$done['unbanned']++;
			} elseif ($id) {
				$failed[$id] = true;
			}
		}

		foreach ($plan['unignore'] as list($ip, $id)) {
			if ($this->said($this->fail2ban->unignore($ip), "no longer ignoring $ip (ban #$id)")) {
				$done['unignored']++;
			} else {
				$failed[$id] = true;
			}
		}

		foreach ($plan['ignore'] as list($ip, $id)) {
			if ($this->said($this->fail2ban->ignore($ip), "ignoring $ip in every jail (ban #$id)")) {
				$synced[] = $id;
				$done['ignored']++;
			}
		}

		$active = Bans::ACTIVE_EXPR;
		$table = $this->bansTable;

		foreach ($plan['insert'] as $ban) {
			// IGNORE the race where a row for the address appeared since it was read.
			$stmt = $this->db->prepare(
				"INSERT INTO `$table` (ip, state, expires_at, source, jail, started_at, times, synced_at, managed)
				VALUES (:ip, :state, IF(:permanent, NULL, FROM_UNIXTIME(:expires)), :source, :jail,
					FROM_UNIXTIME(:banned), 1, NOW(), 1)
				ON DUPLICATE KEY UPDATE id = id"
			);
			$stmt->execute($this->banParams($ban) + [':ip' => $ban['ip'], ':source' => self::SOURCE]);
			$done['imported'] += $stmt->rowCount() === 1 ? 1 : 0;
		}

		// Rows fail2ban has put back in force, for ORYK_BAN_DENY_AFTER.
		$renewed = [];

		foreach ($plan['update'] as $ban) {
			// A revived row is fail2ban's for this period, so the sync manages it
			// again; its source stays what created it.
			$guard = $ban['revive'] ? "b.deleted_at IS NULL AND NOT $active" : "b.managed = 1 AND $active";
			$params = $this->banParams($ban) + [':id' => $ban['id'], ':times' => $ban['times']];

			$stmt = $this->db->prepare(
				"UPDATE `$table` b
				SET b.times = b.times + :times, b.started_at = FROM_UNIXTIME(:banned),
					b.state = :state, b.expires_at = IF(:permanent, NULL, FROM_UNIXTIME(:expires)),
					b.jail = :jail, b.synced_at = NOW(), b.managed = 1, b.updated_at = b.updated_at
				WHERE b.id = :id AND $guard"
			);
			$stmt->execute($params);

			if ($ban['times'] && $stmt->rowCount()) {
				$renewed[] = $ban['id'];
			}
		}

		if ($plan['expire']) {
			$stmt = $this->db->prepare(
				"UPDATE `$table` b
				SET b.state = 'banned', b.expires_at = NOW(), b.synced_at = NULL, b.updated_at = b.updated_at
				WHERE b.id IN (" . self::ids($plan['expire']) . ") AND b.managed = 1 AND $active"
			);
			$stmt->execute();
			$done['expired'] = count($plan['expire']);
		}

		$this->markSynced($synced, true);
		$this->markSynced($unsynced, false);

		$purge = array_diff(array_map('intval', $plan['purge']), array_keys($failed));

		if ($purge) {
			$done['purged'] = $this->db->exec(
				"DELETE FROM `$table` WHERE id IN (" . self::ids($purge) . ") AND deleted_at IS NOT NULL"
			);
		}

		return [$done, $this->escalation ? $this->escalation->apply($renewed) : []];
	}

	/**
	 * Delete sync-made rows, still managed, expired over KEEP_DAYS ago, with no
	 * copy in fail2ban. A row anybody saved is never pruned.
	 *
	 * @return int Rows deleted.
	 */
	private function prune()
	{
		return (int) $this->db->exec(
			"DELETE FROM `{$this->bansTable}`
			WHERE managed = 1 AND source = '" . self::SOURCE . "' AND state = 'banned'
				AND synced_at IS NULL AND expires_at < NOW() - INTERVAL " . (int) self::KEEP_DAYS . ' DAY'
		);
	}

	/**
	 * Bound values for one ban from fail2ban.
	 *
	 * @param array<string, mixed> $ban jail, banned_at, permanent, expires_at.
	 *
	 * @return array<string, mixed> Parameters.
	 */
	private function banParams(array $ban)
	{
		return [
			':state' => $ban['permanent'] ? 'deny' : 'banned',
			':permanent' => $ban['permanent'] ? 1 : 0,
			':expires' => (int) $ban['expires_at'],
			':jail' => $ban['jail'],
			':banned' => $ban['banned_at'],
		];
	}

	/**
	 * Set or clear `synced_at` on rows.
	 *
	 * @param array<int, int> $ids Row ids.
	 * @param bool            $set True to stamp NOW(), false to clear.
	 *
	 * @return void
	 */
	private function markSynced(array $ids, $set)
	{
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if (!$ids) {
			return;
		}

		$this->db->exec(
			"UPDATE `{$this->bansTable}`
			SET synced_at = " . ($set ? 'NOW()' : 'NULL') . ", updated_at = updated_at
			WHERE id IN (" . self::ids($ids) . ")"
		);
	}

	/**
	 * Ids as a SQL list. They are ints by now; the cast is the guarantee.
	 *
	 * @param array<int, mixed> $ids Row ids.
	 *
	 * @return string `1, 2, 3`.
	 */
	private static function ids(array $ids)
	{
		return implode(', ', array_map('intval', $ids));
	}

	/**
	 * Whether the helper said yes; logged either way.
	 *
	 * @param array<string, mixed> $answer What the helper answered.
	 * @param string               $what   What was asked, for the log.
	 *
	 * @return bool True when it did.
	 */
	private function said(array $answer, $what)
	{
		if (empty($answer['ok'])) {
			$this->logWarning("fail2ban: could not do it: $what: " . (string) ($answer['error'] ?? ''));

			return false;
		}

		$this->logInfo("fail2ban: $what");

		return true;
	}

	/**
	 * The addresses this PBX answers on: every interface's, then whatever the
	 * request and the hostname say. The minute job has no request, so the
	 * interfaces are the list it relies on.
	 *
	 * @return array<int, string> Addresses.
	 */
	private function ownAddresses()
	{
		$own = [(string) ($_SERVER['SERVER_ADDR'] ?? '')];

		foreach ((function_exists('net_get_interfaces') ? (@net_get_interfaces() ?: []) : []) as $interface) {
			foreach ((array) ($interface['unicast'] ?? []) as $unicast) {
				$own[] = (string) ($unicast['address'] ?? '');
			}
		}

		$byName = @gethostbynamel((string) gethostname());

		return array_values(array_unique(array_filter(array_merge($own, $byName ?: []))));
	}
}
