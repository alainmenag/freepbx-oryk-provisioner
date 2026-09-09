<?php

// Oryk_provisioner.class.php

namespace FreePBX\modules;

use BMO;
use PDO;
use FreePBX_Helpers;

class Oryk_provisioner extends FreePBX_Helpers implements \BMO
{
	/**
	 * Table holding the clients: a MAC, the FreePBX device it stands for and
	 * the profile it is served.
	 *
	 * @var string
	 */
	private $clientsTable = 'oryk_provisioner_clients';

	/**
	 * Table holding the provisioning profiles.
	 *
	 * @var string
	 */
	private $profilesTable = 'oryk_provisioner_profiles';

	/**
	 * Table holding the files a profile serves, the main config included.
	 *
	 * @var string
	 */
	private $resourcesTable = 'oryk_provisioner_resources';

	/**
	 * Table holding one row per request the provisioning endpoint answered.
	 *
	 * @var string
	 */
	private $logsTable = 'oryk_provisioner_logs';

	/**
	 * Name of the web-root symlink that points at the engine directory.
	 *
	 * @var string
	 */
	private $engineLink = 'provisioner';

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
	 * Create an Oryk provisioner module instance.
	 *
	 * @param object|null $freepbx FreePBX application instance.
	 *
	 * @throws \Exception If no FreePBX instance is provided.
	 */
	public function __construct($freepbx = null)
	{
		if ($freepbx == null) {
			throw new \Exception('Not given a FreePBX Object');
		}

		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;
	}

	public function log(mixed $message = '', mixed $data = '', $level = 'DEBUG')
	{
		$constant = 'FPBX_LOG_' . $level;
		$data = is_string($data) ? $data : ($data ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
		try {
			$l = defined($constant) ? constant($constant) : $level;
			$this->FreePBX->Logger->log($l, trim($message . ' ' . $data));
		} catch (\Throwable $e) {
			// Nowhere to report it that is not the thing that just failed.
			error_log($e->getMessage());
		}
	}

	/**
	 * Render the requested module page.
	 *
	 * A list and three editors, told apart by which key the URL carries.
	 *
	 *   ?display=oryk_provisioner                            the list
	 *   ?display=oryk_provisioner&client=<id>                one client
	 *   ?display=oryk_provisioner&client=                    a new one
	 *   ?display=oryk_provisioner&profile=<id>               one profile
	 *   ?display=oryk_provisioner&profile=                   a new one
	 *   ?display=oryk_provisioner&profile=<id>&resource=<id> one of its files
	 *   ?display=oryk_provisioner&profile=<id>&resource=     a new one
	 *
	 * A key present but empty is deliberate rather than a degenerate case: it
	 * is the same page doing the same thing, minus a row to replace.
	 *
	 * Everything the module edits is a page. A client was a dialog on
	 * the list until 1.0.6 -- three short fields do fit in one -- but a dialog
	 * has no address, so nothing could link to a client, and the profile
	 * editor's Clients tab had to send an id back to the list and have JS
	 * re-open the dialog on arrival. One way in, addressable, like the rest.
	 *
	 * @return string Rendered page output.
	 */
	public function showPage()
	{
		// Checked before ?profile= only because neither URL carries the
		// other's key: a client names its profile in a select, not in
		// the address.
		if (isset($_REQUEST['client'])) {
			return $this->showClient(trim((string) $_REQUEST['client']), (string) ($_REQUEST['tab'] ?? ''));
		}

		if (!isset($_REQUEST['profile'])) {
			return $this->showList();
		}

		$wanted = trim((string) $_REQUEST['profile']);
		$profile = ['id' => 0, 'name' => ''];

		if ($wanted !== '') {
			$found = $this->profileRow($wanted);

			// doConfigPageInit() has already sent an id that names nothing
			// back to the list, so this is only reachable if the profile went
			// between that check and here; the list is where it is not.
			if (!$found) {
				return $this->showList('profiles');
			}

			$profile = $found;
		}

		$tab = (string) ($_REQUEST['tab'] ?? '');

		// ?profile=<id>&resource=<id> is one of that profile's resources, and
		// ?profile=<id>&resource= is a new one -- the same shape ?profile= has
		// itself, one level down. A resource carries a block of configuration
		// text that wants room and a monospace column, so it gets a page
		// rather than a dialog on the profile's Resources tab. Since the
		// profile stopped carrying text of its own, this is the only page in
		// the module with a template box on it.
		if ($profile['id'] && isset($_REQUEST['resource'])) {
			$page = $this->showResource($profile, trim((string) $_REQUEST['resource']), $tab);

			if ($page !== null) {
				return $page;
			}

			// The resource went between doConfigPageInit()'s check and here.
			// The profile it would have belonged to is where it is not.
			$tab = 'resources';
		}

		// Resources and Clients both hang off a profile that has been
		// written, so a new one opens on Profile whichever tab is asked for.
		$tabs = ['resources', 'clients'];

		return load_view(__DIR__ . '/views/profile.php', [
			'profile' => $profile,
			'assigned' => $profile['id'] ? $this->profileClientCount((int) $profile['id']) : 0,
			'tab' => (in_array($tab, $tabs, true) && $profile['id']) ? $tab : 'profile',
			'saved' => (int) ($_REQUEST['saved'] ?? 0),
		]);
	}

	/**
	 * Render the client editor.
	 *
	 * What both selects offer is rendered with the page rather than fetched:
	 * it is a list of FreePBX devices and a list of profiles, and the page is
	 * already waiting on the module for the client itself.
	 *
	 * Resources is the other end of the resource editor's Clients tab: the
	 * files this client's profile serves, each with the filename *this* phone
	 * asks for. One client's provisioning, listed where the client
	 * is -- which is where somebody debugging a phone is already looking.
	 *
	 * @param string $wanted Client id, or '' for a new one.
	 * @param string $tab    Tab to open on: client|resources.
	 *
	 * @return string Rendered page output.
	 */
	private function showClient($wanted, $tab = '')
	{
		$client = ['id' => 0, 'mac' => '', 'device_id' => '', 'profile_id' => 0];

		if ($wanted !== '') {
			$found = $this->clientRow($wanted);

			// doConfigPageInit() has already sent an id that names nothing
			// back to the list, so this is only reachable if the client
			// went between that check and here; the list is where it is not.
			if (!$found) {
				return $this->showList('clients');
			}

			$client = $found;
		}

		$profileId = (int) ($client['profile_id'] ?? 0);
		$mac = (string) ($client['mac'] ?? '');

		// What each tab needs before it has anything on it. Resources needs a
		// profile behind it -- nothing is served to a client without one. Logs
		// needs only a MAC to have been asked about, and deliberately does not
		// need the profile: a client with no profile is exactly the one whose
		// phone is being refused, and those refusals are what its log is made
		// of.
		$available = [
			'resources' => (bool) ($client['id'] && $profileId),
			'logs' => (bool) ($client['id'] && $mac !== ''),
		];

		return load_view(__DIR__ . '/views/client.php', [
			'client' => $client,
			'freepbxDevices' => $this->freepbxDevices(),
			'profiles' => $this->profileChoices(),
			'resources' => $profileId ? $this->profileResourceCount($profileId) : 0,
			'logs' => $mac !== '' ? $this->macLogCount($mac) : 0,
			'available' => $available,
			'tab' => !empty($available[$tab]) ? $tab : 'client',
		]);
	}

	/**
	 * Render the resource editor.
	 *
	 * The same page as the profile editor in everything but which two columns
	 * it is bound to, which is why both are one view apiece over a shared
	 * partial rather than one view with a mode flag.
	 *
	 * Clients is the profile's own client list, narrowed no further and
	 * widened by one column: which filename each of them asks *this* resource
	 * for. A resource's name is a template, so that filename is a different
	 * string per client -- which is exactly why there was no per-resource
	 * preview until there was a per-client row to hang one on.
	 *
	 * @param array<string, mixed> $profile Profile the resource belongs to.
	 * @param string               $wanted  Resource id, or '' for a new one.
	 * @param string               $tab     Tab to open on: resource|clients.
	 *
	 * @return string|null Rendered page, or null when the id names nothing.
	 */
	private function showResource(array $profile, $wanted, $tab = '')
	{
		$resource = ['id' => 0, 'profile_id' => (int) $profile['id'], 'name' => '', 'template' => ''];

		if ($wanted !== '') {
			$found = $this->resourceRow($wanted, (int) $profile['id']);

			if (!$found) {
				return null;
			}

			$resource = $found;
		}

		return load_view(__DIR__ . '/views/resource.php', [
			'resource' => $resource,
			'profile' => $profile,
			'placeholders' => $this->templatePlaceholders(),
			'assigned' => $this->profileClientCount((int) $profile['id']),
			// So the upload control can say what it will accept before
			// somebody finds out at the end of a forty-megabyte upload.
			'uploadLimit' => $this->uploadLimit(),
			// A resource that has never been written has no name to render
			// against a client, so Clients is there but does not open --
			// the same way Resources is on a new profile.
			'tab' => ($tab === 'clients' && $resource['id']) ? 'clients' : 'resource',
		]);
	}

	/**
	 * Render the list page.
	 *
	 * Both tables are filled over AJAX and neither row is edited here, so all
	 * the view is handed is which tab to open on and which row the editor it
	 * came back from has just written.
	 *
	 * One `saved` for both tables: each editor returns to its own tab, so the
	 * tab it arrives on says which table the id belongs to.
	 *
	 * @param string|null $tab Tab to open on, or null to take it from the request.
	 *
	 * @return string Rendered page output.
	 */
	private function showList($tab = null)
	{
		$tab = $tab === null ? (string) ($_REQUEST['tab'] ?? '') : $tab;

		return load_view(__DIR__ . '/views/admin.php', [
			'tab' => in_array($tab, ['profiles', 'logs'], true) ? $tab : 'clients',
			'saved' => (int) ($_REQUEST['saved'] ?? 0),
		]);
	}

	/**
	 * Buttons FreePBX draws in the page header.
	 *
	 * Only the editors have any: the list's two tabs each carry their own Add,
	 * and a single button in the header could not say which tab it meant.
	 *
	 * Deliberately not the usual submit/delete names -- those are wired by
	 * core to a `form.fpbx-submit`, and none of these pages has a form: a row
	 * is saved over AJAX, not posted. These are ours, and
	 * views/partials/editor.php binds them.
	 *
	 * @param string $request Current page request.
	 *
	 * @return array<string, array<string, string>> Action bar buttons.
	 */
	public function getActionBar($request)
	{
		// Which editor is open, and which of the URL's keys names the row its
		// buttons act on.
		if (isset($_REQUEST['client'])) {
			$row = trim((string) $_REQUEST['client']);
		} elseif (isset($_REQUEST['profile'])) {
			// On a resource page it is the resource that Save and Delete act
			// on; the profile in the URL is only what it hangs off.
			$row = isset($_REQUEST['resource'])
				? trim((string) $_REQUEST['resource'])
				: trim((string) $_REQUEST['profile']);
		} else {
			return [];
		}

		$bar = [
			'oryksave' => [
				'name' => 'oryksave',
				'id' => 'oryksave',
				'value' => _('Save'),
			],
		];

		// Nothing to delete until there is a row: on a new profile, or a new
		// resource, the button would refer to something never written.
		if ($row !== '') {
			$bar['orykdelete'] = [
				'name' => 'orykdelete',
				'id' => 'orykdelete',
				'value' => _('Delete'),
			];
		}

		$bar['orykclose'] = [
			'name' => 'orykclose',
			'id' => 'orykclose',
			'value' => _('Close'),
		];

		return $bar;
	}

	/**
	 * Install the module.
	 *
	 * Every table is created if it is not already there, so installing over an
	 * existing install leaves the data where it is;
	 *
	 * @return bool True when installation completes.
	 */
	public function install()
	{
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->profilesTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`name` VARCHAR(191) NOT NULL,
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
		// file_size is the whole of what says a resource is an uploaded file
		// rather than a template. There is no `type` column: only an upload can
		// set the size, and a column that says the same thing twice is a column
		// that can disagree with itself. The template stays underneath a file
		// rather than being cleared by it, so removing the file leaves the
		// resource the template it was.
		//
		// The index on `name` alone is for the lookup a request with no client
		// behind it makes -- firmware, asked for by the name the vendor fixed.
		// The unique key cannot serve it: profile_id is its leftmost column.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->resourcesTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`profile_id` INT(11) NOT NULL,
				`name` VARCHAR(180) NOT NULL,
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

		// device_id is the FreePBX devices.id, which is a string column there,
		// so it is a string here too rather than something that has to be cast
		// on every join.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->clientsTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`mac` VARCHAR(12) NOT NULL,
				`device_id` VARCHAR(20) NULL DEFAULT NULL,
				`profile_id` INT(11) NULL DEFAULT NULL,
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
		// there, so a site upgrading to 1.0.6 gets the two file columns and the
		// name index from here instead.
		$this->addResourceFileColumns();

		$this->linkEngine();

		// Uploads have nowhere to go without it. Like linkEngine(), it fails
		// nothing: a module that could not write to the spool is a working
		// module minus uploads, and the directory is made again on the first
		// one that is attempted.
		if (!$this->ensureRepo()) {
			$this->installMessage(sprintf(
				'Provisioner: could not create %s; resource uploads will not work until it exists.',
				$this->repoPath()
			));
		}

		return true;
	}

	/**
	 * Bring a resources table written before 1.0.6 up to date.
	 *
	 * Asked of information_schema rather than tried and caught: a failed DDL
	 * statement is not something a PDO exception cleanly tells apart from a
	 * connection that has gone, on every MySQL build this has to run on. Not
	 * gated on dbversion either -- the question that matters is whether the
	 * column is there, and asking it directly answers the same whether the
	 * module arrived here by upgrade, by reinstall or by a restore.
	 *
	 * @return void
	 */
	private function addResourceFileColumns()
	{
		$columns = [
			'file_size' => 'ADD COLUMN `file_size` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `template`',
			'file_uploaded_at' => 'ADD COLUMN `file_uploaded_at` DATETIME NULL DEFAULT NULL AFTER `file_size`',
		];

		foreach ($columns as $column => $clause) {
			if (!$this->hasColumn($this->resourcesTable, $column)) {
				$this->db->exec("ALTER TABLE `{$this->resourcesTable}` $clause");
			}
		}

		if (!$this->hasIndex($this->resourcesTable, 'name')) {
			$this->db->exec("ALTER TABLE `{$this->resourcesTable}` ADD KEY `name` (`name`)");
		}
	}

	/**
	 * Whether a table already has a column.
	 *
	 * A question that cannot be answered is answered yes, because the only
	 * thing the answer is used for is deciding whether to ALTER: not knowing
	 * is a reason to leave the table alone, not to change it blind.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 *
	 * @return bool True when it is there, or when it could not be asked.
	 */
	private function hasColumn($table, $column)
	{
		try {
			$stmt = $this->db->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
			);
			$stmt->execute([':table' => $table, ':column' => $column]);

			return (bool) $stmt->fetchColumn();
		} catch (\Exception $e) {
			$this->log('oryk_provisioner: could not read information_schema', $e->getMessage(), 'WARNING');

			return true;
		}
	}

	/**
	 * Whether a table already has an index by that name.
	 *
	 * @param string $table Table name.
	 * @param string $index Index name.
	 *
	 * @return bool True when it is there, or when it could not be asked.
	 */
	private function hasIndex($table, $index)
	{
		try {
			$stmt = $this->db->prepare(
				'SELECT COUNT(*) FROM information_schema.STATISTICS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index'
			);
			$stmt->execute([':table' => $table, ':index' => $index]);

			return (bool) $stmt->fetchColumn();
		} catch (\Exception $e) {
			$this->log('oryk_provisioner: could not read information_schema', $e->getMessage(), 'WARNING');

			return true;
		}
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
		$engine = __DIR__ . '/engine';
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

		if (!is_link($link) || realpath($link) !== realpath(__DIR__ . '/engine')) {
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

	/**
	 * Initialise the module configuration page.
	 *
	 * An id that names no row is a stale link or a hand-edited URL: opening an
	 * editor on it would bind the fields to something that cannot be saved
	 * back. It is sent to the list instead, and it is done here because this
	 * runs before any of the page has been written -- a redirect out of
	 * showPage() would be too late to set a header.
	 *
	 * @param string $page Current configuration page.
	 *
	 * @return void
	 */
	public function doConfigPageInit($page)
	{
		// Empty is the new-client editor, not a lookup that failed.
		if (isset($_REQUEST['client'])) {
			$client = trim((string) $_REQUEST['client']);

			if ($client !== '' && (!ctype_digit($client) || !$this->clientRow($client))) {
				header('Location: config.php?display=oryk_provisioner&tab=clients');
				exit;
			}

			return;
		}

		if (!isset($_REQUEST['profile'])) {
			return;
		}

		$wanted = trim((string) $_REQUEST['profile']);

		// Empty is the new-profile editor, not a lookup that failed. A
		// resource has nothing to hang off until the profile has been
		// written, so ?profile=&resource= is sent back to the profile.
		if ($wanted === '') {
			if (isset($_REQUEST['resource'])) {
				header('Location: config.php?display=oryk_provisioner&profile=');
				exit;
			}

			return;
		}

		if (!ctype_digit($wanted) || !$this->profileExists((int) $wanted)) {
			header('Location: config.php?display=oryk_provisioner&tab=profiles');
			exit;
		}

		if (!isset($_REQUEST['resource'])) {
			return;
		}

		$resource = trim((string) $_REQUEST['resource']);

		// Empty is the new-resource editor. Anything else has to name a
		// resource of this profile: an id belonging to some other profile is
		// as much a stale link as one belonging to nothing at all.
		if ($resource === '') {
			return;
		}

		if (!ctype_digit($resource) || !$this->resourceRow($resource, (int) $wanted)) {
			header('Location: config.php?display=oryk_provisioner&profile=' . (int) $wanted . '&tab=resources');
			exit;
		}
	}

	/**
	 * Determine whether an AJAX request is supported.
	 *
	 * @param string $req Requested AJAX operation.
	 * @param mixed &$setting Request settings passed by reference.
	 *
	 * @return bool True when the request is supported.
	 */
	public function ajaxRequest($req, &$setting)
	{
		switch ($req) {
			case 'listClients':
			case 'listProfiles':
			case 'saveClient':
			case 'saveProfile':
			case 'deleteClient':
			case 'deleteProfile':
			case 'listResources':
			case 'saveResource':
			case 'deleteResource':
			case 'uploadResourceFile':
			case 'deleteResourceFile':
			case 'listLogs':
			case 'clearLogs':
				return true;
			default:
				return false;
		}
	}

	/**
	 * Process an AJAX request.
	 *
	 * @return array<string, mixed>|null AJAX response data.
	 */
	public function ajaxHandler()
	{
		$command = isset($_REQUEST['command']) ? (string) $_REQUEST['command'] : '';

		switch ($command) {
			case 'listClients':
				return $this->listClients();

			case 'listProfiles':
				return $this->listProfiles();

			case 'saveClient':
				return $this->saveClient($_REQUEST);

			case 'saveProfile':
				return $this->saveProfile($_REQUEST);

			case 'deleteClient':
				return $this->deleteClient($_REQUEST['id'] ?? null);

			case 'deleteProfile':
				return $this->deleteProfile($_REQUEST['id'] ?? null);

			case 'listResources':
				return $this->listResources();

			case 'saveResource':
				return $this->saveResource($_REQUEST);

			case 'deleteResource':
				return $this->deleteResource($_REQUEST['id'] ?? null);

			case 'uploadResourceFile':
				return $this->uploadResourceFile($_REQUEST);

			case 'deleteResourceFile':
				return $this->deleteResourceFile($_REQUEST);

			case 'listLogs':
				return $this->listLogs();

			case 'clearLogs':
				return $this->clearLogs($_REQUEST);

			default:
				return null;
		}
	}

	/**
	 * Rows for the Clients table.
	 *
	 * Narrowed to one profile when profile_id is passed, which is how the
	 * profile editor's Clients tab is filled: the same rows read the same
	 * way, rather than a second statement that would drift from this one.
	 *
	 * The resource editor's Clients tab asks with resource_id alongside it
	 * and gets the same rows again, each carrying the filename that client
	 * asks that one resource for.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	private function listClients()
	{
		// The sort column and its direction are written into the statement
		// rather than bound, so neither can be taken from the request as it
		// stands. Only what the table offers as a sortable heading is
		// accepted, and anything else sorts by MAC rather than being refused.
		$sortable = [
			'mac' => 'pc.mac',
			'device_id' => 'pc.device_id',
			'description' => 'd.description',
			'profile' => 'p.name',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['mac'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$clauses = [];

		// The same rows twice: every client on the module page, and one
		// profile's own on that profile's Clients tab, which asks with
		// profile_id. An id is honoured as given rather than falling back to
		// everything, so a profile nothing points at comes back empty instead
		// of coming back as the whole list.
		if (isset($_REQUEST['profile_id'])) {
			$clauses[] = 'pc.profile_id = :profile_id';
			$params[':profile_id'] = (int) $_REQUEST['profile_id'];
		}

		// Bracketed, now that it is no longer the only thing in there: an
		// unbracketed OR chain ANDed with the profile would match every
		// client whose profile name contains the search.
		if ($search !== '') {
			$clauses[] = "(pc.mac LIKE :search
				OR pc.device_id LIKE :search
				OR d.user LIKE :search
				OR d.description LIKE :search
				OR p.name LIKE :search)";
			$params[':search'] = '%' . $search . '%';
		}

		$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

		$from = "FROM `{$this->clientsTable}` pc
			LEFT JOIN devices d ON d.id = pc.device_id
			LEFT JOIN `{$this->profilesTable}` p ON p.id = pc.profile_id";

		$countStmt = $this->db->prepare("SELECT COUNT(*) $from $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$sql = "
			SELECT
				pc.id,
				pc.mac,
				pc.device_id,
				pc.profile_id,
				d.user AS extension,
				d.description AS description,
				p.name AS profile
			$from
			$where
			ORDER BY $sort $order
			LIMIT :limit OFFSET :offset
		";

		$stmt = $this->db->prepare($sql);
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
		}
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmt->execute();

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// Only the page that was read, and only when a resource was asked
		// about: rendering a name costs this client's values, and a client
		// that is not on screen is not worth them.
		if (isset($_REQUEST['resource_id'])) {
			$rows = $this->withResourceFilenames($rows, $_REQUEST['resource_id']);
		}

		return [
			'total' => $total,
			'rows' => $rows,
		];
	}

	/**
	 * The filename each client asks one resource for, added to its row.
	 *
	 * A resource's name is a template, so the file a phone actually asks for
	 * is a different string per client -- which is why a resource has no one
	 * URL to preview and why this belongs on a client row rather than on the
	 * resource itself.
	 *
	 * Each row is rendered against the values the endpoint would render it
	 * against, read the same way through clientByMac(), so what the tab
	 * shows is what a phone gets rather than a second guess at it.
	 *
	 * A row whose client has since moved to another profile is left
	 * undecorated rather than shown a filename this resource would not
	 * answer to.
	 *
	 * @param array<int, array<string, mixed>> $rows       Client rows as read.
	 * @param mixed                            $resourceId Resource they are being asked about.
	 *
	 * @return array<int, array<string, mixed>> The same rows, decorated.
	 */
	private function withResourceFilenames(array $rows, $resourceId)
	{
		if (!$rows) {
			return $rows;
		}

		$stmt = $this->db->prepare(
			"SELECT id, profile_id, name
			FROM `{$this->resourcesTable}`
			WHERE id = :id"
		);
		$stmt->execute([':id' => (int) $resourceId]);
		$resource = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$resource) {
			return $rows;
		}

		foreach ($rows as $index => $row) {
			$mac = (string) $row['mac'];
			$provisioning = $this->clientByMac($mac);

			if (!$provisioning || (int) $provisioning['profile_id'] !== (int) $resource['profile_id']) {
				continue;
			}

			$request = $this->resourceRequest(
				(string) $resource['name'],
				$this->provisioningValues($provisioning),
				$mac
			);

			$rows[$index]['filename'] = $request['filename'];
			$rows[$index]['url'] = $request['url'];
		}

		return $rows;
	}

	/**
	 * What one client asks for when it asks for one resource, and where.
	 *
	 * matchResource() read backwards. A name is matched either as it renders
	 * or as the tail of a request with the MAC taken off the front, so the
	 * request that reaches this resource is one of:
	 *
	 *   {{device.mac}}-phone.cfg  renders to 0004f282e824-phone.cfg, which is
	 *                             asked for as it stands.
	 *   phone.cfg                 has no MAC to render, so the phone asks for
	 *                             0004f282e824-phone.cfg and resourceSuffix()
	 *                             takes the MAC back off. Joined by nothing
	 *                             when the name is an extension of its own
	 *                             (.cfg), by a dash otherwise.
	 *
	 * Whether that request can be *linked* is a second question, because the
	 * endpoint reads the MAC out of the path: a name that renders with this
	 * client's MAC in it says who is asking, and one with no MAC at all can
	 * say so with ?mac=. A name carrying somebody else's twelve hex digits --
	 * 000000000000-directory.xml, which is a real filename a real phone asks
	 * for -- cannot: the endpoint takes the MAC from the path over the query
	 * string, so the link would render the wrong client. Those rows get the
	 * filename and no link, which is the truth about them.
	 *
	 * @param string                $name   Resource name, as typed.
	 * @param array<string, string> $values Placeholder name to value.
	 * @param string                $mac    This client's normalised MAC.
	 *
	 * @return array{filename: string, url: string} What it asks for, and where -- '' when there is no such URL.
	 */
	private function resourceRequest($name, array $values, $mac)
	{
		$name = (string) $name;
		$rendered = $this->renderTemplate($name, $values);
		$carries = $this->macIn($rendered);

		if ($carries === $mac) {
			return ['filename' => $rendered, 'url' => $this->engineUrl($rendered)];
		}

		// Somebody else's twelve hex digits, written into the name and asked
		// for exactly as they stand -- 000000000000-directory.xml is a phone
		// asking every profile for the same file. It is served, and it is
		// not linkable: the endpoint would read that MAC as the client.
		if ($carries !== '') {
			return ['filename' => $rendered, 'url' => ''];
		}

		// No placeholders and no MAC of its own, so the phone asks for the
		// MAC and this name joined and the endpoint takes the MAC back off
		// again.
		if ($rendered === $name && $name !== '') {
			$joined = $mac . ($name[0] === '.' ? '' : '-') . $name;

			if ($this->macIn($joined) === $mac && strcasecmp($this->resourceSuffix($joined, $mac), $name) === 0) {
				return ['filename' => $joined, 'url' => $this->engineUrl($joined)];
			}
		}

		// Nothing in the name says which client is asking, so the request
		// says it the endpoint's other way.
		return ['filename' => $rendered, 'url' => $this->engineUrl($rendered) . '?mac=' . rawurlencode($mac)];
	}

	/**
	 * The MAC a requested filename carries, if it carries one.
	 *
	 * The endpoint's own reading of a path, kept to the same pattern: twelve
	 * hexadecimal characters, optionally paired off with colons or dashes,
	 * not run up against more hex on either side.
	 *
	 * @param string $filename Filename as it would be asked for.
	 *
	 * @return string The normalised MAC, or '' when there is none.
	 */
	private function macIn($filename)
	{
		$pattern = '/(?<![0-9A-Fa-f])(?:[0-9A-Fa-f]{2}[:-]?){5}[0-9A-Fa-f]{2}(?![0-9A-Fa-f])/';

		return preg_match($pattern, (string) $filename, $match) ? $this->normalizeMac($match[0]) : '';
	}

	/**
	 * The URL a phone is given for a filename.
	 *
	 * The web-root symlink rather than the module's own path, because that is
	 * the URL a phone is provisioned with and so the one worth showing.
	 *
	 * @param string $filename Filename as it would be asked for.
	 *
	 * @return string Absolute path under the engine link.
	 */
	private function engineUrl($filename)
	{
		// A colon is legal in a path segment and is how half the vendors
		// separate a MAC, so it is left as it is rather than escaped into
		// something the endpoint's own reading of the path would miss.
		return '/' . $this->engineLink . '/' . str_replace('%3A', ':', rawurlencode((string) $filename));
	}

	/**
	 * Rows for the Profiles table.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	private function listProfiles()
	{
		$sortable = [
			'name' => 'p.name',
			'assigned' => 'assigned',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['name'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$where = '';

		if ($search !== '') {
			$where = "WHERE p.name LIKE :search";
			$params[':search'] = '%' . $search . '%';
		}

		$countStmt = $this->db->prepare("SELECT COUNT(*) FROM `{$this->profilesTable}` p $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$sql = "
			SELECT
				p.id,
				p.name,
				(
					SELECT COUNT(*)
					FROM `{$this->clientsTable}` pc
					WHERE pc.profile_id = p.id
				) AS assigned
			FROM `{$this->profilesTable}` p
			$where
			ORDER BY $sort $order
			LIMIT :limit OFFSET :offset
		";

		$stmt = $this->db->prepare($sql);
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
		}
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmt->execute();

		return [
			'total' => $total,
			'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
		];
	}

	/**
	 * One client, for the editor.
	 *
	 * Read on the way into the page rather than fetched by it, the same way a
	 * profile is: the editor is a page of its own, so there is nothing left
	 * for it to wait on over AJAX. `getDevice` was that fetch, and went with
	 * the dialog it filled.
	 *
	 * @param mixed $id Client id.
	 *
	 * @return array<string, mixed>|null The client, or null when there is none.
	 */
	private function clientRow($id)
	{
		$stmt = $this->db->prepare(
			"SELECT id, mac, device_id, profile_id
			FROM `{$this->clientsTable}`
			WHERE id = :id"
		);
		$stmt->execute([':id' => (int) $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	/**
	 * One profile, for the editor.
	 *
	 * Read on the way into the page rather than fetched by it: the editor is a
	 * page of its own now, so there is nothing to wait for over AJAX.
	 *
	 * @param mixed $id Profile id.
	 *
	 * @return array<string, mixed>|null The profile, or null when there is none.
	 */
	private function profileRow($id)
	{
		$stmt = $this->db->prepare(
			"SELECT id, name
			FROM `{$this->profilesTable}`
			WHERE id = :id"
		);
		$stmt->execute([':id' => (int) $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	/**
	 * How many clients a profile is assigned to.
	 *
	 * Which clients they are is the editor's Clients tab, and that asks for
	 * them itself over listClients, a page at a time. This is the number
	 * alone: what the tab is labelled with, and what a profile still in use
	 * is refused deletion over.
	 *
	 * @param int $profileId Profile id.
	 *
	 * @return int Clients pointing at the profile.
	 */
	private function profileClientCount($profileId)
	{
		$stmt = $this->db->prepare(
			"SELECT COUNT(*) FROM `{$this->clientsTable}` WHERE profile_id = :id"
		);
		$stmt->execute([':id' => (int) $profileId]);

		return (int) $stmt->fetchColumn();
	}

	/**
	 * How many resources a profile serves.
	 *
	 * The rows are a tab's business and it pages through them itself over
	 * listResources; this is the number alone, which labels the tab.
	 *
	 * @param int $profileId Profile id.
	 *
	 * @return int Resources belonging to the profile.
	 */
	private function profileResourceCount($profileId)
	{
		$stmt = $this->db->prepare(
			"SELECT COUNT(*) FROM `{$this->resourcesTable}` WHERE profile_id = :id"
		);
		$stmt->execute([':id' => (int) $profileId]);

		return (int) $stmt->fetchColumn();
	}

	/**
	 * Create or update a client.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	private function saveClient($request)
	{
		$id = (int) ($request['id'] ?? 0);
		$mac = $this->normalizeMac($request['mac'] ?? '');

		if ($mac === '') {
			return [
				'status' => false,
				'message' => _('A MAC address is 12 hexadecimal characters, with or without separators.'),
			];
		}

		$deviceId = trim((string) ($request['device_id'] ?? ''));
		$deviceId = $deviceId === '' ? null : $deviceId;

		$profileId = (int) ($request['profile_id'] ?? 0);
		$profileId = $profileId > 0 ? $profileId : null;

		if ($deviceId !== null && !$this->freepbxDeviceExists($deviceId)) {
			return ['status' => false, 'message' => _('That FreePBX device no longer exists.')];
		}

		if ($profileId !== null && !$this->profileExists($profileId)) {
			return ['status' => false, 'message' => _('That profile no longer exists.')];
		}

		$taken = $this->db->prepare(
			"SELECT id FROM `{$this->clientsTable}` WHERE mac = :mac AND id != :id"
		);
		$taken->execute([':mac' => $mac, ':id' => $id]);

		if ($taken->fetchColumn()) {
			return ['status' => false, 'message' => _('That MAC address is already associated.')];
		}

		if ($id) {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->clientsTable}`
				SET mac = :mac, device_id = :device_id, profile_id = :profile_id
				WHERE id = :id"
			);
			$stmt->execute([
				':mac' => $mac,
				':device_id' => $deviceId,
				':profile_id' => $profileId,
				':id' => $id,
			]);

			return ['status' => true, 'id' => $id];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->clientsTable}` (mac, device_id, profile_id)
			VALUES (:mac, :device_id, :profile_id)"
		);
		$stmt->execute([
			':mac' => $mac,
			':device_id' => $deviceId,
			':profile_id' => $profileId,
		]);

		return ['status' => true, 'id' => (int) $this->db->lastInsertId()];
	}

	/**
	 * Create or update a profile.
	 *
	 * A name and nothing else. What the profile serves is its resources, each
	 * written on its own page, and who it serves is the clients assigned
	 * to it -- neither is edited here.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	private function saveProfile($request)
	{
		$id = (int) ($request['id'] ?? 0);
		$name = trim((string) ($request['name'] ?? ''));

		if ($name === '') {
			return ['status' => false, 'message' => _('A profile needs a name.')];
		}

		$taken = $this->db->prepare(
			"SELECT id FROM `{$this->profilesTable}` WHERE name = :name AND id != :id"
		);
		$taken->execute([':name' => $name, ':id' => $id]);

		if ($taken->fetchColumn()) {
			return ['status' => false, 'message' => _('A profile with that name already exists.')];
		}

		if ($id) {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->profilesTable}`
				SET name = :name
				WHERE id = :id"
			);
			$stmt->execute([
				':name' => $name,
				':id' => $id,
			]);

			return ['status' => true, 'id' => $id, 'name' => $name];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->profilesTable}` (name) VALUES (:name)"
		);
		$stmt->execute([':name' => $name]);

		return ['status' => true, 'id' => (int) $this->db->lastInsertId(), 'name' => $name];
	}

	/**
	 * Remove a client.
	 *
	 * @param mixed $id Client id.
	 *
	 * @return array<string, mixed> Status of the removal.
	 */
	private function deleteClient($id)
	{
		$stmt = $this->db->prepare("DELETE FROM `{$this->clientsTable}` WHERE id = :id");
		$stmt->execute([':id' => (int) $id]);

		return ['status' => true];
	}

	/**
	 * Remove a profile.
	 *
	 * A profile that clients still point at is kept, so a client never
	 * ends up naming a profile that has gone.
	 *
	 * @param mixed $id Profile id.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	private function deleteProfile($id)
	{
		$id = (int) $id;

		$count = $this->profileClientCount($id);

		if ($count) {
			return [
				'status' => false,
				'message' => sprintf(
					_('This profile is assigned to %s client(s). Reassign them first.'),
					$count
				),
			];
		}

		// Resources go with it. Unlike a client, a resource has
		// no existence apart from the profile that serves it -- there is
		// nothing to reassign it to and nothing left for it to mean.
		//
		// Their ids are read first because an uploaded file is named after
		// the resource it belongs to: once the rows are gone there is
		// nothing left to say which files in the repository were theirs.
		$owned = $this->db->prepare("SELECT id FROM `{$this->resourcesTable}` WHERE profile_id = :id");
		$owned->execute([':id' => $id]);

		foreach ($owned->fetchAll(PDO::FETCH_COLUMN) as $resourceId) {
			$this->removeRepoFile($resourceId);
		}

		$resources = $this->db->prepare("DELETE FROM `{$this->resourcesTable}` WHERE profile_id = :id");
		$resources->execute([':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM `{$this->profilesTable}` WHERE id = :id");
		$stmt->execute([':id' => $id]);

		return ['status' => true];
	}

	/**
	 * Rows for a profile's Resources table.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	private function listResources()
	{
		$profileId = (int) ($_REQUEST['profile_id'] ?? 0);

		$sortable = [
			'name' => 'name',
			'file_size' => 'file_size',
			'updated_at' => 'updated_at',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['name'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		// Always narrowed to the one profile: the table is on that profile's
		// page and a resource has no meaning away from it.
		$where = 'WHERE profile_id = :profile_id';
		$params = [':profile_id' => $profileId];

		if ($search !== '') {
			$where .= ' AND name LIKE :search';
			$params[':search'] = '%' . $search . '%';
		}

		$countStmt = $this->db->prepare("SELECT COUNT(*) FROM `{$this->resourcesTable}` $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$sql = "
			SELECT id, profile_id, name, file_size, file_uploaded_at, updated_at
			FROM `{$this->resourcesTable}`
			$where
			ORDER BY $sort $order
			LIMIT :limit OFFSET :offset
		";

		$stmt = $this->db->prepare($sql);
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
		}
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmt->execute();

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		// The client editor's Resources tab asks the same question of the
		// same table, from the other side: these files, for that one phone.
		if (isset($_REQUEST['client_id'])) {
			$rows = $this->withClientFilenames($rows, $_REQUEST['client_id']);
		}

		return [
			'total' => $total,
			'rows' => $rows,
		];
	}

	/**
	 * The filename one client asks each of these resources for, added to its row.
	 *
	 * withResourceFilenames() the other way round -- one resource over many
	 * clients there, one client over many resources here -- so both go
	 * through resourceRequest() and render against the values the endpoint
	 * would use, rather than either tab having its own idea of what a phone
	 * asks for.
	 *
	 * The client's values are read once and rendered against every row: it is
	 * one phone here, where withResourceFilenames() has one name and a page
	 * of phones.
	 *
	 * A resource whose profile is not the one this client is assigned to is
	 * left undecorated -- it is not served to this phone, whatever its name
	 * renders to.
	 *
	 * @param array<int, array<string, mixed>> $rows     Resource rows as read.
	 * @param mixed                            $clientId Client they are being asked about.
	 *
	 * @return array<int, array<string, mixed>> The same rows, decorated.
	 */
	private function withClientFilenames(array $rows, $clientId)
	{
		if (!$rows) {
			return $rows;
		}

		$client = $this->clientRow($clientId);

		if (!$client || !$client['profile_id']) {
			return $rows;
		}

		$mac = (string) $client['mac'];
		$provisioning = $this->clientByMac($mac);

		if (!$provisioning) {
			return $rows;
		}

		$values = $this->provisioningValues($provisioning);

		foreach ($rows as $index => $row) {
			if ((int) $row['profile_id'] !== (int) $client['profile_id']) {
				continue;
			}

			$request = $this->resourceRequest((string) $row['name'], $values, $mac);

			$rows[$index]['filename'] = $request['filename'];
			$rows[$index]['url'] = $request['url'];
		}

		return $rows;
	}

	/**
	 * One resource of one profile, for the editor.
	 *
	 * Read on the way into the page rather than fetched by it, the same way
	 * the profile editor reads its profile. The profile is part of the lookup
	 * rather than checked after it: a resource id that belongs to a different
	 * profile names nothing at this URL.
	 *
	 * @param mixed $id        Resource id.
	 * @param int   $profileId Profile it has to belong to.
	 *
	 * @return array<string, mixed>|null The resource, or null when there is none.
	 */
	private function resourceRow($id, $profileId)
	{
		$stmt = $this->db->prepare(
			"SELECT id, profile_id, name, template, file_size, file_uploaded_at
			FROM `{$this->resourcesTable}`
			WHERE id = :id AND profile_id = :profile_id"
		);
		$stmt->execute([':id' => (int) $id, ':profile_id' => (int) $profileId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	/**
	 * Create or update a resource.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	private function saveResource($request)
	{
		$id = (int) ($request['id'] ?? 0);
		$profileId = (int) ($request['profile_id'] ?? 0);
		$name = trim((string) ($request['name'] ?? ''));
		$template = (string) ($request['template'] ?? '');

		if (!$this->profileExists($profileId)) {
			return ['status' => false, 'message' => _('That profile no longer exists.')];
		}

		if ($name === '') {
			return ['status' => false, 'message' => _('A resource needs the filename a phone asks for.')];
		}

		// The name is matched against the last segment of a request path, so
		// a separator in it could never match anything. Better said here than
		// found out as a phone quietly failing to provision.
		if (strpbrk($name, '/\\') !== false) {
			return ['status' => false, 'message' => _('A filename cannot contain a slash.')];
		}

		// Counted in characters, which is what the column holds, rather than
		// in bytes: a name is almost always ASCII, where the two are the same.
		if (mb_strlen($name) > 180) {
			return ['status' => false, 'message' => _('That filename is too long.')];
		}

		$taken = $this->db->prepare(
			"SELECT id FROM `{$this->resourcesTable}`
			WHERE profile_id = :profile_id AND name = :name AND id != :id"
		);
		$taken->execute([':profile_id' => $profileId, ':name' => $name, ':id' => $id]);

		if ($taken->fetchColumn()) {
			return ['status' => false, 'message' => _('This profile already serves a file by that name.')];
		}

		if ($id) {
			$existing = $this->resourceRow($id, $profileId);

			if (!$existing) {
				return ['status' => false, 'message' => _('That resource no longer exists.')];
			}

			// Two reasons the template may not be written, and they are the
			// same reason twice: this only writes what it was actually given.
			//
			// A resource serving an uploaded file is not showing a template box
			// at all, so its text stays underneath the file rather than being
			// destroyed by it -- remove the file and the resource is the
			// template it was. And a caller that sent no template did not mean
			// an empty one: uploading a file saves the resource's name along
			// with it, and that is a save of the name and nothing else.
			$set = 'name = :name';
			$params = [':name' => $name, ':id' => $id, ':profile_id' => $profileId];

			if (array_key_exists('template', $request) && $existing['file_size'] === null) {
				$set .= ', template = :template';
				$params[':template'] = $template;
			}

			// The profile is in the WHERE rather than trusted from the form:
			// a resource does not move between profiles, and an id from one
			// profile posted at another is not an edit of anything.
			$stmt = $this->db->prepare(
				"UPDATE `{$this->resourcesTable}`
				SET $set
				WHERE id = :id AND profile_id = :profile_id"
			);
			$stmt->execute($params);

			return ['status' => true, 'id' => $id, 'profile_id' => $profileId, 'name' => $name];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->resourcesTable}` (profile_id, name, template)
			VALUES (:profile_id, :name, :template)"
		);
		$stmt->execute([
			':profile_id' => $profileId,
			':name' => $name,
			':template' => $template,
		]);

		return [
			'status' => true,
			'id' => (int) $this->db->lastInsertId(),
			'profile_id' => $profileId,
			'name' => $name,
		];
	}

	/**
	 * Remove a resource.
	 *
	 * Nothing points at a resource the way a client points at a
	 * profile, so there is nothing to refuse this for.
	 *
	 * Its uploaded file goes first, while there is still a row to say the
	 * id: the file is named after the resource and nothing else, so a row
	 * deleted without it would leave a number in the repository that
	 * nothing on the system can account for.
	 *
	 * @param mixed $id Resource id.
	 *
	 * @return array<string, mixed> Status of the removal.
	 */
	private function deleteResource($id)
	{
		$this->removeRepoFile($id);

		$stmt = $this->db->prepare("DELETE FROM `{$this->resourcesTable}` WHERE id = :id");
		$stmt->execute([':id' => (int) $id]);

		return ['status' => true];
	}

	/**
	 * The directory uploaded resource files are kept in.
	 *
	 * Under the Asterisk spool rather than anywhere beneath the web root:
	 * these are handed out by the endpoint reading them, never by Apache
	 * finding them, and a firmware image has no business being reachable at
	 * its path on disk as well as by the name a phone asks for.
	 *
	 * @return string Absolute path, without a trailing slash.
	 */
	private function repoPath()
	{
		$spool = trim((string) $this->FreePBX->Config->get('ASTSPOOLDIR'));

		return ($spool !== '' ? rtrim($spool, '/') : '/var/spool/asterisk') . '/repo';
	}

	/**
	 * Where one resource's uploaded file is kept.
	 *
	 * Named after the resource id and nothing else -- not the filename it is
	 * served under, which can be renamed and repeats across profiles, and
	 * nothing the uploader sent. An integer cast is a path that cannot climb
	 * out of the directory it is in, and it means a file can still be found
	 * and removed by id alone when the row it belonged to is being deleted.
	 *
	 * @param mixed $id Resource id.
	 *
	 * @return string Absolute path to the file, whether or not it is there.
	 */
	private function repoFile($id)
	{
		return $this->repoPath() . '/' . (int) $id;
	}

	/**
	 * Make the repository directory if it is not there.
	 *
	 * Called at install and again before every upload, because the spool is
	 * not a place the module is the only writer of and a directory that was
	 * there in the morning may not be by the afternoon.
	 *
	 * @return bool True when the directory exists and is writable.
	 */
	private function ensureRepo()
	{
		$path = $this->repoPath();

		if (!is_dir($path) && !@mkdir($path, 0750, true) && !is_dir($path)) {
			$this->log(sprintf('oryk_provisioner: could not create %s', $path), null, 'WARNING');

			return false;
		}

		// The web user is the one that writes here, and Asterisk is the group
		// the rest of the spool belongs to. Neither is fatal: a directory
		// somebody else made with workable permissions is workable.
		$user = (string) $this->FreePBX->Config->get('AMPASTERISKWEBUSER');
		$group = (string) $this->FreePBX->Config->get('AMPASTERISKWEBGROUP');

		if ($user !== '') {
			@chown($path, $user);
		}

		if ($group !== '') {
			@chgrp($path, $group);
		}

		return is_writable($path);
	}

	/**
	 * Remove one resource's uploaded file.
	 *
	 * A file that is not there is not a failure: it is the state this was
	 * asked to reach.
	 *
	 * @param mixed $id Resource id.
	 *
	 * @return bool True when nothing is left at that path.
	 */
	private function removeRepoFile($id)
	{
		$path = $this->repoFile($id);

		if (is_file($path) && !@unlink($path)) {
			$this->log(sprintf('oryk_provisioner: could not remove %s', $path), null, 'WARNING');

			return false;
		}

		return true;
	}

	/**
	 * The largest upload this server accepts, in bytes.
	 *
	 * The smaller of upload_max_filesize and post_max_size, because a
	 * multipart body is a little larger than the file inside it and either
	 * limit refuses the request on its own. Shown in the editor: a firmware
	 * image is tens of megabytes and PHP's default is not, and finding that
	 * out at the end of a long upload is the worst time to find it out.
	 *
	 * @return int Bytes, or 0 when neither limit is set.
	 */
	private function uploadLimit()
	{
		$bytes = function ($value) {
			$value = trim((string) $value);

			if ($value === '') {
				return 0;
			}

			$number = (int) $value;

			switch (strtolower(substr($value, -1))) {
				case 'g':
					return $number * 1024 * 1024 * 1024;

				case 'm':
					return $number * 1024 * 1024;

				case 'k':
					return $number * 1024;

				default:
					return $number;
			}
		};

		$limits = array_filter([$bytes(ini_get('upload_max_filesize')), $bytes(ini_get('post_max_size'))]);

		return $limits ? (int) min($limits) : 0;
	}

	/**
	 * Store an uploaded file against a resource.
	 *
	 * The file is what the resource serves from now on, and the template it
	 * had is left in the column underneath -- there is no second thing to
	 * set and nothing to undo but removing the file again.
	 *
	 * The resource is saved as part of it, so choosing a file is the whole of
	 * what has to be done: a filename edited on the way to the upload is
	 * written with it rather than sitting unsaved behind a file that is
	 * already stored.
	 *
	 * The resource has to have been written first, because the file is named
	 * after its id and a resource that has never been saved has not got one.
	 * That is why the control is inert on a new resource rather than absent.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, the size stored, and a message when refused.
	 */
	private function uploadResourceFile($request)
	{
		// A body over post_max_size arrives with $_POST and $_FILES both
		// empty and no error set anywhere -- PHP discards it before any of
		// this runs. The only trace is a Content-Length with nothing behind
		// it, and without this the answer would be 'no file was uploaded',
		// which is true and useless.
		if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
			return [
				'status' => false,
				'message' => sprintf(
					_('That file is larger than this server accepts (%s).'),
					ini_get('post_max_size')
				),
			];
		}

		$id = (int) ($request['id'] ?? 0);
		$profileId = (int) ($request['profile_id'] ?? 0);
		$resource = $this->resourceRow($id, $profileId);

		if (!$resource) {
			return ['status' => false, 'message' => _('That resource no longer exists.')];
		}

		// The filename is saved with the file. Somebody who renamed the resource
		// and then chose a file meant both of those, and storing the bytes
		// against the old name would be a save that half happened. It goes first
		// so a name that cannot be saved -- blank, or one this profile already
		// serves -- refuses the whole action before anything is written.
		if (array_key_exists('name', $request)) {
			$saved = $this->saveResource([
				'id' => $id,
				'profile_id' => $profileId,
				'name' => $request['name'],
			]);

			if (!$saved['status']) {
				return $saved;
			}

			$resource['name'] = $saved['name'];
		}

		$file = $_FILES['file'] ?? null;

		if (!is_array($file) || !isset($file['error'])) {
			return ['status' => false, 'message' => _('No file was uploaded.')];
		}

		if ($file['error'] !== UPLOAD_ERR_OK) {
			return ['status' => false, 'message' => $this->uploadErrorMessage((int) $file['error'])];
		}

		// Nothing else in here reads the name the browser sent, and this is
		// why: the only thing it could be used for is a path.
		if (!is_uploaded_file((string) $file['tmp_name'])) {
			return ['status' => false, 'message' => _('That was not an uploaded file.')];
		}

		if (!$this->ensureRepo()) {
			return [
				'status' => false,
				'message' => sprintf(_('%s cannot be written to.'), $this->repoPath()),
			];
		}

		$target = $this->repoFile($id);

		if (!@move_uploaded_file((string) $file['tmp_name'], $target)) {
			return [
				'status' => false,
				'message' => sprintf(_('The file could not be stored at %s.'), $target),
			];
		}

		@chmod($target, 0640);
		clearstatcache(true, $target);

		// Read back off the file rather than taken from the upload: the size
		// is the column that says this resource is a file at all, so it says
		// what is on the disk and not what was meant to be.
		$size = (int) filesize($target);

		$stmt = $this->db->prepare(
			"UPDATE `{$this->resourcesTable}`
			SET file_size = :size, file_uploaded_at = NOW()
			WHERE id = :id AND profile_id = :profile_id"
		);
		$stmt->execute([':size' => $size, ':id' => $id, ':profile_id' => $profileId]);

		$this->log(sprintf(
			'oryk_provisioner: stored %s bytes for resource %s (%s)',
			$size,
			$id,
			(string) $resource['name']
		), null, 'INFO');

		return [
			'status' => true,
			'id' => $id,
			'name' => (string) $resource['name'],
			'file_size' => $size,
			'file_uploaded_at' => date('Y-m-d H:i:s'),
		];
	}

	/**
	 * Take the uploaded file off a resource.
	 *
	 * What is left is the resource it was before the upload, template and
	 * all: the file never touched that column, so there is nothing to put
	 * back. The resource is saved on the way through, as it is for an upload.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when refused.
	 */
	private function deleteResourceFile($request)
	{
		$id = (int) ($request['id'] ?? 0);
		$profileId = (int) ($request['profile_id'] ?? 0);
		$resource = $this->resourceRow($id, $profileId);

		if (!$resource) {
			return ['status' => false, 'message' => _('That resource no longer exists.')];
		}

		// Saved for the same reason an upload is: the button was pressed on a
		// page that may have a renamed resource on it, and it means the page.
		if (array_key_exists('name', $request)) {
			$saved = $this->saveResource([
				'id' => $id,
				'profile_id' => $profileId,
				'name' => $request['name'],
			]);

			if (!$saved['status']) {
				return $saved;
			}

			$resource['name'] = $saved['name'];
		}

		$this->removeRepoFile($id);

		$stmt = $this->db->prepare(
			"UPDATE `{$this->resourcesTable}`
			SET file_size = NULL, file_uploaded_at = NULL
			WHERE id = :id AND profile_id = :profile_id"
		);
		$stmt->execute([':id' => $id, ':profile_id' => $profileId]);

		return ['status' => true, 'id' => $id, 'name' => (string) $resource['name']];
	}

	/**
	 * What one of PHP's upload error codes means, in words.
	 *
	 * @param int $code UPLOAD_ERR_* constant.
	 *
	 * @return string Message for the editor.
	 */
	private function uploadErrorMessage($code)
	{
		switch ($code) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf(
					_('That file is larger than this server accepts (%s).'),
					ini_get('upload_max_filesize')
				);

			case UPLOAD_ERR_PARTIAL:
				return _('The upload did not finish.');

			case UPLOAD_ERR_NO_FILE:
				return _('No file was uploaded.');

			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
				return _('This server has nowhere to put the upload.');

			case UPLOAD_ERR_EXTENSION:
				return _('A PHP extension refused the upload.');

			default:
				return _('The upload failed.');
		}
	}

	/**
	 * Rows for a Logs table.
	 *
	 * One statement asked two ways, the way listClients is: every request the
	 * endpoint has answered on the module page, and one client's own on the
	 * client editor's Logs tab, which asks with `mac`.
	 *
	 * It asks with a MAC rather than a client id, and that is the point. A row
	 * is written for a MAC whether or not anything is associated with it, so
	 * the requests a phone made before somebody wrote its client are on that
	 * client's tab the moment it exists -- which is the run of 404s that says
	 * what the phone has been asking for all along.
	 *
	 * Newest first unless asked otherwise, and the id breaks the tie: a phone
	 * that has just booted asks for six files inside one second, and a log
	 * that shuffles them is a log that cannot be read.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	private function listLogs()
	{
		$sortable = [
			'created_at' => 'l.created_at',
			'mac' => 'l.mac',
			'filename' => 'l.filename',
			'status' => 'l.status',
			'ip' => 'l.ip',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['created_at'];

		// The other way round from every other table here: a log is read from
		// the end, so anything that is not explicitly ascending is descending.
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'asc' ? 'ASC' : 'DESC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$clauses = [];

		if (isset($_REQUEST['mac'])) {
			$clauses[] = 'l.mac = :mac';
			$params[':mac'] = $this->logMac($_REQUEST['mac']);
		}

		if ($search !== '') {
			$clauses[] = "(l.mac LIKE :search
				OR l.filename LIKE :search
				OR l.message LIKE :search
				OR l.ip LIKE :search
				OR l.user_agent LIKE :search)";
			$params[':search'] = '%' . $search . '%';
		}

		$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

		// Joined on the MAC rather than on an id stored in the row, for the
		// reason there is no such id: which client a MAC belongs to is a
		// question about now, not about when the request came in.
		$from = "FROM `{$this->logsTable}` l
			LEFT JOIN `{$this->clientsTable}` pc ON pc.mac = l.mac
			LEFT JOIN devices d ON d.id = pc.device_id";

		try {
			$countStmt = $this->db->prepare("SELECT COUNT(*) $from $where");
			$countStmt->execute($params);
			$total = (int) $countStmt->fetchColumn();

			$sql = "
				SELECT
					l.id,
					l.mac,
					l.filename,
					l.status,
					l.message,
					l.method,
					l.ip,
					l.user_agent,
					l.created_at,
					pc.id AS client_id,
					d.description AS description
				$from
				$where
				ORDER BY $sort $order, l.id DESC
				LIMIT :limit OFFSET :offset
			";

			$stmt = $this->db->prepare($sql);
			foreach ($params as $key => $value) {
				$stmt->bindValue($key, $value);
			}
			$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
			$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
			$stmt->execute();

			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			// The table arrives with install(); a module upgraded without it
			// is a module whose log is empty, not one whose pages are broken.
			$this->log('oryk_provisioner: could not read the provisioning log', $e->getMessage(), 'WARNING');

			return ['total' => 0, 'rows' => []];
		}

		return [
			'total' => $total,
			'rows' => $rows,
		];
	}

	/**
	 * Empty the log, or one client's part of it.
	 *
	 * Narrowed the same way the table is: the client editor's tab clears the
	 * one phone's rows, the module page's clears the lot. A provisioning log
	 * grows by a row per file per boot per phone and nothing prunes it, so
	 * this is the only thing standing between a busy site and a table larger
	 * than everything else the module has.
	 *
	 * @param array<string, mixed> $request Submitted values; `mac` narrows it.
	 *
	 * @return array<string, mixed> Status of the removal.
	 */
	private function clearLogs($request)
	{
		try {
			if (isset($request['mac'])) {
				$mac = $this->logMac($request['mac']);

				if ($mac === '') {
					return ['status' => false, 'message' => _('No MAC address to clear the log for.')];
				}

				$stmt = $this->db->prepare("DELETE FROM `{$this->logsTable}` WHERE mac = :mac");
				$stmt->execute([':mac' => $mac]);

				return ['status' => true];
			}

			// A DELETE rather than the TRUNCATE that would be quicker: TRUNCATE
			// is DDL, it commits whatever transaction it lands in, and this is
			// a button on a page rather than a maintenance job.
			$this->db->exec("DELETE FROM `{$this->logsTable}`");
		} catch (\Exception $e) {
			return ['status' => false, 'message' => _('The provisioning log could not be cleared.')];
		}

		return ['status' => true];
	}

	/**
	 * How many requests one MAC has made.
	 *
	 * What the client editor's Logs tab is labelled with. Zero is worth
	 * reading rather than hiding: a phone that has never asked for anything is
	 * a phone that is not reaching this PBX at all, which is a different fault
	 * from the ones the rows themselves describe.
	 *
	 * @param string $mac Normalised MAC address.
	 *
	 * @return int Rows logged against it.
	 */
	private function macLogCount($mac)
	{
		try {
			$stmt = $this->db->prepare("SELECT COUNT(*) FROM `{$this->logsTable}` WHERE mac = :mac");
			$stmt->execute([':mac' => (string) $mac]);

			return (int) $stmt->fetchColumn();
		} catch (\Exception $e) {
			// Rendered on the way into the page, so it may not throw: a
			// missing log table is a badge that reads zero, not a 500 on the
			// client editor.
			return 0;
		}
	}

	/**
	 * A MAC as the log stores it.
	 *
	 * Normalised when it is a MAC, so a row can be read back by the client
	 * editor's tab, and kept as it was sent when it is not -- on a row like
	 * that, what the thing at the other end actually sent is the whole of what
	 * the row is worth having.
	 *
	 * @param mixed $mac MAC address as it was written.
	 *
	 * @return string The normalised MAC, or what was asked with.
	 */
	private function logMac($mac)
	{
		$normalised = $this->normalizeMac($mac);

		return $normalised !== '' ? $normalised : trim((string) $mac);
	}


	/**
	 * What a template can refer to, as the editor lists it.
	 *
	 * Written out here rather than derived from a rendering, because the
	 * resource editor has to be able to say what the names are with no client
	 * in hand -- a new resource's profile may not be assigned to anything yet.
	 * The `sip.` names are whatever the device carries in FreePBX, so the view
	 * names a few by way of example instead of listing them.
	 *
	 * @return array<string, array<string, string>> Group heading to name and note.
	 */
	private function templatePlaceholders()
	{
		return [
			_('Device') => [
				'device.mac' => _('00908f3bbcba'),
				'device.mac_upper' => _('00908F3BBCBA'),
				'device.mac_colon' => _('00:90:8f:3b:bc:ba'),
				'device.mac_colon_upper' => _('00:90:8F:3B:BC:BA'),
				'device.id' => _('FreePBX device'),
				'device.description' => _('Device description'),
				'device.tech' => _('pjsip or sip'),
				'device.username' => _('SIP username'),
				'device.secret' => _('SIP secret'),
			],
			_('Extension') => [
				'extension.number' => _('Extension the device is attached to'),
				'extension.name' => _('Display name'),
				'extension.voicemail' => _('Voicemail setting'),
			],
			_('Profile and server') => [
				'profile.name' => _('This profile'),
				'server.host' => _('Host the PBX is reached on'),
				'server.port' => _('5060'),
			],
		];
	}

	/**
	 * Answer a request for a file and end the request.
	 *
	 * Called by engine/provisioner.php, which is the only route a phone can
	 * reach: FreePBX's config.php sends every session-less request to the
	 * login page long before a module's doConfigPageInit() runs.
	 *
	 *   serve($mac)                          the main config, [mac].cfg
	 *   serve($mac, '0004f282e824-web.cfg')  any other file that profile serves
	 *   serve('', '3111-44500-001.sip.ld')   a file, asked for by name alone
	 *
	 * It is serve() rather than serveConfig() because what a resource holds is
	 * no longer always configuration: one with an uploaded file behind it is
	 * sent as it was stored, and firmware is why the upload exists.
	 *
	 * The MAC may be empty, which is the other half of the same change. A
	 * phone fetching firmware does not put its MAC anywhere in the request --
	 * a Polycom asks for /3111-44500-001.sip.ld and nothing else -- so a
	 * request that reaches no profile is answered from the resources that are
	 * the same for every caller, which is to say the ones with a file.
	 *
	 * The second argument is the filename as it was asked for, not a resource
	 * id: which resource that names is resolveRequest()'s business.
	 *
	 * The outcome is logged here rather than by the endpoint, because this is
	 * where the request ends and the endpoint never gets to see it.
	 *
	 * @param mixed       $mac       MAC address, written however it was written, or ''.
	 * @param string|null $requested Filename asked for, or null for the main config.
	 *
	 * @return void Never returns; the request ends here.
	 */
	public function serve($mac, $requested = null)
	{
		$result = $this->resolveRequest($mac, $requested);
		$status = $result['status'] ? 200 : 404;

		$this->log(sprintf(
			'oryk_provisioner: %s for %s (%s)',
			(string) $status,
			(string) $requested !== '' ? (string) $requested : (string) $mac,
			$result['message'] ?? 'OK',
		), null, $result['status'] ? 'INFO' : 'DEBUG');

		// Both outcomes, and a MAC nothing is associated with as readily as one
		// that renders: a phone asking for a file nobody has written a client
		// for is the request an operator most needs to see, and it is the one
		// that leaves no other trace.
		$this->logRequest($mac, $requested, $status, $result['status'] ? null : ($result['message'] ?? null));

		if (!$result['status']) {
			$this->sendText(404, $result['message'] . "\n");
		}

		// An uploaded file never becomes a string on the way out: a firmware
		// image is tens of megabytes and the rendered text of a config is not.
		if (($result['kind'] ?? '') === 'file') {
			$this->sendFile((string) $result['path'], (string) $result['resource']);
		}

		$this->sendText(200, $result['config'], $this->contentType((string) $result['resource']));
	}

	/**
	 * Record one provisioning request.
	 *
	 * Written on the way out of serve(), whichever way that went, and by the
	 * endpoint itself for the requests that never reach serve() at
	 * all -- a PUT of a phone's boot log, or a path with no MAC anywhere in
	 * it. A request nobody can answer is the one worth having a record of.
	 *
	 * Metadata only, which is the rule the README sets and the reason there is
	 * no column for the rendered body: it carries device.secret whenever a
	 * template asks for it, and a log is not where that belongs. What is kept
	 * is who asked, what for, and how it went -- not which resource answered,
	 * because the filename as the phone spelled it is the fact of the request,
	 * and which file of which profile it reached is the profile's answer and
	 * may not be the same answer tomorrow.
	 *
	 * Nothing in here may fail a request. A phone whose configuration is ready
	 * does not go without it because the log table is missing, which is
	 * exactly the state a module upgraded without its install step is in.
	 *
	 * @param mixed       $mac       MAC address, written however it was written.
	 * @param string|null $requested Filename asked for, '' when none was.
	 * @param int         $status    HTTP status the request was answered with.
	 * @param string|null $message   Why, when it was not answered with a file.
	 *
	 * @return void
	 */
	public function logRequest($mac, $requested, $status, $message = null)
	{
		try {
			$stmt = $this->db->prepare(
				"INSERT INTO `{$this->logsTable}`
					(mac, filename, status, message, method, ip, user_agent)
				VALUES (:mac, :filename, :status, :message, :method, :ip, :user_agent)"
			);
			$stmt->execute([
				':mac' => $this->clip($this->logMac($mac), 64),
				':filename' => $this->clip($requested, 255),
				':status' => (int) $status,
				':message' => $message === null ? null : $this->clip($message, 255),
				':method' => $this->clip($_SERVER['REQUEST_METHOD'] ?? '', 10),
				':ip' => $this->clip($_SERVER['REMOTE_ADDR'] ?? '', 45) ?: null,
				':user_agent' => $this->clip($_SERVER['HTTP_USER_AGENT'] ?? '', 255) ?: null,
			]);
		} catch (\Exception $e) {
			// The log is the one thing here that is allowed to go missing.
			$this->log('oryk_provisioner: could not write the provisioning log', $e->getMessage(), 'WARNING');
		}
	}

	/**
	 * A value cut to what its column holds.
	 *
	 * Everything logged comes off the wire -- a filename, a User-Agent, a
	 * message with a filename in it -- so none of it has a length anyone here
	 * decided. Cut rather than refused: a truncated User-Agent still says
	 * which phone asked, and a row that failed to insert says nothing at all.
	 *
	 * Counted in characters, which is what the column holds.
	 *
	 * @param mixed $value  Value as it arrived.
	 * @param int   $length Characters the column takes.
	 *
	 * @return string The value, or as much of it as fits.
	 */
	private function clip($value, $length)
	{
		$value = (string) $value;

		return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
	}


	/**
	 * Which file answers a request, and what it is.
	 *
	 * Separate from serve() because this is the part worth calling again: a
	 * preview, a console command, a test. Only the caller there ends the
	 * request. It is resolveRequest() rather than renderConfig() because what
	 * comes back is not always rendered text -- a resource with an uploaded
	 * file behind it comes back as a path on disk.
	 *
	 * Three steps, and the first that answers wins:
	 *
	 *   1. A MAC naming a client with a profile: that profile's resources,
	 *      matched by name. The profile is the authority -- a name it does not
	 *      serve is refused here rather than looked for elsewhere, or a
	 *      profile could never withhold a file.
	 *   2. No MAC, a MAC naming no client, or a client with no profile: the
	 *      resources that carry an uploaded file, matched by name exactly.
	 *   3. Nothing.
	 *
	 * Step 2 is files only, and deliberately so. A template matched with no
	 * client behind it has no values to render against, so every placeholder
	 * in it would come out empty and the phone would receive a configuration
	 * that parses and is wrong -- which is worse than the 404 it gets instead.
	 * A file has no rendering at all, and that is exactly why it is the same
	 * bytes for every caller and can be handed out by name. The consequence is
	 * worth saying plainly: an uploaded file can be fetched by anyone who
	 * reaches the endpoint and knows what it is called.
	 *
	 * A request that names no file -- /provisioner/[mac], or an internal
	 * caller with nothing to pass -- is a request for the main config, which
	 * is to say [mac].cfg. It is filled in inside step 1 rather than left
	 * empty and special-cased further down, so exactly one string is matched
	 * against and the message on a miss names the file the caller will
	 * recognise. There is nothing to fill it in from without a client, so a
	 * caller with neither a MAC nor a filename has asked for nothing.
	 *
	 * @param mixed       $mac       MAC address, written however it was written, or ''.
	 * @param string|null $requested Filename asked for, or null for the main config.
	 *
	 * @return array<string, mixed> Status, what answers when something does,
	 *                              and a message when nothing did.
	 */
	public function resolveRequest($mac, $requested = null)
	{
		$mac = $this->normalizeMac($mac);
		$requested = trim((string) $requested);

		$row = $mac === '' ? null : $this->clientByMac($mac);

		if ($row && $row['profile_id'] !== null) {
			$values = $this->provisioningValues($row);

			// What is left of the filename with this client's own MAC off the
			// front: 0004f282e824-phone.cfg asked of that client is phone.cfg,
			// and 0004f282e824.cfg is .cfg. Worked out here rather than in the
			// endpoint, so the endpoint only has to report what was asked for.
			$suffix = $requested === '' ? '' : $this->resourceSuffix($requested, $mac);

			// Two ways of asking for nothing in particular, and both are asking
			// for the main config: no filename at all, and the client's own MAC
			// with no filename after it (/provisioner/0004f282e824, which is
			// what leaves nothing behind once the MAC is taken off the front).
			if ($suffix === '') {
				$requested = $mac . '.cfg';
				$suffix = '.cfg';
			}

			$match = $this->matchResource((int) $row['profile_id'], $requested, $suffix, $values);

			if ($match !== null) {
				return $this->resourceResult($match, $values) + [
					'mac' => $mac,
					'profile' => (string) $row['profile_name'],
				];
			}

			// Nothing this profile serves answers to the name. That includes
			// [mac].cfg on a profile with no `.cfg` resource on it: there is no
			// template behind the profile to fall back to, and a profile that
			// serves nothing is one somebody has not finished writing rather
			// than one with an implicit main config.
			return [
				'status' => false,
				'message' => sprintf(_('%s is not something this profile serves.'), $requested),
			];
		}

		// No profile behind the request, which is the firmware case: a name and
		// nothing else to say who is asking.
		if ($requested !== '') {
			$file = $this->fileByName($requested);

			if ($file !== null) {
				return $this->resourceResult($file, []);
			}
		}

		if ($mac === '') {
			return [
				'status' => false,
				'message' => $requested === ''
					? _('No MAC address and no filename: nothing was asked for.')
					: sprintf(_('%s is not a file this server serves.'), $requested),
			];
		}

		if (!$row) {
			return [
				'status' => false,
				'message' => sprintf(_('%s is not associated with anything.'), $mac),
			];
		}

		return [
			'status' => false,
			'message' => sprintf(_('%s has no profile assigned.'), $mac),
		];
	}

	/**
	 * A matched resource as the thing that answers a request.
	 *
	 * The one place that knows there are two kinds of resource, and the only
	 * place that needs to: one with a file size on it was uploaded and is sent
	 * as it was stored, one without is a template and is rendered. There is no
	 * column declaring which -- the size is the answer, because only an upload
	 * can set it and removing the file clears it again.
	 *
	 * A row that says it has a file and a repository that has not got it is a
	 * refusal rather than a quiet fall back to the template underneath. A
	 * phone handed an empty config where it expected firmware fails in a way
	 * nobody can see; a 404 naming the file says what happened.
	 *
	 * @param array<string, mixed>  $resource The resource row.
	 * @param array<string, string> $values   Placeholder name to value, empty for a file.
	 *
	 * @return array<string, mixed> What serve() sends, or a refusal.
	 */
	private function resourceResult(array $resource, array $values)
	{
		$name = (string) $resource['name'];

		if ($resource['file_size'] === null) {
			return [
				'status' => true,
				'kind' => 'template',
				'resource' => $name,
				'config' => $this->renderTemplate((string) $resource['template'], $values),
			];
		}

		$path = $this->repoFile($resource['id']);

		if (!is_file($path)) {
			return [
				'status' => false,
				'message' => sprintf(_('The uploaded file for %s is missing.'), $name),
			];
		}

		return [
			'status' => true,
			'kind' => 'file',
			'resource' => $name,
			'path' => $path,
		];
	}

	/**
	 * A resource with an uploaded file, by the name it is asked for.
	 *
	 * The lookup behind a request that reaches no profile. Matched exactly,
	 * and only against the name as it was typed: with no client there is no
	 * MAC to take out of the request and nothing to render a templated name
	 * against, so a file meant to be fetched this way is named exactly what
	 * the vendor asks for -- 3111-44500-001.sip.ld.
	 *
	 * Case is the collation's business rather than LOWER()'s. The column is
	 * utf8mb4 case-insensitive, so a plain comparison already ignores case and
	 * can use the index on `name`, where wrapping the column in a function
	 * would be just as correct and would guarantee a scan.
	 *
	 * Two profiles carrying the same firmware is the ordinary case rather than
	 * an ambiguity worth refusing -- they hold the same bytes -- so the lowest
	 * id wins and the rest are noted in the log.
	 *
	 * @param string $requested Filename as it was asked for.
	 *
	 * @return array<string, mixed>|null The resource, or null when none answers.
	 */
	private function fileByName($requested)
	{
		$stmt = $this->db->prepare(
			"SELECT id, profile_id, name, template, file_size
			FROM `{$this->resourcesTable}`
			WHERE name = :name AND file_size IS NOT NULL
			ORDER BY id"
		);
		$stmt->execute([':name' => (string) $requested]);

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		if (!$rows) {
			return null;
		}

		if (count($rows) > 1) {
			$this->log(sprintf(
				'oryk_provisioner: %s is uploaded to %s profiles; serving resource %s',
				(string) $requested,
				count($rows),
				$rows[0]['id']
			), null, 'DEBUG');
		}

		return $rows[0];
	}

	/**
	 * Which of a profile's resources answers to a requested filename.
	 *
	 * Two ways, and a name written out in full wins:
	 *
	 *   {{device.mac}}-phone.cfg  rendered with this client's values and
	 *                             compared to what was actually asked for, so
	 *                             one resource covers every client on the
	 *                             profile -- and a vendor that does not put
	 *                             the MAC at the front, or anywhere, can
	 *                             still be named exactly.
	 *   phone.cfg                 compared against the request with the MAC
	 *                             taken off the front, so the ordinary case
	 *                             can be typed as the tail on its own.
	 *
	 * Both are the same string in the same column, which is the point: the
	 * second is only what the first becomes when it has no placeholders in
	 * it. There is nothing to declare and nothing to migrate later.
	 *
	 * @param int                   $profileId Profile the resources belong to.
	 * @param string                $requested Filename as it was asked for.
	 * @param string                $suffix    The same, with this client's MAC removed.
	 * @param array<string, string> $values    Placeholder name to value.
	 *
	 * @return array<string, mixed>|null The resource, or null when none answers.
	 */
	private function matchResource($profileId, $requested, $suffix, array $values)
	{
		$stmt = $this->db->prepare(
			"SELECT id, name, template, file_size
			FROM `{$this->resourcesTable}`
			WHERE profile_id = :profile_id
			ORDER BY name"
		);
		$stmt->execute([':profile_id' => (int) $profileId]);

		$fallback = null;

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $resource) {
			$name = (string) $resource['name'];

			// Filenames are matched without regard to case throughout: a
			// phone asking for 0004F282E824.cfg and one asking for
			// 0004f282e824.cfg are the same phone asking for the same file.
			if (strcasecmp($this->renderTemplate($name, $values), $requested) === 0) {
				return $resource;
			}

			// Held rather than returned. A rendered name is what its author
			// wrote out in full, and it wins over one that only matches the
			// tail of the request.
			if ($fallback === null && $suffix !== '' && strcasecmp($name, $suffix) === 0) {
				$fallback = $resource;
			}
		}

		return $fallback;
	}

	/**
	 * A requested filename with this client's own MAC taken off the front.
	 *
	 * Phones ask by MAC, in whatever separator style they favour:
	 * 0004f282e824-phone.cfg, 00:04:f2:82:e8:24-phone.cfg and
	 * 0004f282e824.cfg are all one client asking. What is left is the part a
	 * resource can be named after -- phone.cfg, and .cfg for the main config,
	 * which is a resource of the profile like any other file it serves.
	 *
	 * A filename that does not begin with this client's MAC comes back
	 * unchanged: it is either meant literally or meant for somebody else, and
	 * neither is helped by having something trimmed off it.
	 *
	 * Read a character at a time, stopping the moment twelve hex digits are
	 * in hand, because a pattern that allows a dot between them will also
	 * take the dot of the extension: [mac].cfg has to come back as `.cfg`,
	 * not `cfg`. Stopping at the twelfth digit means nothing past the MAC is
	 * ever looked at, and 0004.f282.e824 grouping costs nothing extra.
	 *
	 * @param string $filename Last segment of the requested path.
	 * @param string $mac      This client's normalised MAC.
	 *
	 * @return string The filename, or what is left of it.
	 */
	private function resourceSuffix($filename, $mac)
	{
		$hex = '';
		$end = 0;

		for ($i = 0, $length = strlen($filename); $i < $length; $i++) {
			$char = $filename[$i];

			if (ctype_xdigit($char)) {
				$hex .= $char;
				$end = $i + 1;

				if (strlen($hex) === 12) {
					break;
				}

				continue;
			}

			// A separator, but only once there is something for it to
			// separate: a name starting with one is not a MAC.
			if ($hex !== '' && ($char === ':' || $char === '-' || $char === '.')) {
				continue;
			}

			break;
		}

		if (strlen($hex) !== 12 || strtolower($hex) !== $mac) {
			return $filename;
		}

		$rest = substr($filename, $end);

		// The dot of an extension belongs to the name that is left --
		// [mac].cfg is `.cfg` -- where a dash or an underscore is only the
		// vendor's way of joining the two, and part of neither.
		return ($rest !== '' && $rest[0] === '.') ? $rest : ltrim($rest, '-_');
	}

	/**
	 * What a file is served as.
	 *
	 * Read off the name rather than stored against the resource: an author
	 * who called a file directory.xml has already said what it is, and a
	 * second field saying it again is a second field to get wrong.
	 *
	 * The two kinds of resource want different answers to an extension
	 * nothing here recognises, so the kind is the second argument rather than
	 * another list of extensions to keep up with. A template with an odd
	 * extension is still configuration text, which is what .cfg is and what
	 * every phone here expects; an uploaded file is bytes somebody chose, and
	 * sending a firmware image as text is how it arrives corrupted.
	 *
	 * @param string $name Resource name, which is the filename it is served as.
	 * @param string $kind 'template' or 'file'.
	 *
	 * @return string Content type, without the charset.
	 */
	private function contentType($name, $kind = 'template')
	{
		switch (strtolower((string) pathinfo($name, PATHINFO_EXTENSION))) {
			case 'xml':
				return 'text/xml';

			case 'json':
				return 'application/json';

			case 'cfg':
			case 'conf':
			case 'ini':
			case 'txt':
				return 'text/plain';

			default:
				return $kind === 'file' ? 'application/octet-stream' : 'text/plain';
		}
	}

	/**
	 * Write a text response and end the request.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @param string $type Content type, without the charset.
	 *
	 * @return void Never returns.
	 */
	private function sendText($code, $body, $type = 'text/plain')
	{
		$body = (string) $body;

		http_response_code($code);
		header('Content-Type: ' . $type . '; charset=utf-8');
		header('Content-Length: ' . strlen($body));
		// A phone that re-reads its config expects what is stored now, not
		// what a cache kept from the last time it asked.
		header('Cache-Control: no-store');

		// A phone HEADs before it GETs. The length is what it asked for; the
		// body is not.
		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
			echo $body;
		}

		exit;
	}

	/**
	 * A filename as Content-Disposition spells it.
	 *
	 * Two spellings of the one name, which is what RFC 6266 asks for: a bare
	 * `filename` every client understands, with anything outside printable
	 * ASCII -- and the quotes and backslashes that would end the header
	 * early -- folded to underscores, and a `filename*` carrying the name as
	 * it really is for the clients that read it.
	 *
	 * basename() because a name is a name. Saving a resource already refuses
	 * a slash; a response header is not where that should be found out.
	 *
	 * @param string $name Resource name, which is the filename it is served as.
	 *
	 * @return string The filename and filename* parameters.
	 */
	private function filenameParameter($name)
	{
		$name = basename((string) $name);
		$ascii = str_replace(['\\', '"'], '_', preg_replace('/[^\x20-\x7E]/', '_', $name));

		return sprintf('filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name));
	}

	/**
	 * Send an uploaded file and end the request.
	 *
	 * What sendText() does not have to think about, because a config is a few
	 * kilobytes and a firmware image is forty megabytes:
	 *
	 *  - the body is never a string. Output buffering is dropped and the file
	 *    is read straight out to the client.
	 *  - a conditional request is answered with 304. Polycom sends
	 *    If-Modified-Since for firmware, and on a fleet that re-provisions
	 *    nightly that is the difference between a handful of empty replies and
	 *    tens of gigabytes of the same image over and over.
	 *
	 * Cache-Control is no-cache rather than the no-store a config gets: ask
	 * every time, and be told when nothing has changed. The validators are the
	 * file's own size and modification time, so a re-upload invalidates them
	 * without anything having to remember to.
	 *
	 * Ranges are declined rather than half-implemented. No phone here asks for
	 * one, and saying so is better than a client believing 206 is available.
	 *
	 * @param string $path Absolute path to the stored file.
	 * @param string $name Resource name, which is the filename it is served as.
	 *
	 * @return void Never returns.
	 */
	private function sendFile($path, $name)
	{
		$size = (int) @filesize($path);
		$modified = (int) @filemtime($path);
		$etag = sprintf('"%x-%x"', $modified, $size);

		header('Content-Type: ' . $this->contentType($name, 'file'));
		// The name it is saved under. Without this a fetch through a client's
		// own URL -- /provisioner/0004f282e824-3111-44500-001.sip.ld -- lands
		// on disk under that whole path segment, MAC and all, when what it is
		// is 3111-44500-001.sip.ld. The resource's name is the answer because
		// the resource's name is what the file is; the MAC in front of it is
		// addressing, and belongs to the request rather than to the file.
		//
		// A phone ignores this header and writes the file wherever it decided
		// to ask for it, so it costs nothing there and is the whole of the
		// difference in a browser. inline rather than attachment: something
		// displayable should still display, and the name is taken from here
		// either way.
		header('Content-Disposition: inline; ' . $this->filenameParameter($name));
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
		header('ETag: ' . $etag);
		header('Cache-Control: no-cache');
		header('Accept-Ranges: none');

		$tag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
		$since = (int) strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

		// The tag is the stronger of the two and is checked alone when it is
		// there: a client that sent both means the tag.
		if ($tag !== '' ? $tag === $etag : ($since > 0 && $modified > 0 && $since >= $modified)) {
			http_response_code(304);
			exit;
		}

		http_response_code(200);
		header('Content-Length: ' . $size);

		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
			exit;
		}

		// Nothing between the file and the client: a readfile() into an output
		// buffer is the string this whole path exists to avoid.
		while (ob_get_level()) {
			ob_end_clean();
		}

		@set_time_limit(0);
		readfile($path);
		exit;
	}

	/**
	 * The client a MAC names, with the FreePBX device and profile behind it.
	 *
	 * One statement rather than three lookups: the whole of what rendering
	 * needs is one row wide.
	 *
	 * @param string $mac Normalised MAC address.
	 *
	 * @return array<string, mixed>|null The row, or null when the MAC is unknown.
	 */
	private function clientByMac($mac)
	{
		$stmt = $this->db->prepare(
			"SELECT
				pc.mac,
				pc.device_id,
				pc.profile_id,
				d.user AS extension,
				d.description,
				d.tech,
				p.name AS profile_name
			FROM `{$this->clientsTable}` pc
			LEFT JOIN devices d ON d.id = pc.device_id
			LEFT JOIN `{$this->profilesTable}` p ON p.id = pc.profile_id
			WHERE pc.mac = :mac"
		);
		$stmt->execute([':mac' => $mac]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	/**
	 * What a template can refer to, as a flat map of dotted names.
	 *
	 * Flat and dotted rather than nested, because that is what the
	 * placeholders are: `{{device.mac}}` is a key here, not a path walked
	 * through arrays. The eventual resolver puts sources in precedence order
	 * behind the same names.
	 *
	 * A client with no FreePBX device still renders -- everything the
	 * client would have answered for is simply empty, which is what a profile
	 * of pure static configuration wants anyway.
	 *
	 * @param array<string, mixed> $row Client row from clientByMac().
	 *
	 * @return array<string, string> Placeholder name to value.
	 */
	private function provisioningValues(array $row)
	{
		$mac = (string) $row['mac'];
		$colon = implode(':', str_split($mac, 2));

		$sip = $this->deviceSipSettings($row['device_id'] ?? null);
		$extension = $this->extensionRow($row['extension'] ?? null);

		$values = [
			'device.mac' => $mac,
			'device.mac_upper' => strtoupper($mac),
			'device.mac_colon' => $colon,
			'device.mac_colon_upper' => strtoupper($colon),
			'device.id' => (string) ($row['device_id'] ?? ''),
			'device.description' => (string) ($row['description'] ?? ''),
			'device.tech' => (string) ($row['tech'] ?? ''),
			'device.username' => (string) ($sip['username'] ?? $row['device_id'] ?? ''),
			'device.secret' => (string) ($sip['secret'] ?? ''),
			'extension.number' => (string) ($row['extension'] ?? ''),
			'extension.name' => (string) ($extension['name'] ?? ''),
			'extension.voicemail' => (string) ($extension['voicemail'] ?? ''),
			'profile.id' => (string) ($row['profile_id'] ?? ''),
			'profile.name' => (string) ($row['profile_name'] ?? ''),
			'server.host' => $this->serverHost(),
			'server.port' => '5060',
		];

		// Everything else the device is configured with in FreePBX, under its
		// own prefix: transport, callerid, dtmfmode and the rest are vendor
		// business, so the template asks for what it needs by name rather
		// than this file deciding in advance what a phone might want.
		foreach ($sip as $keyword => $data) {
			$values['sip.' . $keyword] = $data;
		}

		return $values;
	}

	/**
	 * The FreePBX device settings for a device id.
	 *
	 * `sip` is where core keeps them, one keyword per row, for both drivers.
	 * A site without that table -- or with a device that has no settings --
	 * renders a template with those values empty rather than failing.
	 *
	 * @param string|null $deviceId FreePBX devices.id.
	 *
	 * @return array<string, string> Keyword to value.
	 */
	private function deviceSipSettings($deviceId)
	{
		if ($deviceId === null || $deviceId === '') {
			return [];
		}

		try {
			$stmt = $this->db->prepare("SELECT keyword, data FROM sip WHERE id = :id");
			$stmt->execute([':id' => $deviceId]);
			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return [];
		}

		$settings = [];

		foreach ($rows as $setting) {
			// Keywords become placeholder names, and a placeholder name is
			// letters, digits, underscores and the dot that separates the
			// prefix -- so anything else in a keyword is folded to an
			// underscore rather than producing a name nothing can spell.
			$keyword = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $setting['keyword']);
			$settings[$keyword] = (string) $setting['data'];
		}

		return $settings;
	}

	/**
	 * The extension a device is attached to.
	 *
	 * @param string|null $extension Extension number.
	 *
	 * @return array<string, mixed>|null The row, or null when there is none.
	 */
	private function extensionRow($extension)
	{
		if ($extension === null || $extension === '') {
			return null;
		}

		try {
			$stmt = $this->db->prepare("SELECT name, voicemail FROM users WHERE extension = :extension");
			$stmt->execute([':extension' => $extension]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return null;
		}

		return $row ?: null;
	}

	/**
	 * The host a phone would register against.
	 *
	 * Taken from the request, which is the host the administrator is looking
	 * at the PBX on and, on a single-address system, the one the phones use.
	 * A module setting overrides it once there are settings to hold one.
	 *
	 * @return string Hostname or address, without a port.
	 */
	private function serverHost()
	{
		$host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
		$host = preg_replace('/:\d+$/', '', $host);

		return $host !== '' ? $host : (string) ($_SERVER['SERVER_ADDR'] ?? '');
	}

	/**
	 * Fill in a template's {{ }} placeholders.
	 *
	 * The delimiters and the dotted names are the ones the full engine will
	 * use, so profiles written against this keep rendering: what is missing
	 * is filters, sections and escaping, not the syntax.
	 *
	 * A name nothing answers to renders as nothing. A phone parsing a config
	 * copes with an empty value; it does not cope with a literal `{{ }}` left
	 * where a value was meant to be.
	 *
	 * @param string                $template Template text.
	 * @param array<string, string> $values   Placeholder name to value.
	 *
	 * @return string Rendered configuration.
	 */
	private function renderTemplate($template, array $values)
	{
		return preg_replace_callback(
			'/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/',
			function ($match) use ($values) {
				return $values[$match[1]] ?? '';
			},
			$template
		);
	}

	/**
	 * A MAC address as it is stored: lowercase hexadecimal, no separators.
	 *
	 * @param mixed $mac MAC address as it was typed.
	 *
	 * @return string The normalised MAC, or an empty string when it is not one.
	 */
	private function normalizeMac($mac)
	{
		$mac = strtolower(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));

		return preg_match('/^[0-9a-f]{12}$/', $mac) ? $mac : '';
	}

	/**
	 * The FreePBX devices a client can point at.
	 *
	 * @return array<int, array<string, mixed>> Device rows.
	 */
	private function freepbxDevices()
	{
		$stmt = $this->db->prepare(
			"SELECT id, user, description, tech
			FROM devices
			ORDER BY id + 0, id"
		);
		$stmt->execute();

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * The profiles a client can point at.
	 *
	 * @return array<int, array<string, mixed>> Profile rows, id and name.
	 */
	private function profileChoices()
	{
		$stmt = $this->db->prepare(
			"SELECT id, name FROM `{$this->profilesTable}` ORDER BY name"
		);
		$stmt->execute();

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Whether a FreePBX device still exists.
	 *
	 * @param string $deviceId FreePBX devices.id.
	 *
	 * @return bool True when the device is there.
	 */
	private function freepbxDeviceExists($deviceId)
	{
		$stmt = $this->db->prepare("SELECT id FROM devices WHERE id = :id");
		$stmt->execute([':id' => $deviceId]);

		return (bool) $stmt->fetchColumn();
	}

	/**
	 * Whether a profile still exists.
	 *
	 * @param int $profileId Profile id.
	 *
	 * @return bool True when the profile is there.
	 */
	private function profileExists($profileId)
	{
		$stmt = $this->db->prepare("SELECT id FROM `{$this->profilesTable}` WHERE id = :id");
		$stmt->execute([':id' => (int) $profileId]);

		return (bool) $stmt->fetchColumn();
	}
}
