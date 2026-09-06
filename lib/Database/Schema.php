<?php

namespace Oryk\Provisioner\Database;

/**
 * Schema definition and migrations.
 *
 * install() is idempotent: it is safe to run on every module install/upgrade.
 * Table changes between versions are expressed as guarded ALTER statements in
 * migrate() so upgrades never lose device data.
 */
class Schema
{
	const TABLE_TEMPLATES = 'oryk_provisioner_templates';
	const TABLE_OUTPUTS   = 'oryk_provisioner_template_outputs';
	const TABLE_DEVICES   = 'oryk_provisioner_devices';
	const TABLE_LOGS      = 'oryk_provisioner_logs';
	const TABLE_SETTINGS  = 'oryk_provisioner_settings';

	/** @var \PDO */
	private $pdo;

	public function __construct($pdo = null)
	{
		$this->pdo = $pdo === null ? Connection::get() : $pdo;
	}

	/**
	 * Create every table the module needs, then apply pending migrations.
	 */
	public function install()
	{
		foreach ($this->definitions() as $sql) {
			$this->pdo->query($sql);
		}

		$this->migrate();
	}

	/**
	 * Drop every table the module owns.
	 */
	public function uninstall()
	{
		$tables = array(
			self::TABLE_LOGS,
			self::TABLE_OUTPUTS,
			self::TABLE_DEVICES,
			self::TABLE_TEMPLATES,
			self::TABLE_SETTINGS,
		);

		foreach ($tables as $table) {
			$this->pdo->query('DROP TABLE IF EXISTS `' . $table . '`');
		}
	}

	/**
	 * Guarded schema changes for upgrades from earlier releases.
	 */
	public function migrate()
	{
		$this->addColumn(self::TABLE_DEVICES, 'notes', 'TEXT NULL AFTER `parameters`');
		$this->addColumn(self::TABLE_DEVICES, 'last_provisioned_at', 'DATETIME NULL DEFAULT NULL');
		$this->addColumn(self::TABLE_DEVICES, 'last_provisioned_ip', 'VARCHAR(45) NULL DEFAULT NULL');
		$this->addColumn(self::TABLE_TEMPLATES, 'builtin', 'TINYINT(1) NOT NULL DEFAULT 0');
	}

	/**
	 * Does a table exist?
	 */
	public function tableExists($table)
	{
		try {
			$statement = $this->pdo->query('SHOW TABLES LIKE ' . $this->pdo->quote($table));
			$row = $statement->fetch(\PDO::FETCH_NUM);

			return !empty($row);
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * Add a column when it is not already present.
	 */
	private function addColumn($table, $column, $definition)
	{
		if (!$this->tableExists($table)) {
			return;
		}

		try {
			$statement = $this->pdo->query(
				'SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->pdo->quote($column)
			);

			if ($statement->fetch(\PDO::FETCH_NUM)) {
				return;
			}

			$this->pdo->query('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition);
		} catch (\Exception $e) {
			// A failed optional migration must never block a module install.
		}
	}

	/**
	 * @return array CREATE TABLE statements.
	 */
	private function definitions()
	{
		$engine = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

		return array(
			'CREATE TABLE IF NOT EXISTS `' . self::TABLE_TEMPLATES . '` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`slug` VARCHAR(100) NOT NULL,
				`name` VARCHAR(190) NOT NULL,
				`vendor` VARCHAR(60) NOT NULL DEFAULT "generic",
				`family` VARCHAR(60) NOT NULL DEFAULT "",
				`description` TEXT NULL,
				`defaults` LONGTEXT NULL,
				`parameters` LONGTEXT NULL,
				`enabled` TINYINT(1) NOT NULL DEFAULT 1,
				`builtin` TINYINT(1) NOT NULL DEFAULT 0,
				`created_at` DATETIME NULL DEFAULT NULL,
				`updated_at` DATETIME NULL DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `oryk_prov_template_slug` (`slug`),
				KEY `oryk_prov_template_vendor` (`vendor`)
			) ' . $engine,

			'CREATE TABLE IF NOT EXISTS `' . self::TABLE_OUTPUTS . '` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`template_id` INT UNSIGNED NOT NULL,
				`filename` VARCHAR(190) NOT NULL,
				`content_type` VARCHAR(100) NOT NULL DEFAULT "text/plain",
				`body` LONGTEXT NULL,
				`sort_order` INT NOT NULL DEFAULT 0,
				PRIMARY KEY (`id`),
				KEY `oryk_prov_output_template` (`template_id`)
			) ' . $engine,

			'CREATE TABLE IF NOT EXISTS `' . self::TABLE_DEVICES . '` (
				`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
				`identifier` VARCHAR(100) NOT NULL,
				`name` VARCHAR(190) NOT NULL,
				`template_id` INT UNSIGNED NULL DEFAULT NULL,
				`extension` VARCHAR(50) NOT NULL DEFAULT "",
				`mac` VARCHAR(20) NOT NULL DEFAULT "",
				`vendor` VARCHAR(60) NOT NULL DEFAULT "",
				`model` VARCHAR(60) NOT NULL DEFAULT "",
				`enabled` TINYINT(1) NOT NULL DEFAULT 1,
				`token` VARCHAR(128) NOT NULL,
				`parameters` LONGTEXT NULL,
				`notes` TEXT NULL,
				`last_provisioned_at` DATETIME NULL DEFAULT NULL,
				`last_provisioned_ip` VARCHAR(45) NULL DEFAULT NULL,
				`created_at` DATETIME NULL DEFAULT NULL,
				`updated_at` DATETIME NULL DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `oryk_prov_device_identifier` (`identifier`),
				UNIQUE KEY `oryk_prov_device_token` (`token`),
				KEY `oryk_prov_device_template` (`template_id`),
				KEY `oryk_prov_device_extension` (`extension`),
				KEY `oryk_prov_device_mac` (`mac`)
			) ' . $engine,

			'CREATE TABLE IF NOT EXISTS `' . self::TABLE_LOGS . '` (
				`id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				`created_at` DATETIME NOT NULL,
				`device_id` INT UNSIGNED NULL DEFAULT NULL,
				`identifier` VARCHAR(100) NOT NULL DEFAULT "",
				`filename` VARCHAR(190) NOT NULL DEFAULT "",
				`status` VARCHAR(20) NOT NULL DEFAULT "",
				`http_code` SMALLINT NOT NULL DEFAULT 0,
				`message` VARCHAR(255) NOT NULL DEFAULT "",
				`ip` VARCHAR(45) NOT NULL DEFAULT "",
				`user_agent` VARCHAR(255) NOT NULL DEFAULT "",
				PRIMARY KEY (`id`),
				KEY `oryk_prov_log_created` (`created_at`),
				KEY `oryk_prov_log_device` (`device_id`)
			) ' . $engine,

			'CREATE TABLE IF NOT EXISTS `' . self::TABLE_SETTINGS . '` (
				`skey` VARCHAR(100) NOT NULL,
				`svalue` LONGTEXT NULL,
				PRIMARY KEY (`skey`)
			) ' . $engine,
		);
	}
}
