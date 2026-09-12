<?php

// src/Service.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * What every part of this module is given when it is built.
 *
 * Every subsystem reaches the same two things -- the FreePBX application and
 * the Asterisk database -- so they are taken apart once, here, along with the
 * four table names every repository needs.
 *
 * The table names stay **properties rather than class constants**,
 * deliberately: every statement in this module is an interpolated string,
 * `SELECT ... FROM `{$this->clientsTable}``, and a constant does not
 * interpolate. Written as `{self::CLIENTS}` the table name becomes the literal
 * text of that expression, which MySQL accepts and which is how a CREATE TABLE
 * quietly makes a table nobody meant.
 */
abstract class Service
{
	use Logs;

	/** @var string Clients: a MAC, the FreePBX device it stands for, its profile. */
	protected $clientsTable = 'oryk_provisioner_clients';

	/** @var string The provisioning profiles. */
	protected $profilesTable = 'oryk_provisioner_profiles';

	/** @var string The files a profile serves, the main config included. */
	protected $resourcesTable = 'oryk_provisioner_resources';

	/** @var string One row per request the provisioning endpoint answered. */
	protected $logsTable = 'oryk_provisioner_logs';

	/** @var string Name of the web-root symlink pointing at engine/. */
	protected $engineLink = 'provisioner';

	/** @var object FreePBX application instance. */
	public $FreePBX;

	/** @var \PDO Asterisk database handle. */
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
	 * The statement every count in this module is, written once. A value of null
	 * is no narrowing rather than a row whose column is NULL.
	 *
	 * The table and column are interpolated, so **neither may come from a
	 * request**: both are named by the caller, from the properties above.
	 *
	 * It answers zero rather than throwing -- a table that is not there is a badge
	 * reading zero, not a 500 on the page.
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
