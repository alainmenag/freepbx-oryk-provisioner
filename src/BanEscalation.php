<?php

// src/BanEscalation.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * Turning a repeat Banned ban into a Deny (ORYK_BAN_DENY_AFTER).
 *
 * Asked only at the moment a ban comes back into force -- `times` going up,
 * on a save on the Bans tab or a fail2ban ban the sync follows -- never of a
 * row just because its count is high, so a person who sets a ban back to
 * Banned by hand is not overruled. See ARCHITECTURE.md, "Bans".
 */
class BanEscalation extends Service
{
	/** @var Settings */
	private $settings;

	/**
	 * @param object   $freepbx  FreePBX application instance.
	 * @param Settings $settings Whose ORYK_BAN_DENY_AFTER is the threshold.
	 */
	public function __construct($freepbx, Settings $settings)
	{
		parent::__construct($freepbx);

		$this->settings = $settings;
	}

	/**
	 * How many times a ban may be in force before it is made Deny.
	 *
	 * @return int The threshold, or 0 when it is off (blank).
	 */
	public function threshold()
	{
		$value = (string) $this->settings->get(Settings::BAN_DENY_AFTER);

		return ctype_digit($value) && (int) $value >= 2 ? (int) $value : 0;
	}

	/**
	 * Make Deny every row among these that is Banned, in force, and has now
	 * been in force the threshold's number of times or more.
	 *
	 * A row made Deny here is the person's from then on (`managed` 0), as if
	 * they had saved it, so the fail2ban sync keeps it in `deny`. Never throws.
	 *
	 * @param array<int, int> $ids Rows that have just come back into force.
	 *
	 * @return array<int, string> The rows made Deny: id => address ('' for none).
	 */
	public function apply(array $ids)
	{
		$after = $this->threshold();
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

		if (!$after || !$ids) {
			return [];
		}

		$list = implode(', ', $ids);

		try {
			$stmt = $this->db->prepare(
				"SELECT b.id, b.ip FROM `{$this->bansTable}` b
				WHERE b.id IN ($list) AND b.state = 'banned' AND b.times >= :after AND " . Bans::ACTIVE_EXPR
			);
			$stmt->execute([':after' => $after]);
			$found = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

			if (!$found) {
				return [];
			}

			$this->db->exec(
				"UPDATE `{$this->bansTable}`
				SET state = 'deny', expires_at = NULL, managed = 0, updated_at = updated_at
				WHERE id IN (" . implode(', ', array_map('intval', array_keys($found))) . ") AND state = 'banned'"
			);
		} catch (\Exception $e) {
			$this->logError('could not turn repeat bans into Deny: ' . $e->getMessage());

			return [];
		}

		foreach ($found as $id => $ip) {
			$this->logInfo("ban #$id" . ($ip !== '' ? " ($ip)" : '') . " is Deny: in force $after times or more");
		}

		return array_map('strval', $found);
	}
}
