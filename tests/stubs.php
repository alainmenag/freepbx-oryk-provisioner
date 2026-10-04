<?php

// tests/stubs.php
//
// Just enough FreePBX to stand the subsystems up outside one. Everything
// here is a stub: no database, no Asterisk, no configuration files. What it
// gives back is fixed and what it is asked is recorded, so a test can check
// what a subsystem tried to do rather than what came of it.

define('CONF_TYPE_TEXT', 'text');
define('CONF_TYPE_BOOL', 'bool');
define('CONF_TYPE_INT', 'int');
define('CONF_TYPE_SELECT', 'select');

define('FPBX_LOG_ERROR', 'ERROR');
define('FPBX_LOG_WARNING', 'WARNING');
define('FPBX_LOG_INFO', 'INFO');

$LOG = [];

function freepbx_log($level, $message)
{
	global $LOG;

	$LOG[] = $level . ': ' . $message;
}

function needreload()
{
	return true;
}

/** A prepared statement that answers with whatever it was handed. */
class StubStatement
{
	public $column = false;
	public $rows = [];
	public $params = null;
	public $affected = 0;
	public $fetchRows = [];
	public $fetchColumns = [];
	public $onExecute = null;

	public function execute($params = null)
	{
		$this->params = $params;

		if ($this->onExecute) {
			call_user_func($this->onExecute, $params);
		}

		return true;
	}

	public function fetch($mode = null)
	{
		return $this->fetchRows ? array_shift($this->fetchRows) : false;
	}

	public function fetchColumn($n = 0)
	{
		if ($this->fetchColumns) {
			return array_shift($this->fetchColumns);
		}

		return $this->column;
	}

	public function fetchAll($mode = null)
	{
		return $this->rows;
	}

	public function bindValue($k, $v, $t = null)
	{
		return true;
	}

	public function rowCount()
	{
		return $this->affected;
	}
}

/**
 * A database that answers by what the statement looks like.
 *
 * $answers maps a distinctive fragment of SQL onto the value fetchColumn()
 * should give back. Anything not listed answers false, which for every
 * query this module makes means "nothing holds that".
 */
class StubDatabase
{
	public $answers = [];
	/** @var array<string, array> Fragment => rows fetch() hands back, in turn. */
	public $fetches = [];
	/** @var array<string, array> Fragment => what fetchAll() hands back. */
	public $fetchAlls = [];
	public $seen = [];
	public $params = [];
	public $insertId = 0;

	public function prepare($sql)
	{
		$this->seen[] = $sql;
		$statement = new StubStatement();
		$statement->onExecute = function ($params) use ($sql) {
			$this->params[] = [$sql, $params];
		};

		// Users::withLock(): the named lock is always had.
		if (strpos($sql, 'GET_LOCK') !== false) {
			$statement->column = 1;
		}

		foreach ($this->answers as $fragment => $value) {
			if (strpos($sql, $fragment) !== false) {
				$statement->column = $value;

				break;
			}
		}

		foreach ($this->fetchAlls as $fragment => $rows) {
			if (strpos($sql, $fragment) !== false) {
				$statement->rows = $rows;

				break;
			}
		}

		foreach ($this->fetches as $fragment => $rows) {
			if (strpos($sql, $fragment) !== false) {
				$statement->fetchRows = $rows;

				break;
			}
		}

		return $statement;
	}

	public function lastInsertId()
	{
		return (string) $this->insertId;
	}

	public function exec($sql)
	{
		$this->seen[] = $sql;

		return 0;
	}
}

/**
 * A CDR database that answers by what the statement looks like.
 *
 * The real one varies with the FreePBX version and with which optional
 * modules a site has, which is the whole reason CdrHistory asks what is in
 * front of it rather than assuming. This answers as a plain install would.
 */
class StubCdrDatabase
{
	public $tables = ['cdr'];
	public $columns = [
		'cdr' => ['calldate', 'clid', 'src', 'dst', 'channel', 'dstchannel',
		          'lastapp', 'duration', 'disposition', 'uniqueid', 'linkedid',
		          'accountcode', 'peeraccount', 'cnum', 'recordingfile'],
		'cel' => ['eventtype', 'cid_num', 'cid_ani', 'exten', 'channame',
		          'peer', 'accountcode', 'peeraccount', 'uniqueid', 'linkedid'],
	];
	public $calls = [['uniqueid' => '1700000000.1', 'linkedid' => '1700000000.1']];
	public $recordings = ['out-1001-2002-20260101-120000-1700000000.1.wav'];
	public $statements = [];

	public function prepare($sql)
	{
		$this->statements[] = $sql;
		$statement = new StubStatement();

		if (strpos($sql, 'SHOW TABLES LIKE') !== false) {
			$statement->onExecute = function ($params) use ($statement) {
				$wanted = is_array($params) ? reset($params) : null;
				$statement->column = in_array($wanted, $this->tables, true) ? $wanted : false;
			};

			return $statement;
		}

		if (preg_match('/SHOW COLUMNS FROM `(\w+)`/', $sql, $m)) {
			$statement->rows = $this->columns[$m[1]] ?? [];

			return $statement;
		}

		if (strpos($sql, 'uniqueid') !== false && strpos($sql, 'SELECT') === 0) {
			$statement->fetchRows = $this->calls;

			return $statement;
		}

		if (strpos($sql, 'recordingfile') !== false && strpos($sql, 'SELECT') === 0) {
			$statement->fetchColumns = $this->recordings;

			return $statement;
		}

		$statement->affected = 1; // an UPDATE or DELETE that matched something

		return $statement;
	}
}

/** The parts of the CDR module this module calls. */
class StubCdr
{
	public $handle;

	public function __construct()
	{
		$this->handle = new StubCdrDatabase();
	}

	public function getCdrDbHandle()
	{
		return $this->handle;
	}

	public function getDbTable()
	{
		return 'cdr';
	}
}

/** The bit of a Core driver that store() asks about. */
class StubDriver
{
	public function getDefaultDeviceSettings($id, $displayname, &$flag)
	{
		return ['dial' => 'PJSIP', 'settings' => []];
	}
}

/** The parts of the Core module this module calls. */
class StubCore
{
	public $devices = [];
	public $users = [];
	public $added = null;
	public $deleted = [];
	public $epm = [];

	public function getDevice($id)
	{
		return $this->devices[(string) $id] ?? [];
	}

	public function generateDefaultDeviceSettings($tech, $user, $displayname, $flag)
	{
		return [
			'description' => ['value' => $displayname, 'flag' => 1],
			'user' => ['value' => $user, 'flag' => 2],
			'secret' => ['value' => 'from-core', 'flag' => 3],
			'emergency_cid' => ['value' => '', 'flag' => 4],
			'media_encryption' => ['value' => 'no', 'flag' => 5],
		];
	}

	public function getDriver($tech)
	{
		return new StubDriver();
	}

	public function generateDefaultUserSettings($extension, $displayname)
	{
		return ['extension' => $extension, 'name' => $displayname];
	}

	public function addUser($extension, $settings)
	{
		$this->users[(string) $extension] = $settings;

		return true;
	}

	public function delUser($extension, $editmode = false)
	{
		unset($this->users[(string) $extension]);

		return true;
	}

	public function addDevice($id, $tech, $settings, $editmode = false)
	{
		$this->added = ['id' => $id, 'tech' => $tech, 'settings' => $settings];

		return true;
	}

	public function delDevice($id, $editmode = false)
	{
		$this->deleted[] = [(string) $id, $editmode];

		return true;
	}

	public function processEPM($id, $tech, $flag)
	{
		$this->epm[] = (string) $id;

		return true;
	}
}

/**
 * FreePBX's own settings, the ones Advanced Settings shows.
 *
 * It answers with what it holds, records what was defined, and keeps the
 * one rule this module depends on: defining a setting that already exists
 * leaves the value where it is.
 */
class StubConfig
{
	public $defined = [];

	public function get($keyword, $passthru = false)
	{
		return FreePBX::$config[$keyword] ?? '';
	}

	public function conf_setting_exists($keyword)
	{
		return array_key_exists($keyword, FreePBX::$config);
	}

	public function update($keyword, $value, $commit = true, $override = true)
	{
		FreePBX::$config[$keyword] = $value;

		return true;
	}

	public function define_conf_setting($keyword, $vars, $commit = false)
	{
		$this->defined[$keyword] = $vars;

		// The real one keeps the value of a setting it already has
		if (!array_key_exists($keyword, FreePBX::$config)) {
			FreePBX::$config[$keyword] = $vars['value'];
		}

		return true;
	}
}

class StubModules
{
	public $active = [];

	public function checkStatus($module)
	{
		return in_array($module, $this->active, true);
	}

	public function loadFunctionsInc($module)
	{
		return false;
	}
}

/** The FreePBX logger, which the module's Logs trait writes through. */
class StubLogger
{
	public function log($level, $message)
	{
		freepbx_log($level, $message);
	}
}

class StubApp
{
	public $Modules;
	public $Database;
	public $Logger;
	public $Config;
	public $astman;

	public function __construct()
	{
		$this->Modules = new StubModules();
		$this->Config = new StubConfig();
		$this->Database = new StubDatabase();
		$this->Logger = new StubLogger();
		$this->astman = null;
	}
}

/** The static entry points, pointed at one shared set of stubs. */
class FreePBX
{
	public static $core;
	public static $config = ['ASTSPOOLDIR' => '/var/spool/asterisk'];

	public static function Core()
	{
		if (self::$core === null) {
			self::$core = new StubCore();
		}

		return self::$core;
	}

	public static $conf;

	public static function Config()
	{
		if (self::$conf === null) {
			self::$conf = new StubConfig();
		}

		return self::$conf;
	}

	public static $cdr;

	public static function Cdr()
	{
		if (self::$cdr === null) {
			self::$cdr = new StubCdr();
		}

		return self::$cdr;
	}

	public static function Framework()
	{
		return new class {
			public function doReload()
			{
				return ['status' => true];
			}
		};
	}
}

// The same loader the module registers, pointed at this checkout
spl_autoload_register(function ($class) {
	$prefix = 'FreePBX\\Modules\\Oryk_Provisioner\\';

	if (strpos($class, $prefix) !== 0) {
		return;
	}

	$relative = substr($class, strlen($prefix));

	if (strpos($relative, 'Drivers\\') === 0) {
		return;
	}

	$file = __DIR__ . '/../src/' . str_replace('\\', '/', $relative) . '.php';

	if (is_file($file)) {
		require_once $file;
	}
});
