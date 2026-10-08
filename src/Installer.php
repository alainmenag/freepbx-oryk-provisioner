<?php

// src/Installer.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Installing and uninstalling the module.
 *
 * The tables, the module's settings, the repo directory, the web-root symlink
 * that gives a phone a short URL, the fail2ban sync -- its minute job and,
 * when this runs as root, its helper -- open provisioning's minute sweep
 * and Realtime bridge, and the service job worker's minute run. Nothing about the symlink, the sync or the bridge fails
 * the install: without them the module still works, minus a friendly URL,
 * fail2ban, or sign-ups that register before Apply Config.
 */
class Installer extends Service
{
	/** @var Schema */
	private $schema;

	/** @var FileRepo */
	private $files;

	/** @var LogRepo */
	private $logs;

	/** @var Settings */
	private $settings;

	/** @var Fail2ban */
	private $fail2ban;

	/** @var RealtimeBridge|null */
	private $bridge;

	/** @var Services|null */
	private $services;

	/** The FreePBX job the minute sync is registered as. */
	const SYNC_JOB = 'fail2ban-sync';

	/** The FreePBX job the open-provisioning sweep is registered as. */
	const SWEEP_JOB = 'signup-sweep';

	/** The FreePBX job the service job worker's minute run is registered as. */
	const JOBS_JOB = 'service-jobs';

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Schema $schema, FileRepo $files, LogRepo $logs, Settings $settings, Fail2ban $fail2ban, ?RealtimeBridge $bridge = null, ?Services $services = null)
	{
		parent::__construct($freepbx);

		$this->schema = $schema;
		$this->files = $files;
		$this->logs = $logs;
		$this->settings = $settings;
		$this->fail2ban = $fail2ban;
		$this->bridge = $bridge;
		$this->services = $services;
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
		//
		// state and signup_ip are open provisioning's -- see
		// Schema::addClientSignupColumns().
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
				`state` VARCHAR(16) NOT NULL DEFAULT 'provisioned',
				`signup_ip` VARCHAR(45) NULL DEFAULT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `mac` (`mac`),
				KEY `device_id` (`device_id`),
				KEY `profile_id` (`profile_id`),
				KEY `signup_ip_created` (`signup_ip`, `created_at`),
				KEY `created_at` (`created_at`),
				KEY `state` (`state`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// One row per request the endpoint answered, written whether or not the MAC
		// is one this module knows. No client_id and no foreign key: a log row
		// predates the client it is about; a deleted client takes its rows by MAC.
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

		// One rule per row: up to five subjects in the spelling Bans::value()
		// stores, and a state. "Any" is 0 or '' and never NULL, so the unique key
		// over the five admits one row per set of subjects -- MySQL counts NULLs
		// as distinct, and would admit any number. Only a `banned` row has an
		// expires_at. `source` and `jail` are what created the row, set once.
		// `hits` counts the requests the row decided. `started_at`, `times` and
		// `synced_at` are the current ban period, how often it came back into
		// force, and when fail2ban last had it; `managed` says the fail2ban sync
		// keeps the row in step with fail2ban's own ban; `deleted_at` marks a row
		// deleted but kept until its copy in fail2ban is lifted. See
		// ARCHITECTURE.md, "Bans".
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->bansTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`client_id` INT(11) NOT NULL DEFAULT 0,
				`extension` VARCHAR(20) NOT NULL DEFAULT '',
				`mac` VARCHAR(12) NOT NULL DEFAULT '',
				`profile_id` INT(11) NOT NULL DEFAULT 0,
				`ip` VARCHAR(45) NOT NULL DEFAULT '',
				`state` VARCHAR(16) NOT NULL DEFAULT 'banned',
				`expires_at` DATETIME NULL DEFAULT NULL,
				`note` VARCHAR(255) NULL DEFAULT NULL,
				`source` VARCHAR(32) NOT NULL DEFAULT 'manual',
				`jail` VARCHAR(64) NULL DEFAULT NULL,
				`hits` INT(10) UNSIGNED NOT NULL DEFAULT 0,
				`last_hit_at` DATETIME NULL DEFAULT NULL,
				`started_at` DATETIME NULL DEFAULT NULL,
				`times` INT(10) UNSIGNED NOT NULL DEFAULT 1,
				`synced_at` DATETIME NULL DEFAULT NULL,
				`managed` TINYINT(1) NOT NULL DEFAULT 0,
				`deleted_at` DATETIME NULL DEFAULT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `scope` (`client_id`, `extension`, `mac`, `profile_id`, `ip`),
				KEY `extension` (`extension`),
				KEY `mac` (`mac`),
				KEY `profile_id` (`profile_id`),
				KEY `ip` (`ip`),
				KEY `expires_at` (`expires_at`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->servicesTable}` (
				`slug` VARCHAR(64) NOT NULL,
				`name` VARCHAR(191) NOT NULL,
				`owner` INT UNSIGNED NOT NULL DEFAULT 0,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`slug`),
				UNIQUE KEY `name` (`name`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// Both ends are slugs, not ids. The pair is the key, so a link exists
		// once; `child` has an index of its own for the walk upwards, which the
		// key's left column cannot serve.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->serviceLinksTable}` (
				`parent` VARCHAR(64) NOT NULL,
				`child` VARCHAR(64) NOT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`parent`, `child`),
				KEY `child` (`child`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// A user is its extension and a service its slug, as everywhere else.
		// `service` has an index of its own: a renamed or deleted service is
		// looked up by it.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->serviceAssignmentsTable}` (
				`extension` VARCHAR(20) NOT NULL,
				`service` VARCHAR(64) NOT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`extension`, `service`),
				KEY `service` (`service`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// One change to what one user holds. `service` and `name` are the
		// service the change was made to, the name a snapshot: a deleted
		// service's job still says what it was. An upgrade's job names none.
		// See ARCHITECTURE.md, "Jobs".
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->jobsTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`extension` VARCHAR(20) NOT NULL,
				`service` VARCHAR(64) NOT NULL DEFAULT '',
				`name` VARCHAR(191) NOT NULL DEFAULT '',
				`reason` VARCHAR(16) NOT NULL,
				`source` VARCHAR(16) NOT NULL DEFAULT 'gui',
				`state` VARCHAR(16) NOT NULL DEFAULT 'queued',
				`attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
				`error` TEXT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`started_at` DATETIME NULL DEFAULT NULL,
				`finished_at` DATETIME NULL DEFAULT NULL,
				PRIMARY KEY (`id`),
				KEY `extension_state` (`extension`, `state`, `id`),
				KEY `state_finished` (`state`, `finished_at`),
				KEY `service` (`service`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// A job's services, in the order they run: revokes first. `via` is the
		// assigned service it came or went through, NULL for the service itself;
		// `done_by` the handlers that have finished it, comma-separated rawnames;
		// `own_job` the src/Jobs/ class this module ran for it, NULL for none.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->jobStepsTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`job_id` INT(11) NOT NULL,
				`position` SMALLINT(5) UNSIGNED NOT NULL,
				`service` VARCHAR(64) NOT NULL,
				`name` VARCHAR(191) NOT NULL DEFAULT '',
				`via` VARCHAR(64) NULL DEFAULT NULL,
				`event` VARCHAR(8) NOT NULL,
				`state` VARCHAR(16) NOT NULL DEFAULT 'pending',
				`done_by` VARCHAR(255) NOT NULL DEFAULT '',
				`own_job` VARCHAR(64) NULL DEFAULT NULL,
				`attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
				`error` TEXT NULL,
				`finished_at` DATETIME NULL DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `job_position` (`job_id`, `position`),
				KEY `service` (`service`),
				KEY `via` (`via`)
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
		$this->schema->addCoreIndexes();
		$this->schema->addBanSyncColumns();
		$this->schema->addClientSignupColumns();
		$this->schema->addServiceSlugColumn();
		$this->schema->addServiceOwnerColumn(array_keys(Services::DEFAULTS));
		$this->schema->addJobStepOwnJobColumn();

		// The services the module ships -- Services::DEFAULTS -- deleted and
		// written again to match. After the slug column, which they are found
		// by, and the owner column, which says which rows are the module's.
		// A regrouping that changes what users hold queues their jobs, which
		// the minute job runs: an install may be root, and jobs run as the web user.
		if ($this->services) {
			foreach ($this->services->seed() as $line) {
				$this->installMessage('Provisioner: ' . $line);
			}
		}

		// After seed(), which is what gives every older row the slug this keys on.
		if (!$this->schema->dropServiceIdColumn()) {
			$this->installMessage('Provisioner: the services table could not be re-keyed by slug; see the FreePBX log.');
		}

		// Advanced Settings -> Oryk Provisioner. Registering again on an upgrade
		// keeps whatever is set there.
		if (!$this->settings->register()) {
			$this->installMessage('Provisioner: could not register every module setting; see the FreePBX log.');
		}

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

		$this->registerJob(self::SYNC_JOB, 'oryk-fail2ban-sync', 'the fail2ban sync');
		$this->registerJob(self::SWEEP_JOB, 'oryk-signup-sweep', 'the open-provisioning sweep');
		$this->registerJob(self::JOBS_JOB, 'oryk-jobs', 'the service job worker');
		$this->setUpFail2ban();

		// Like the symlink, nothing about the bridge fails the install: without
		// it a sign-up works from the next Apply Config.
		if ($this->bridge) {
			foreach ($this->bridge->install() as $line) {
				$this->installMessage('Provisioner: ' . $line);
			}
		}

		return true;
	}

	/**
	 * Uninstall the module.
	 *
	 * The tables are deliberately left in place; the symlink is not, since it
	 * would be left pointing into a directory that has gone, and nor are the
	 * minute jobs, the bridge's lines in Asterisk's config, the dashboard
	 * notices and, when this runs as root, the fail2ban helper, its sudo rule
	 * and the banned and deny jails.
	 *
	 * @return void
	 */
	public function uninstall()
	{
		$this->unlinkEngine();

		foreach ([self::SYNC_JOB, self::SWEEP_JOB, self::JOBS_JOB] as $job) {
			try {
				$this->FreePBX->Job->remove('oryk_provisioner', $job);
			} catch (\Throwable $e) {
				// No Job BMO: nothing was registered.
			}
		}

		if ($this->bridge) {
			$this->bridge->uninstall();
		}

		$notices = new Notices($this->FreePBX);
		$notices->clear(Notices::OPEN_CAP);
		$notices->clear(Notices::BRIDGE_STALE);

		if ($this->runningAsRoot()) {
			foreach ($this->fail2ban->runSetup(['--remove']) as $line) {
				$this->installMessage('Provisioner: ' . $line);
			}
		}
	}

	/**
	 * Register one of bin/'s minute jobs with FreePBX's scheduler.
	 *
	 * Removed and added again on every install, so the path in it is this
	 * install's. It runs as the web user, like every FreePBX job; the sync
	 * reaches fail2ban through the sudo helper.
	 *
	 * @param string $job    Job name, one of the *_JOB constants.
	 * @param string $script File name under bin/.
	 * @param string $what   What it is, for the message when it cannot be.
	 *
	 * @return void
	 */
	private function registerJob($job, $script, $what)
	{
		$php = $this->phpBinary();
		$path = (realpath(dirname(__DIR__) . '/bin') ?: dirname(__DIR__) . '/bin') . '/' . $script;

		try {
			$jobs = $this->FreePBX->Job;
			$jobs->remove('oryk_provisioner', $job);
			$jobs->addCommand('oryk_provisioner', $job, escapeshellarg($php) . ' ' . escapeshellarg($path), '* * * * *', 50);
		} catch (\Throwable $e) {
			$this->installMessage('Provisioner: could not schedule ' . $what . ': ' . $e->getMessage());
		}
	}

	/**
	 * Install or update the fail2ban helper, which only root can do.
	 *
	 * As root -- `fwconsole ma install/upgrade` -- this runs the same setup
	 * script an operator would and passes on what it said. Otherwise it says
	 * what to run, unless the helper is already in place and current. Not at
	 * all while the sync is switched off.
	 *
	 * @return void
	 */
	private function setUpFail2ban()
	{
		if (!$this->fail2ban->enabled()) {
			return;
		}

		if ($this->runningAsRoot()) {
			foreach ($this->fail2ban->runSetup([]) as $line) {
				$this->installMessage('Provisioner: ' . $line);
			}

			return;
		}

		if (!in_array($this->fail2ban->status()['state'], ['ok', 'fail2ban'], true)) {
			$this->installMessage('Provisioner: to sync bans with fail2ban, run as root: ' . $this->fail2ban->setupCommand());
		}
	}

	/**
	 * Whether this process is root, which writing to /etc needs.
	 *
	 * @return bool True when it is.
	 */
	private function runningAsRoot()
	{
		return function_exists('posix_geteuid') && posix_geteuid() === 0;
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
