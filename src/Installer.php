<?php

// src/Installer.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Installing and uninstalling the module.
 *
 * The tables, the repo directory, and the web-root symlink that gives a
 * phone a short URL. Nothing about the symlink fails the install: a module
 * that could not write to the web root is still a working module minus a
 * friendly URL.
 */
class Installer extends Service
{
	/** @var Schema */
	private $schema;

	/** @var FileRepo */
	private $files;

	/** @var LogRepo */
	private $logs;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Schema $schema, FileRepo $files, LogRepo $logs)
	{
		parent::__construct($freepbx);

		$this->schema = $schema;
		$this->files = $files;
		$this->logs = $logs;
	}

	/**
	 * Install the module.
	 *
	 * Every table is created if it is not already there, so installing over an
	 * existing install leaves the data where it is; what an older install is
	 * missing is added afterwards by Schema.
	 *
	 * @return bool True when installation completes.
	 */
	public function install()
	{
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->profilesTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`name` VARCHAR(191) NOT NULL,
				`enabled` TINYINT(1) NOT NULL DEFAULT 1,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `name` (`name`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// A resource is a filename, a type and what hangs off it. The name is
		// unique per profile rather than globally -- two profiles both serving a
		// `{{device.mac}}-phone.cfg` is the normal case, not a collision -- and is
		// 180 characters because it is half of a composite index that has to stay
		// inside the 767 bytes an older MySQL allows. There is no separate index on
		// profile_id: it is the left of the unique one.
		//
		// `type` is what the resource is, and the only thing that says so. 16
		// characters rather than an ENUM, so a fourth kind is a line in
		// Resources::TYPES rather than an ALTER. `file_size` is a fact about the
		// upload and never the thing that decides.
		//
		// The index on `name` alone is for the lookup a request with no client
		// behind it makes -- see Matcher::fileByName(). The unique key cannot serve
		// it: profile_id is its leftmost column.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->resourcesTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`profile_id` INT(11) NOT NULL,
				`name` VARCHAR(180) NOT NULL,
				`type` VARCHAR(16) NOT NULL DEFAULT 'template',
				`template` LONGTEXT NULL,
				`file_size` INT(10) UNSIGNED NULL DEFAULT NULL,
				`file_uploaded_at` DATETIME NULL DEFAULT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `profile_name` (`profile_id`, `name`),
				KEY `name` (`name`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// mac is nullable, so a client can be written before anybody has read the
		// label off the handset. NULL and not '' because the unique key beside it
		// counts NULLs as distinct and empty strings as equal -- see
		// Schema::relaxClientMacColumn().
		//
		// device_id is the FreePBX devices.id, a string column there and so a
		// string here rather than something cast on every join.
		//
		// token is a password_hash() of the token the client was given. No
		// plaintext column beside it and no index on it: it is verified against,
		// never looked up by.
		//
		// public_ip and private_ip are where the phone is, written down by whoever
		// set it up rather than discovered. private_ip is what the Clients list
		// draws its handset link from, which is why both are validated as addresses
		// on the way in -- see Clients::address().
		//
		// last_seen is on the client rather than derived from the provisioning log,
		// because the log is prunable and when a phone last checked in is the one
		// fact about a client that must survive its requests being thrown away.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->clientsTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`mac` VARCHAR(12) NULL DEFAULT NULL,
				`device_id` VARCHAR(20) NULL DEFAULT NULL,
				`profile_id` INT(11) NULL DEFAULT NULL,
				`token` VARCHAR(255) NULL DEFAULT NULL,
				`enabled` TINYINT(1) NOT NULL DEFAULT 1,
				`last_seen` DATETIME NULL DEFAULT NULL,
				`public_ip` VARCHAR(45) NULL DEFAULT NULL,
				`private_ip` VARCHAR(45) NULL DEFAULT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `mac` (`mac`),
				KEY `device_id` (`device_id`),
				KEY `profile_id` (`profile_id`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// One row per request the endpoint answered, written whether or not the MAC
		// is one this module knows. No client_id and no foreign key: a log row
		// outlives the client it was about and predates the one it was not.
		//
		// Metadata only. The rendered body carries device.secret whenever a
		// template asks for it, and a log is not where that belongs. Nor is which
		// resource answered -- the filename as the phone spelled it is the fact of
		// the request.
		//
		// `mac` is 64 rather than the clients table's 12 because it also holds what
		// was asked with when that was not a MAC at all.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->logsTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`mac` VARCHAR(64) NOT NULL DEFAULT '',
				`filename` VARCHAR(255) NOT NULL DEFAULT '',
				`status` SMALLINT(5) NOT NULL DEFAULT 0,
				`message` VARCHAR(255) NULL DEFAULT NULL,
				`method` VARCHAR(10) NOT NULL DEFAULT '',
				`ip` VARCHAR(45) NULL DEFAULT NULL,
				`user_agent` VARCHAR(255) NULL DEFAULT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				KEY `mac` (`mac`, `id`),
				KEY `created_at` (`created_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// CREATE TABLE IF NOT EXISTS does nothing to a table that is already there,
		// so every column added after a table first existed is added from here.
		// Order matters in one place: addResourceTypeColumn() backfills from
		// file_size, which addResourceFileColumns() is what adds.
		$this->schema->addResourceFileColumns();
		$this->schema->addResourceTypeColumn();
		$this->schema->addClientTokenColumn();
		$this->schema->addClientEnabledColumn();
		$this->schema->addProfileEnabledColumn();
		$this->schema->addClientLastSeenColumn();
		$this->schema->addClientAddressColumns();
		$this->schema->relaxClientMacColumn();

		$this->linkEngine();

		// Uploads have nowhere to go without it. Like linkEngine(), it fails
		// nothing, and the directory is made again on the first upload attempted.
		if (!$this->files->ensureRepo()) {
			$this->installMessage(sprintf(
				'Provisioner: could not create %s; resource uploads will not work until it exists.',
				$this->files->repoPath()
			));
		}

		// The same for the other direction. Made here so an operator is told at
		// install rather than by a phone being refused months later.
		if (!$this->logs->ensureLogs()) {
			$this->installMessage(sprintf(
				'Provisioner: could not create %s; logs a phone uploads will not be stored until it exists.',
				$this->logs->logPath()
			));
		}

		return true;
	}

	/**
	 * Uninstall the module.
	 *
	 * The tables are deliberately left in place; the symlink is not, since it
	 * would be left pointing into a directory that has gone.
	 *
	 * @return void
	 */
	public function uninstall()
	{
		$this->unlinkEngine();
	}

	/**
	 * Point a web-root symlink at the engine directory.
	 *
	 * The endpoint lives in engine/, under the module, which puts it at
	 * /admin/modules/oryk_provisioner/engine/ -- a URL no phone should be given,
	 * and a path under an /admin a hardened site may not serve anonymously. The
	 * link gives it a short public one instead:
	 *
	 *   /var/www/html/provisioner -> .../admin/modules/oryk_provisioner/engine
	 *   http(s)://<pbx>/provisioner/?mac=00908F3BBCBA
	 *
	 * A symlink rather than a copied shim, so there is one engine and nothing to
	 * keep in step across an upgrade. Apache has to be willing to follow it --
	 * Options FollowSymLinks on the web root, the FreePBX default.
	 *
	 * **Nothing in here fails the install**: a module that could not write to the
	 * web root is still a working module minus a friendly URL.
	 *
	 * @return bool True when the link is in place.
	 */
	private function linkEngine()
	{
		$engine = dirname(__DIR__) . '/engine';
		$link = $this->engineLinkPath();

		if (!is_dir($engine)) {
			$this->installMessage('Provisioner: no engine directory to link, skipped.');

			return false;
		}

		if (is_link($link)) {
			// Compared resolved rather than by the stored target: the link may have
			// been made through a path that is itself a link.
			if (realpath($link) === realpath($engine)) {
				return true;
			}

			// Ours to replace -- it is a link, not somebody's directory.
			@unlink($link);
		}

		// A real file or directory there belongs to someone else. A site that
		// already has its own /provisioner is not one to overwrite.
		if (file_exists($link)) {
			$this->installMessage("Provisioner: {$link} exists and is not a symlink, left alone.");

			return false;
		}

		if (!@symlink($engine, $link)) {
			$this->installMessage("Provisioner: could not create {$link}, the endpoint is still at admin/modules/oryk_provisioner/engine/.");

			return false;
		}

		// Apache follows the link as the owner of the target, so this changes
		// nothing about whether it works; it is here so FreePBX's file permission
		// pass finds what it expects under the web root.
		if (function_exists('lchown')) {
			$user = (string) $this->FreePBX->Config->get('AMPASTERISKWEBUSER');
			$group = (string) $this->FreePBX->Config->get('AMPASTERISKWEBGROUP');

			if ($user !== '') {
				@lchown($link, $user);
			}

			if ($group !== '') {
				@lchgrp($link, $group);
			}
		}

		return true;
	}

	/**
	 * Remove the web-root symlink.
	 *
	 * Only a link that resolves to this module's engine is removed: anything
	 * else at that path is someone else's, and an uninstall is not the moment
	 * to find that out the hard way.
	 *
	 * @return bool True when the link was removed.
	 */
	private function unlinkEngine()
	{
		$link = $this->engineLinkPath();

		if (!is_link($link) || realpath($link) !== realpath(dirname(__DIR__) . '/engine')) {
			return false;
		}

		return (bool) @unlink($link);
	}

	/**
	 * Full path of the web-root symlink.
	 *
	 * @return string Absolute path to the link.
	 */
	private function engineLinkPath()
	{
		$root = trim((string) $this->FreePBX->Config->get('AMPWEBROOT'));

		if ($root === '') {
			$root = '/var/www/html';
		}

		return rtrim($root, '/') . '/' . $this->engineLink;
	}

	/**
	 * Report something that happened during install or uninstall.
	 *
	 * fwconsole is where an operator is looking when a module is installed, so it
	 * is told first; anywhere else there is no out() and the log is all there is.
	 *
	 * @param string $message Message to report.
	 *
	 * @return void
	 */
	private function installMessage($message)
	{
		if (function_exists('out')) {
			out($message);

			return;
		}

		$this->log($message, null, 'INFO');
	}

	/**
	 * Create a module backup.
	 *
	 * @return void
	 */
	public function backup()
	{
	}

	/**
	 * Restore module data from a backup.
	 *
	 * @param mixed $backup Backup data.
	 *
	 * @return void
	 */
	public function restore($backup)
	{
	}
}
