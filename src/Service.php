<?php

// src/Service.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * What every part of this module is given when it is built.
 *
 * Every subsystem reaches the same three things -- the FreePBX application,
 * the Asterisk database and the manager connection -- so they are taken apart
 * once, here, along with the table names every repository needs.
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

	/** @var string Who the endpoint refuses, or answers in spite of a ban. */
	protected $bansTable = 'oryk_provisioner_bans';

	/** @var string The services: a name each. */
	protected $servicesTable = 'oryk_provisioner_services';

	/** @var string Which service is under which: one row per parent and child, by slug. */
	protected $serviceLinksTable = 'oryk_provisioner_service_links';

	/** @var string Which user has which service: one row per extension and service slug. */
	protected $serviceAssignmentsTable = 'oryk_provisioner_service_assignments';

	/** @var string One change to what one user holds, run by the job worker. */
	protected $jobsTable = 'oryk_provisioner_jobs';

	/** @var string What a job means service by service: one row each, in order. */
	protected $jobStepsTable = 'oryk_provisioner_job_steps';

	/** @var string Name of the web-root symlink pointing at engine/. */
	protected $engineLink = 'provisioner';

	/** @var object FreePBX application instance. */
	public $FreePBX;

	/** @var \PDO Asterisk database handle. */
	public $db;

	/** @var object|null Asterisk manager connection; absent while Asterisk is down. */
	protected $astman;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx)
	{
		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
		$this->astman = $freepbx->astman ?? null;
	}

	/**
	 * Whether a FreePBX module is installed and enabled.
	 *
	 * @param string $module Module rawname.
	 *
	 * @return bool True when the module can be used.
	 */
	protected function moduleActive($module)
	{
		try {
			return (bool) $this->FreePBX->Modules->checkStatus($module);
		} catch (\Throwable $e) {
			return false;
		}
	}

	/**
	 * The command-line PHP, for a script under bin/: PHP_BINARY is php-fpm's
	 * own binary when this runs in a web request.
	 *
	 * @return string Path, or `php` to be found on the PATH.
	 */
	protected function phpBinary()
	{
		return is_executable(PHP_BINDIR . '/php') ? PHP_BINDIR . '/php' : 'php';
	}

	/**
	 * Whether the Asterisk manager can be written to. Every astdb write asks
	 * first: a stopped Asterisk is a normal state during an install.
	 *
	 * @return bool True when the manager is connected.
	 */
	protected function astmanReady()
	{
		return $this->astman && $this->astman->connected();
	}

	/**
	 * Log a failure, prefixed so the line can be traced to this module.
	 *
	 * @param string $message What happened.
	 *
	 * @return void
	 */
	protected function logError($message)
	{
		$this->log('oryk_provisioner: ' . $message, '', 'ERROR');
	}

	/**
	 * Log something that was stepped over rather than failed.
	 *
	 * @param string $message What happened.
	 *
	 * @return void
	 */
	protected function logWarning($message)
	{
		$this->log('oryk_provisioner: ' . $message, '', 'WARNING');
	}

	/**
	 * Log something worth looking back at.
	 *
	 * @param string $message What happened.
	 *
	 * @return void
	 */
	protected function logInfo($message)
	{
		$this->log('oryk_provisioner: ' . $message, '', 'INFO');
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
	 * It answers zero rather than throwing -- a table that is not there counts as
	 * empty, not as a 500 on the page.
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

	/**
	 * A clause keeping rows whose column is one of some values, each bound.
	 *
	 * No values keeps no rows, rather than every row. The column is
	 * interpolated, so **it may never come from a request**.
	 *
	 * @param string               $column Column, as written in the statement.
	 * @param array<int, mixed>    $values Values to keep.
	 * @param string               $name   Prefix for the placeholders, unique in the statement.
	 * @param array<string, mixed> $params Placeholders, added to.
	 *
	 * @return string The clause.
	 */
	protected function inClause($column, array $values, $name, array &$params)
	{
		$names = [];

		foreach (array_values(array_unique(array_map('strval', $values))) as $i => $value) {
			$names[] = ':' . $name . '_' . $i;
			$params[':' . $name . '_' . $i] = $value;
		}

		return $names ? "$column IN (" . implode(', ', $names) . ')' : '1 = 0';
	}
}
