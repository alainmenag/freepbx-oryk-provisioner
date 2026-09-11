<?php

// src/Service.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * What every part of this module is given when it is built.
 *
 * The subsystems all reach the same two things -- the FreePBX application and
 * the Asterisk database -- so those are taken apart once here, along with the
 * four table names every repository needs.
 *
 * Those names are properties rather than class constants because every
 * statement interpolates them: `FROM `{$this->clientsTable}``. A constant does
 * not interpolate, and `{self::CLIENTS}` renders as the literal text --
 * backticks and all -- which is how a CREATE TABLE quietly makes a table nobody
 * meant.
 */
abstract class Service
{
	use Logs;

	/** @var string Clients: a MAC, its FreePBX device and its profile. */
	protected $clientsTable = 'oryk_provisioner_clients';

	/** @var string Provisioning profiles. */
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
	 * The statement every count in this module is, written once. A null column or
	 * value is no narrowing rather than a row whose column is NULL, and both names
	 * are interpolated, so neither may come from a request.
	 *
	 * Answers zero rather than throwing: a table that is not there is a badge
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
