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
	/**
	 * @var Schema
	 */
	private $schema;

	/**
	 * @var FileRepo
	 */
	private $files;

	/**
	 * @var LogRepo
	 */
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
	 * missing is added afterwards by Schema, which asks the database what is
	 * there rather than trusting a dbversion.
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

		// A resource is a filename and a block of text hanging off the profile
		// that serves it. Every file a profile serves is one of these. The name is
		// unique per profile rather than globally: two profiles both serving a
		// `{{device.mac}}-phone.cfg` is the normal case, not a collision.
		//
		// 180 rather than the 191 a profile name gets, because this one is
		// half of a composite index: 180 utf8mb4 characters plus the int is
		// 724 bytes, inside the 767 an older MySQL allows per index. No
		// filename a phone asks for comes close either way. There is no
		// separate index on profile_id -- it is the left of the unique one.
		//
		// `type` is what the resource is, and the only thing that says so:
		// template (rendered for the client that asked), file (handed over as
		// it was stored) or log (received from the phone rather than served to
		// it). It was inferred from file_size until 1.0.14 -- a size meant a
		// file -- which meant a resource could not say what it was until it
		// already was one, and could not be a log at any point. It is 16
		// characters and not an ENUM because adding a fourth kind should be a
		// line in Resources::TYPES rather than an ALTER.
		//
		// file_size is a fact about the upload now rather than the thing that
		// decides: it is what is on the disk, and it is null on a resource
		// declared a file that nobody has uploaded to yet -- which is an
		// unfinished resource, and answered as one.
		//
		// The index on `name` alone is for the lookup a request with no client
		// behind it makes -- firmware, asked for by the name the vendor fixed.
		// The unique key cannot serve it: profile_id is its leftmost column.
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

		// mac is what a phone is found by and is nullable, so a client can be
		// written before anybody has read the label off the handset. NULL and
		// not '' because the unique key beside it counts NULLs as distinct and
		// empty strings as equal -- see Schema::relaxClientMacColumn(). A
		// client without one is never reached: the endpoint reads the MAC out
		// of the path a phone asks with.
		//
		// device_id is the FreePBX devices.id, which is a string column there,
		// so it is a string here too rather than something that has to be cast
		// on every join.
		//
		// token is a password_hash() of the token the client was given,
		// wide enough for the longest hash that function has ever produced
		// rather than for the one it produces today. There is no plaintext
		// column beside it and no index on it: it is verified against, never
		// looked up by, and hashToken() says why.
		//
		// enabled is whether the endpoint answers this client at all. A row
		// is written enabled -- somebody adding a client is adding one to
		// serve -- and switching it off is a deliberate act taken afterwards,
		// on the client or from its row on the list.
		//
		// public_ip and private_ip are where the phone is, written down by
		// whoever set it up rather than discovered: nothing here reaches a
		// phone, so there is nothing that could fill them in. private_ip is
		// what the Clients list draws its link to the handset's own web
		// interface from, which is why both are validated as addresses on the
		// way in -- see Clients::address().
		//
		// last_seen is the last time the endpoint answered this client with a
		// 200, written by the request that was answered. It is on the client
		// rather than derived from the provisioning log beside it, because
		// the log is prunable -- there is a Clear button on two pages and a
		// retention policy still to come -- and when a phone last checked in
		// is the one fact about a client that must survive its requests being
		// thrown away. NULL until the first one: see
		// Schema::addClientLastSeenColumn().
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

		// One row per request the endpoint answered, written whether or not
		// the MAC is one this module knows. There is no client_id on it and
		// no foreign key: a log row outlives the client it was about and
		// predates the one it was not, so which client a MAC belongs to is a
		// question asked when the log is read rather than answered once here.
		//
		// Metadata only, and less of it than a log of this kind usually keeps.
		// The rendered body carries device.secret whenever a template asks for
		// it, and a log is not where that belongs. Nor is which resource
		// answered: the filename as the phone spelled it is the fact of the
		// request, and which file of which profile that reached is a question
		// the profile answers and can go on answering differently.
		//
		// `mac` is 64 rather than the clients table's 12 because this column
		// also has to hold what was asked with when what was asked with is not
		// a MAC at all -- on a row like that, "what did this thing send us" is
		// the whole question.
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

		// CREATE TABLE IF NOT EXISTS does nothing to a table that is already
		// there, so every column added after a table first existed is added from
		// here instead. Each step asks information_schema whether it has already
		// been done, so this is the same on a fresh install, an upgrade and a
		// reinstall. Order matters in one place: addResourceTypeColumn() backfills
		// from file_size, which addResourceFileColumns() is what adds.
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
		// nothing: a module that could not write to the spool is a working
		// module minus uploads, and the directory is made again on the first
		// one that is attempted.
		if (!$this->files->ensureRepo()) {
			$this->installMessage(sprintf(
				'Provisioner: could not create %s; resource uploads will not work until it exists.',
				$this->files->repoPath()
			));
		}

		// The same, for the other direction: a log resource has nowhere to put
		// what a phone PUTs without it. Made here so an operator is told at
		// install rather than by a phone being refused months later, and made
		// again on the first PUT that needs it.
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
	 * The client endpoint lives in engine/, under the module, which puts it at
	 * /admin/modules/oryk_provisioner/engine/ -- a URL no phone should have to
	 * be given, and a path under an /admin that a hardened site may well not
	 * serve to an anonymous caller at all. The link gives it a short public one
	 * instead:
	 *
	 *   /var/www/html/provisioner -> .../admin/modules/oryk_provisioner/engine
	 *   http(s)://<pbx>/provisioner/?mac=00908F3BBCBA
	 *
	 * A symlink rather than a copied shim, so there is one engine and nothing
	 * to keep in step across an upgrade. Apache has to be willing to follow it
	 * -- Options FollowSymLinks on the web root, which is the FreePBX default.
	 *
	 * Nothing in here fails the install. A module that could not write to the
	 * web root is still a working module minus a friendly URL, and the endpoint
	 * stays reachable at its real path either way.
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
			// Compared resolved rather than by the stored target: the link may
			// have been made through a path that is itself a link.
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
		// nothing about whether it works; it is here so FreePBX's file
		// permission pass finds what it expects under the web root.
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
	 * fwconsole is where an operator is actually looking when a module is
	 * installed, so it is told first; anywhere else (the GUI's module admin,
	 * a test harness) there is no out() and the log is the only place left.
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
