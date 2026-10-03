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

	/** @var Fail2ban */
	private $fail2ban;

	/**
	 * @param object   $freepbx  FreePBX application instance.
	 * @param Fail2ban $fail2ban The helper, through sudo.
	 */
	public function __construct($freepbx, Fail2ban $fail2ban)
	{
		parent::__construct($freepbx);

		$this->fail2ban = $fail2ban;
	}

	/**
	 * Whether the sync is switched on.
	 *
	 * @return bool True when ORYK_FAIL2BAN_SYNC is on.
	 */
	public function enabled()
	{
		return $this->fail2ban->enabled();
	}

	/**
	 * Whether the sync can run, and if not why not, with the command that sets it up.
	 *
	 * @return array<string, mixed> Fail2ban::status(), and command.
	 */
	public function status()
	{
		return $this->fail2ban->status() + ['command' => $this->fail2ban->setupCommand()];
	}

	/**
	 * Run the setup script as root, for Installer. Never throws.
	 *
	 * @param array<int, string> $args Its flags: none to install, --remove.
	 *
	 * @return array<int, string> What it printed, line by line.
	 */
	public function runSetup(array $args)
	{
		$pipes = [];
		$process = @proc_open(
			array_merge(['/bin/bash', $this->fail2ban->setupScript()], $args),
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes
		);

		if (!is_resource($process)) {
			return ['could not run ' . $this->fail2ban->setupScript()];
		}

		$output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		proc_close($process);

		return array_values(array_filter(array_map('rtrim', preg_split('/\R/', (string) $output)), 'strlen'));
	}

	/**
	 * The command that sets the sync up, as root.
	 *
	 * @return string A shell command line.
	 */
	public function setupCommand()
	{
		return $this->fail2ban->setupCommand();
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
	 * For a row about to be deleted, or changed so its copy no longer fits: the
	 * minute job cannot lift what it can no longer see in the table. Only a row
	 * with `synced_at` set has a copy of ours. Never throws.
	 *
	 * @param array<string, mixed>|null $row The row as Bans::banRow() reads it.
	 *
	 * @return bool True when there was nothing to lift, or it was lifted.
	 */
	public function lift($row)
	{
		if (!$row || !self::ipOnly($row) || empty($row['synced_at']) || !$this->enabled()) {
			return true;
		}

		$ip = (string) $row['ip'];

		// A ban the sync manages is fail2ban's own, in the jail it came from.
		if ($row['state'] === 'allow') {
			$answer = $this->fail2ban->unignore($ip);
		} elseif (!empty($row['managed']) && !empty($row['jail'])) {
			$answer = $this->fail2ban->unban((string) $row['jail'], $ip);
		} elseif ($row['state'] === 'deny') {
			$answer = $this->fail2ban->unban(Fail2ban::DENY_JAIL, $ip);
		} else {
			$answer = $this->fail2ban->unban(Fail2ban::BANNED_JAIL, $ip);
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
		if (!$this->enabled()) {
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
	 * Pure, so every case is tested. `deny` is mirrored exactly: it holds the
	 * Deny rows made here and nothing else. A row in force that the sync does
	 * not manage -- `managed` 0: made on the Bans tab, or a fail2ban ban a
	 * person has since saved -- is never changed by it.
	 *
	 * @param array<string, mixed>              $listed What Fail2ban::listAll() answered.
	 * @param array<string, array<string, mixed>> $rows IP-only rows by canonical
	 *                                                  address: id, state, managed,
	 *                                                  active, synced, expires_at,
	 *                                                  started_at (epochs or null).
	 * @param array<int, string>                $own    The PBX's own addresses: never pushed.
	 * @param string|null                       $only   One address to look at, or null for all.
	 *
	 * @return array<string, array<int, mixed>> insert, update, expire, synced,
	 *                                          unsynced, ban, unban, ignore.
	 */
	public static function plan(array $listed, array $rows, array $own = [], $only = null)
	{
		$plan = [
			'insert' => [], 'update' => [], 'expire' => [], 'synced' => [], 'unsynced' => [],
			'ban' => [], 'unban' => [], 'ignore' => [],
		];

		$jails = array_values(array_map('strval', (array) ($listed['jails'] ?? [])));
		$byIp = [];
		$inDeny = [];

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

			if ($ban['jail'] === Fail2ban::DENY_JAIL) {
				$inDeny[$ip] = true;
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

		$wantDeny = [];

		foreach ($rows as $ip => $row) {
			if ($only !== null && $ip !== $only) {
				continue;
			}

			$id = (int) $row['id'];
			$active = !empty($row['active']);
			$synced = !empty($row['synced']);
			$mine = empty($row['managed']);
			$bans = $byIp[$ip] ?? [];
			$local = self::local($ip, $own);

			if ($active && $row['state'] === 'allow') {
				// fail2ban never holds an address allowed here.
				foreach ($bans as $ban) {
					$plan['unban'][] = [$ban['jail'], $ip, null];
				}

				unset($byIp[$ip]);

				if ($local) {
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

				if ($local) {
					continue;
				}

				if ($row['state'] === 'deny') {
					$wantDeny[$ip] = true;
					$present = isset($inDeny[$ip]);
					$target = Fail2ban::DENY_JAIL;
				} else {
					$present = (bool) array_filter($bans, function ($ban) {
						return $ban['jail'] === Fail2ban::BANNED_JAIL;
					});
					$target = Fail2ban::BANNED_JAIL;
				}

				if ($present) {
					$plan['synced'][] = $id;
				} else {
					$plan['ban'][] = [$target, $ip, $id];
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

			// Not in force. A copy of ours still in `asterisk` is lifted -- unless
			// fail2ban banned the address again on its own after the row ran out,
			// which is a new ban and revives the row below.
			if ($synced && $mine) {
				$ends = (int) $row['expires_at'];
				$kept = [];
				$lifted = false;

				foreach ($bans as $ban) {
					if (!$lifted && $ban['jail'] === Fail2ban::BANNED_JAIL && $ban['banned_at'] < $ends) {
						$plan['unban'][] = [Fail2ban::BANNED_JAIL, $ip, $id];
						$lifted = true;
					} else {
						$kept[] = $ban;
					}
				}

				$byIp[$ip] = $kept;

				if (!$lifted) {
					$plan['unsynced'][] = $id;
				}
			} elseif ($synced) {
				$plan['unsynced'][] = $id;
			}
		}

		foreach ($inDeny as $ip => $yes) {
			if (!isset($wantDeny[$ip])) {
				$plan['unban'][] = [Fail2ban::DENY_JAIL, $ip, null];
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
		if (!$this->enabled()) {
			return ['ok' => true, 'paused' => true];
		}

		$listed = $this->fail2ban->listAll();

		if (empty($listed['ok'])) {
			return ['ok' => false, 'error' => (string) ($listed['error'] ?? 'fail2ban could not be read')];
		}

		try {
			$plan = self::plan($listed, $this->rows($only), $this->ownAddresses(), $only);

			return ['ok' => true] + $this->apply($plan);
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
			"SELECT b.id, b.ip, b.state, b.managed,
				" . Bans::ACTIVE_EXPR . " AS active,
				b.synced_at IS NOT NULL AS synced,
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
	 * @return array<string, int> What was done, by kind.
	 */
	private function apply(array $plan)
	{
		$synced = $plan['synced'];
		$unsynced = $plan['unsynced'];
		$done = ['banned' => 0, 'unbanned' => 0, 'ignored' => 0, 'imported' => 0, 'expired' => 0];

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
			$done['imported']++;
		}

		foreach ($plan['update'] as $ban) {
			// A revived row is fail2ban's for this period, so the sync manages it
			// again; its source stays what created it.
			$guard = $ban['revive'] ? "NOT $active" : "b.managed = 1 AND $active";
			$params = $this->banParams($ban) + [':id' => $ban['id'], ':times' => $ban['times']];

			$stmt = $this->db->prepare(
				"UPDATE `$table` b
				SET b.times = b.times + :times, b.started_at = FROM_UNIXTIME(:banned),
					b.state = :state, b.expires_at = IF(:permanent, NULL, FROM_UNIXTIME(:expires)),
					b.jail = :jail, b.synced_at = NOW(), b.managed = 1, b.updated_at = b.updated_at
				WHERE b.id = :id AND $guard"
			);
			$stmt->execute($params);
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

		return $done;
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
	 * The addresses this PBX answers on, as far as PHP can see.
	 *
	 * @return array<int, string> Addresses.
	 */
	private function ownAddresses()
	{
		$own = [(string) ($_SERVER['SERVER_ADDR'] ?? '')];
		$byName = @gethostbynamel((string) gethostname());

		return array_values(array_filter(array_merge($own, $byName ?: [])));
	}
}
