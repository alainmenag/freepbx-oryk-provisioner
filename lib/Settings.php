<?php

namespace Oryk\Provisioner;

use Oryk\Provisioner\Database\Connection;
use Oryk\Provisioner\Database\Schema;

/**
 * Module settings, stored as key/value rows.
 *
 * A dedicated table (rather than the BMO KVStore) keeps settings reachable from
 * the standalone provisioning endpoint without instantiating the module class.
 */
class Settings
{
	/** Every supported setting with its default value and cast. */
	private static $definitions = array(
		'server_address'          => array('default' => '',      'type' => 'string'),
		'server_port'             => array('default' => '',      'type' => 'string'),
		'server_protocol'         => array('default' => 'auto',  'type' => 'string'),
		'sip_domain'              => array('default' => '',      'type' => 'string'),
		'sip_port'                => array('default' => '5060',  'type' => 'string'),
		'sip_transport'           => array('default' => 'udp',   'type' => 'string'),
		'token_length'            => array('default' => 32,      'type' => 'int'),
		'allow_config_route'      => array('default' => 1,       'type' => 'bool'),
		'friendly_url_enabled'    => array('default' => 0,       'type' => 'bool'),
		'require_https'           => array('default' => 0,       'type' => 'bool'),
		'allowed_networks'        => array('default' => '',      'type' => 'string'),
		'log_enabled'             => array('default' => 1,       'type' => 'bool'),
		'log_retention_days'      => array('default' => 30,      'type' => 'int'),
		'directory_enabled'       => array('default' => 1,       'type' => 'bool'),
		'directory_limit'         => array('default' => 200,     'type' => 'int'),
		'strict_rendering'        => array('default' => 0,       'type' => 'bool'),
	);

	/** @var \PDO */
	private $pdo;

	/** @var array|null Lazily loaded cache. */
	private $cache = null;

	public function __construct($pdo = null)
	{
		$this->pdo = $pdo === null ? Connection::get() : $pdo;
	}

	/**
	 * Every setting, defaults merged with stored values.
	 *
	 * @return array
	 */
	public function all()
	{
		if ($this->cache !== null) {
			return $this->cache;
		}

		$values = array();

		foreach (self::$definitions as $key => $definition) {
			$values[$key] = $this->cast($definition['default'], $definition['type']);
		}

		try {
			$statement = $this->pdo->query('SELECT `skey`, `svalue` FROM `' . Schema::TABLE_SETTINGS . '`');
			$rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			$rows = array();
		}

		foreach ($rows as $row) {
			$key = $row['skey'];

			if (!isset(self::$definitions[$key])) {
				continue;
			}

			$values[$key] = $this->cast($row['svalue'], self::$definitions[$key]['type']);
		}

		$this->cache = $values;

		return $this->cache;
	}

	/**
	 * Read a single setting.
	 *
	 * @param string $key
	 * @param mixed  $default Used only for unknown keys.
	 * @return mixed
	 */
	public function get($key, $default = null)
	{
		$all = $this->all();

		return array_key_exists($key, $all) ? $all[$key] : $default;
	}

	/**
	 * Write a single setting. Unknown keys are ignored.
	 */
	public function set($key, $value)
	{
		if (!isset(self::$definitions[$key])) {
			return false;
		}

		$value = $this->cast($value, self::$definitions[$key]['type']);
		$stored = is_bool($value) ? ($value ? '1' : '0') : (string) $value;

		// Update-then-insert keeps this portable and avoids a race producing a
		// duplicate key error on the primary key.
		$update = $this->pdo->prepare(
			'UPDATE `' . Schema::TABLE_SETTINGS . '` SET `svalue` = :v WHERE `skey` = :k'
		);
		$update->execute(array(':k' => $key, ':v' => $stored));

		if ($update->rowCount() === 0) {
			$exists = $this->pdo->prepare(
				'SELECT `skey` FROM `' . Schema::TABLE_SETTINGS . '` WHERE `skey` = :k'
			);
			$exists->execute(array(':k' => $key));

			if ($exists->fetch(\PDO::FETCH_NUM) === false) {
				$insert = $this->pdo->prepare(
					'INSERT INTO `' . Schema::TABLE_SETTINGS . '` (`skey`, `svalue`) VALUES (:k, :v)'
				);
				$insert->execute(array(':k' => $key, ':v' => $stored));
			}
		}

		if ($this->cache !== null) {
			$this->cache[$key] = $value;
		}

		return true;
	}

	/**
	 * Write many settings at once.
	 */
	public function setMany(array $values)
	{
		foreach ($values as $key => $value) {
			$this->set($key, $value);
		}

		return $this->all();
	}

	/**
	 * Definitions, so the admin interface can render defaults.
	 */
	public static function definitions()
	{
		return self::$definitions;
	}

	/**
	 * Forget the in-memory cache.
	 */
	public function refresh()
	{
		$this->cache = null;

		return $this->all();
	}

	private function cast($value, $type)
	{
		switch ($type) {
			case 'int':
				return (int) $value;
			case 'bool':
				return !empty($value) && $value !== '0';
			default:
				return (string) $value;
		}
	}
}
