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
}
