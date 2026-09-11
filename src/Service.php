<?php

// src/Service.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * What every part of this module is given when it is built.
 *
 * The subsystems this module is made of all reach the same two things: the
 * FreePBX application, so they can read configuration and log, and the
 * Asterisk database. Rather than each of them taking those apart again, they
 * are taken apart once here.
 *
 * The table names are here for the same reason -- they were four private
 * properties on the module class, referenced fifty-two times, and they are the
 * four properties every repository needs. They stay *properties* rather than
 * becoming class constants, deliberately: every statement in this module is
 * written as an interpolated string, `SELECT ... FROM `{$this->clientsTable}``,
 * and a constant does not interpolate. Written as `{self::CLIENTS}` the name
 * of the table becomes the literal text of the table name, backticks and all,
 * which MySQL accepts and which is how a CREATE TABLE quietly makes a table
 * nobody meant.
 */
abstract class Service
{
	use Logs;

	/**
	 * Table holding the clients: a MAC, the FreePBX device it stands for and
	 * the profile it is served.
	 *
	 * @var string
	 */
	protected $clientsTable = 'oryk_provisioner_clients';

	/**
	 * Table holding the provisioning profiles.
	 *
	 * @var string
	 */
	protected $profilesTable = 'oryk_provisioner_profiles';

	/**
	 * Table holding the files a profile serves, the main config included.
	 *
	 * @var string
	 */
	protected $resourcesTable = 'oryk_provisioner_resources';

	/**
	 * Table holding one row per request the provisioning endpoint answered.
	 *
	 * @var string
	 */
	protected $logsTable = 'oryk_provisioner_logs';

	/**
	 * Name of the web-root symlink that points at the engine directory.
	 *
	 * @var string
	 */
	protected $engineLink = 'provisioner';

	/**
	 * FreePBX application instance.
	 *
	 * @var object
	 */
	public $FreePBX;

	/**
	 * Asterisk database handle.
	 *
	 * @var \PDO
	 */
	public $db;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx)
	{
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
	}

	/**
	 * How many rows a table holds, or how many of them a column names.
	 *
	 * Here for the same reason the table names are: it is the statement every
	 * count in this module is, written once. A table is counted whole when no
	 * value narrows it, and a value of null is no narrowing rather than a row
	 * whose column is NULL -- nothing in this module counts those.
	 *
	 * The table and column are interpolated, so neither may come from a
	 * request: both are named by the caller, from the properties above.
	 *
	 * It answers zero rather than throwing. Counts are read on the way into a
	 * page and label a tab beside the table they count; a table that is not
	 * there -- a module upgraded from before it existed, an install part way
	 * through -- is a badge reading zero, not a 500 on the page.
	 *
	 * @param string      $table  Table to count, from this class's properties.
	 * @param string|null $column Column to narrow by, or null for the lot.
	 * @param mixed       $value  Value it has to hold, or null for the lot.
	 *
	 * @return int Rows counted.
	 */
	protected function rowCount($table, $column = null, $value = null)
	{
		$narrowed = $column !== null && $value !== null;

		try {
			$stmt = $this->db->prepare(
				"SELECT COUNT(*) FROM `$table`" . ($narrowed ? " WHERE `$column` = :value" : '')
			);
			$stmt->execute($narrowed ? [':value' => $value] : []);

			return (int) $stmt->fetchColumn();
		} catch (\Exception $e) {
			return 0;
		}
	}
}
