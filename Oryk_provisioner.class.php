<?php

// Oryk_provisioner.class.php

namespace FreePBX\modules;

use BMO;
use PDO;
use FreePBX_Helpers;

class Oryk_provisioner extends FreePBX_Helpers implements \BMO
{
	/**
	 * Table holding the MAC to FreePBX device to profile associations.
	 *
	 * @var string
	 */
	private $devicesTable = 'oryk_provisioner_devices';

	/**
	 * Table holding the provisioning profiles.
	 *
	 * @var string
	 */
	private $profilesTable = 'oryk_provisioner_profiles';

	/**
	 * Table holding the extra files a profile serves besides its main config.
	 *
	 * @var string
	 */
	private $resourcesTable = 'oryk_provisioner_resources';

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

	/**
	 * Render the requested module page.
	 *
	 * Two pages, told apart by ?profile=.
	 *
	 *   ?display=oryk_provisioner              the list: devices and profiles
	 *   ?display=oryk_provisioner&profile=<id> the editor, bound to that profile
	 *   ?display=oryk_provisioner&profile=     the editor, writing a new one
	 *
	 * A fourth URL, ?mac=[mac]&config=, never reaches here: it is
	 * configuration text rather than a page, and doConfigPageInit() has
	 * already answered it and ended the request.
	 *
	 * `profile` present but empty is deliberate rather than a degenerate case:
	 * it is the same page doing the same thing, minus a row to replace. A
	 * device association is small enough to stay in a dialog on the list; a
	 * profile carries a block of configuration text, which wants a page.
	 *
	 * @return string Rendered page output.
	 */
	public function showPage()
	{
		if (!isset($_REQUEST['profile'])) {
			return $this->showList();
		}

		$wanted = trim((string) $_REQUEST['profile']);
		$profile = ['id' => 0, 'name' => '', 'template' => ''];

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
		// text for the same reason a profile does, so it gets a page too
		// rather than a dialog on the profile's Resources tab.
		if ($profile['id'] && isset($_REQUEST['resource'])) {
			$page = $this->showResource($profile, trim((string) $_REQUEST['resource']));

			if ($page !== null) {
				return $page;
			}

			// The resource went between doConfigPageInit()'s check and here.
			// The profile it would have belonged to is where it is not.
			$tab = 'resources';
		}

		// Resources and Devices both hang off a profile that has been
		// written, so a new one opens on Profile whichever tab is asked for.
		$tabs = ['resources', 'devices'];

		return load_view(__DIR__ . '/views/profile.php', [
			'profile' => $profile,
			'assigned' => $profile['id'] ? $this->profileDeviceCount((int) $profile['id']) : 0,
			'placeholders' => $this->templatePlaceholders(),
			'tab' => (in_array($tab, $tabs, true) && $profile['id']) ? $tab : 'profile',
			'saved' => (int) ($_REQUEST['saved'] ?? 0),
		]);
	}

	/**
	 * Render the resource editor.
	 *
	 * The same page as the profile editor in everything but which two columns
	 * it is bound to, which is why both are one view apiece over a shared
	 * partial rather than one view with a mode flag.
	 *
	 * @param array<string, mixed> $profile Profile the resource belongs to.
	 * @param string               $wanted  Resource id, or '' for a new one.
	 *
	 * @return string|null Rendered page, or null when the id names nothing.
	 */
	private function showResource(array $profile, $wanted)
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
		]);
	}

	/**
	 * Render the list page.
	 *
	 * The tables on it are filled over AJAX, so the only thing handed to the
	 * view is what the device dialog needs to offer as choices, plus which tab
	 * to open on, which profile the editor has just written, and which device
	 * a link has asked to have opened -- the dialog for an association lives
	 * here, so a link from anywhere else comes back to this page naming a row.
	 *
	 * @param string|null $tab Tab to open on, or null to take it from the request.
	 *
	 * @return string Rendered page output.
	 */
	private function showList($tab = null)
	{
		$tab = $tab === null ? (string) ($_REQUEST['tab'] ?? '') : $tab;

		return load_view(__DIR__ . '/views/admin.php', [
			'freepbxDevices' => $this->freepbxDevices(),
			'profiles' => $this->profileChoices(),
			'tab' => $tab === 'profiles' ? 'profiles' : 'devices',
			'saved' => (int) ($_REQUEST['saved'] ?? 0),
			'openDevice' => (int) ($_REQUEST['device'] ?? 0),
		]);
	}

	/**
	 * Buttons FreePBX draws in the page header.
	 *
	 * Only the editor has any: the list's two tabs each carry their own Add,
	 * and a single button in the header could not say which tab it meant.
	 *
	 * Deliberately not the usual submit/delete names -- those are wired by
	 * core to a `form.fpbx-submit`, and this page has no form: a profile is
	 * saved over AJAX, not posted. These are ours, and views/profile.php binds
	 * them.
	 *
	 * @param string $request Current page request.
	 *
	 * @return array<string, array<string, string>> Action bar buttons.
	 */
	public function getActionBar($request)
	{
		if (!isset($_REQUEST['profile'])) {
			return [];
		}

		// On a resource page it is the resource that Save and Delete act on;
		// the profile in the URL is only what it hangs off.
		$row = isset($_REQUEST['resource'])
			? trim((string) $_REQUEST['resource'])
			: trim((string) $_REQUEST['profile']);

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
	 * Both tables are created if they are not already there, so installing
	 * over an existing install leaves the data where it is, and the web-root
	 * symlink that gives the device endpoint a short URL is put in place.
	 *
	 * @return bool True when installation completes.
	 */
	public function install()
	{
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->profilesTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`name` VARCHAR(191) NOT NULL,
				`template` LONGTEXT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `name` (`name`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// A resource is the same two columns a profile has -- a name and a
		// block of text -- hanging off the profile that serves it. The name is
		// unique per profile rather than globally: two profiles both serving a
		// `{{device.mac}}-phone.cfg` is the normal case, not a collision.
		//
		// 180 rather than the 191 a profile name gets, because this one is
		// half of a composite index: 180 utf8mb4 characters plus the int is
		// 724 bytes, inside the 767 an older MySQL allows per index. No
		// filename a phone asks for comes close either way. There is no
		// separate index on profile_id -- it is the left of the unique one.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->resourcesTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`profile_id` INT(11) NOT NULL,
				`name` VARCHAR(180) NOT NULL,
				`template` LONGTEXT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`id`),
				UNIQUE KEY `profile_name` (`profile_id`, `name`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// device_id is the FreePBX devices.id, which is a string column there,
		// so it is a string here too rather than something that has to be cast
		// on every join.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->devicesTable}` (
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

		$this->linkEngine();

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
	 * The device endpoint lives in engine/, under the module, which puts it at
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

		error_log($message);
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
	 * An id that names no profile is a stale link or a hand-edited URL, not a
	 * profile: opening the editor on it would bind the fields to something
	 * that cannot be saved back. It is sent to the list instead, and it is
	 * done here because this runs before any of the page has been written.
	 *
	 * ?config= is answered here too, and for the same reason: it is
	 * configuration text rather than a page, so it has to be written before
	 * FreePBX starts writing HTML around it. The request ends there.
	 *
	 * @param string $page Current configuration page.
	 *
	 * @return void
	 */
	public function doConfigPageInit($page)
	{
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
			case 'listDevices':
			case 'listProfiles':
			case 'getDevice':
			case 'saveDevice':
			case 'saveProfile':
			case 'deleteDevice':
			case 'deleteProfile':
			case 'listResources':
			case 'saveResource':
			case 'deleteResource':
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
			case 'listDevices':
				return $this->listDevices();

			case 'listProfiles':
				return $this->listProfiles();

			case 'getDevice':
				return $this->getDevice($_REQUEST['id'] ?? null);

			case 'saveDevice':
				return $this->saveDevice($_REQUEST);

			case 'saveProfile':
				return $this->saveProfile($_REQUEST);

			case 'deleteDevice':
				return $this->deleteDevice($_REQUEST['id'] ?? null);

			case 'deleteProfile':
				return $this->deleteProfile($_REQUEST['id'] ?? null);

			case 'listResources':
				return $this->listResources();

			case 'saveResource':
				return $this->saveResource($_REQUEST);

			case 'deleteResource':
				return $this->deleteResource($_REQUEST['id'] ?? null);

			default:
				return null;
		}
	}

	/**
	 * Rows for the Devices table.
	 *
	 * Narrowed to one profile when profile_id is passed, which is how the
	 * profile editor's Devices tab is filled: the same rows read the same
	 * way, rather than a second statement that would drift from this one.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	private function listDevices()
	{
		// The sort column and its direction are written into the statement
		// rather than bound, so neither can be taken from the request as it
		// stands. Only what the table offers as a sortable heading is
		// accepted, and anything else sorts by MAC rather than being refused.
		$sortable = [
			'mac' => 'pd.mac',
			'device_id' => 'pd.device_id',
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

		// The same rows twice: every association on the module page, and one
		// profile's own on that profile's Devices tab, which asks with
		// profile_id. An id is honoured as given rather than falling back to
		// everything, so a profile nothing points at comes back empty instead
		// of coming back as the whole list.
		if (isset($_REQUEST['profile_id'])) {
			$clauses[] = 'pd.profile_id = :profile_id';
			$params[':profile_id'] = (int) $_REQUEST['profile_id'];
		}

		// Bracketed, now that it is no longer the only thing in there: an
		// unbracketed OR chain ANDed with the profile would match every
		// association whose profile name contains the search.
		if ($search !== '') {
			$clauses[] = "(pd.mac LIKE :search
				OR pd.device_id LIKE :search
				OR d.user LIKE :search
				OR d.description LIKE :search
				OR p.name LIKE :search)";
			$params[':search'] = '%' . $search . '%';
		}

		$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

		$from = "FROM `{$this->devicesTable}` pd
			LEFT JOIN devices d ON d.id = pd.device_id
			LEFT JOIN `{$this->profilesTable}` p ON p.id = pd.profile_id";

		$countStmt = $this->db->prepare("SELECT COUNT(*) $from $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$sql = "
			SELECT
				pd.id,
				pd.mac,
				pd.device_id,
				pd.profile_id,
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

		return [
			'total' => $total,
			'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
		];
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
					FROM `{$this->devicesTable}` pd
					WHERE pd.profile_id = p.id
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
	 * One device association, for the edit form.
	 *
	 * @param mixed $id Association id.
	 *
	 * @return array<string, mixed> Status and the association.
	 */
	private function getDevice($id)
	{
		$stmt = $this->db->prepare(
			"SELECT id, mac, device_id, profile_id
			FROM `{$this->devicesTable}`
			WHERE id = :id"
		);
		$stmt->execute([':id' => (int) $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$row) {
			return ['status' => false, 'message' => _('Device not found.')];
		}

		return ['status' => true, 'device' => $row];
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
			"SELECT id, name, template
			FROM `{$this->profilesTable}`
			WHERE id = :id"
		);
		$stmt->execute([':id' => (int) $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	/**
	 * How many device associations a profile is assigned to.
	 *
	 * Which devices they are is the editor's Devices tab, and that asks for
	 * them itself over listDevices, a page at a time. This is the number
	 * alone: what the tab is labelled with, and what a profile still in use
	 * is refused deletion over.
	 *
	 * @param int $profileId Profile id.
	 *
	 * @return int Associations pointing at the profile.
	 */
	private function profileDeviceCount($profileId)
	{
		$stmt = $this->db->prepare(
			"SELECT COUNT(*) FROM `{$this->devicesTable}` WHERE profile_id = :id"
		);
		$stmt->execute([':id' => (int) $profileId]);

		return (int) $stmt->fetchColumn();
	}

	/**
	 * Create or update a device association.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	private function saveDevice($request)
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
			return ['status' => false, 'message' => _('That device profile no longer exists.')];
		}

		$taken = $this->db->prepare(
			"SELECT id FROM `{$this->devicesTable}` WHERE mac = :mac AND id != :id"
		);
		$taken->execute([':mac' => $mac, ':id' => $id]);

		if ($taken->fetchColumn()) {
			return ['status' => false, 'message' => _('That MAC address is already associated.')];
		}

		if ($id) {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->devicesTable}`
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
			"INSERT INTO `{$this->devicesTable}` (mac, device_id, profile_id)
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
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	private function saveProfile($request)
	{
		$id = (int) ($request['id'] ?? 0);
		$name = trim((string) ($request['name'] ?? ''));
		$template = (string) ($request['template'] ?? '');

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
				SET name = :name, template = :template
				WHERE id = :id"
			);
			$stmt->execute([
				':name' => $name,
				':template' => $template,
				':id' => $id,
			]);

			return ['status' => true, 'id' => $id, 'name' => $name];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->profilesTable}` (name, template) VALUES (:name, :template)"
		);
		$stmt->execute([
			':name' => $name,
			':template' => $template,
		]);

		return ['status' => true, 'id' => (int) $this->db->lastInsertId(), 'name' => $name];
	}

	/**
	 * Remove a device association.
	 *
	 * @param mixed $id Association id.
	 *
	 * @return array<string, mixed> Status of the removal.
	 */
	private function deleteDevice($id)
	{
		$stmt = $this->db->prepare("DELETE FROM `{$this->devicesTable}` WHERE id = :id");
		$stmt->execute([':id' => (int) $id]);

		return ['status' => true];
	}

	/**
	 * Remove a profile.
	 *
	 * A profile that devices still point at is kept, so an association never
	 * ends up naming a profile that has gone.
	 *
	 * @param mixed $id Profile id.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	private function deleteProfile($id)
	{
		$id = (int) $id;

		$count = $this->profileDeviceCount($id);

		if ($count) {
			return [
				'status' => false,
				'message' => sprintf(
					_('This profile is assigned to %s device(s). Reassign them first.'),
					$count
				),
			];
		}

		// Resources go with it. Unlike a device association, a resource has
		// no existence apart from the profile that serves it -- there is
		// nothing to reassign it to and nothing left for it to mean.
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
			SELECT id, profile_id, name, updated_at
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

		return [
			'total' => $total,
			'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
		];
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
			"SELECT id, profile_id, name, template
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
			// The profile is in the WHERE rather than trusted from the form:
			// a resource does not move between profiles, and an id from one
			// profile posted at another is not an edit of anything.
			$stmt = $this->db->prepare(
				"UPDATE `{$this->resourcesTable}`
				SET name = :name, template = :template
				WHERE id = :id AND profile_id = :profile_id"
			);
			$stmt->execute([
				':name' => $name,
				':template' => $template,
				':id' => $id,
				':profile_id' => $profileId,
			]);

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
	 * Nothing points at a resource the way a device association points at a
	 * profile, so there is nothing to refuse this for.
	 *
	 * @param mixed $id Resource id.
	 *
	 * @return array<string, mixed> Status of the removal.
	 */
	private function deleteResource($id)
	{
		$stmt = $this->db->prepare("DELETE FROM `{$this->resourcesTable}` WHERE id = :id");
		$stmt->execute([':id' => (int) $id]);

		return ['status' => true];
	}

	/**
	 * What a template can refer to, as the editor lists it.
	 *
	 * Written out here rather than derived from a rendering, because the
	 * editor has to be able to say what the names are with no device in hand
	 * -- a new profile is not assigned to anything yet. The `sip.` names are
	 * whatever the device carries in FreePBX, so the view names a few by way
	 * of example instead of listing them.
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
	 * Answer a device's request for a file and end the request.
	 *
	 * Called by engine/provisioner.php, which is the only route a phone can
	 * reach: FreePBX's config.php sends every session-less request to the
	 * login page long before a module's doConfigPageInit() runs.
	 *
	 *   serveConfig($mac)                          the profile's own template
	 *   serveConfig($mac, '0004f282e824-web.cfg')  a resource of that profile
	 *
	 * The second argument is the filename as it was asked for, not a resource
	 * id: which resource that names is the profile's business, and working it
	 * out is renderConfig()'s.
	 *
	 * The outcome is logged here rather than by the endpoint, because this is
	 * where the request ends and the endpoint never gets to see it.
	 *
	 * @param mixed       $mac       MAC address, written however it was written.
	 * @param string|null $requested Filename asked for, or null for the main config.
	 *
	 * @return void Never returns; the request ends here.
	 */
	public function serveConfig($mac, $requested = null)
	{
		$result = $this->renderConfig($mac, $requested);

		if (!$result['status']) {
			$this->FreePBX->Logger->log(FPBX_LOG_WARNING, sprintf(
				'oryk_provisioner: 404 for %s (%s)',
				(string) $requested !== '' ? (string) $requested : (string) $mac,
				$result['message']
			));

			$this->sendText(404, $result['message'] . "\n");
		}

		$this->sendText(200, $result['config'], $this->contentType((string) $result['resource']));
	}

	/**
	 * The configuration text a MAC provisions with.
	 *
	 * Separate from serveConfig() because this is the part worth calling
	 * again: a preview, a console command, a test. Only the caller there ends
	 * the request.
	 *
	 * With no filename this is the profile's own template -- the main config,
	 * the one file every profile has. With one, it is whichever of the
	 * profile's resources answers to that name; and the profile's template is
	 * still the answer for [mac].cfg when no resource claims it, so a profile
	 * that has never had a resource added renders exactly as it did before
	 * there were any.
	 *
	 * @param mixed       $mac       MAC address, written however it was written.
	 * @param string|null $requested Filename asked for, or null for the main config.
	 *
	 * @return array<string, mixed> Status, the rendered config when there is
	 *                              one, and a message when there is not.
	 */
	public function renderConfig($mac, $requested = null)
	{
		$mac = $this->normalizeMac($mac);

		if ($mac === '') {
			return [
				'status' => false,
				'message' => _('A MAC address is 12 hexadecimal characters, with or without separators.'),
			];
		}

		$row = $this->associationByMac($mac);

		if (!$row) {
			return [
				'status' => false,
				'message' => sprintf(_('%s is not associated with anything.'), $mac),
			];
		}

		if ($row['profile_id'] === null) {
			return [
				'status' => false,
				'message' => sprintf(_('%s has no profile assigned.'), $mac),
			];
		}

		$values = $this->provisioningValues($row);
		$requested = trim((string) $requested);

		// What is left of the filename with this device's own MAC off the
		// front: 0004f282e824-phone.cfg asked of that device is phone.cfg,
		// and 0004f282e824.cfg is .cfg. Worked out here rather than in the
		// endpoint, so the endpoint only has to report what was asked for.
		$suffix = $requested === '' ? '' : $this->resourceSuffix($requested, $mac);

		$match = $requested === ''
			? null
			: $this->matchResource((int) $row['profile_id'], $requested, $suffix, $values);

		if ($match === null) {
			// Nothing the profile serves claims the name, so the profile's
			// own template answers -- but only for the main config, which is
			// [mac].cfg, a bare [mac], or no filename at all. Anything else
			// is a file this profile does not have, and saying so beats
			// handing a phone the main config under a name it never asked
			// for and will not parse.
			if ($requested !== '' && $suffix !== '' && strcasecmp($suffix, '.cfg') !== 0) {
				return [
					'status' => false,
					'message' => sprintf(_('%s is not something this profile serves.'), $requested),
				];
			}

			$match = ['name' => '', 'template' => (string) $row['template']];
		}

		$out = [
			'status' => true,
			'mac' => $mac,
			'profile' => (string) $row['profile_name'],
			'resource' => (string) $match['name'],
			'config' => $this->renderTemplate((string) $match['template'], $values),
		];

		// Metadata only. A rendered config carries device.secret whenever a
		// template asks for it, and the log is not where that belongs.
		$this->FreePBX->Logger->log(FPBX_LOG_INFO, sprintf(
			'oryk_provisioner: %s served %s from profile %s',
			$mac,
			$out['resource'] !== '' ? $out['resource'] : 'the main config',
			$out['profile']
		));

		return $out;
	}

	/**
	 * Which of a profile's resources answers to a requested filename.
	 *
	 * Two ways, and a name written out in full wins:
	 *
	 *   {{device.mac}}-phone.cfg  rendered with this device's values and
	 *                             compared to what was actually asked for, so
	 *                             one resource covers every device on the
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
	 * @param string                $suffix    The same, with this device's MAC removed.
	 * @param array<string, string> $values    Placeholder name to value.
	 *
	 * @return array<string, mixed>|null The resource, or null when none answers.
	 */
	private function matchResource($profileId, $requested, $suffix, array $values)
	{
		$stmt = $this->db->prepare(
			"SELECT id, name, template
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
	 * A requested filename with this device's own MAC taken off the front.
	 *
	 * Phones ask by MAC, in whatever separator style they favour:
	 * 0004f282e824-phone.cfg, 00:04:f2:82:e8:24-phone.cfg and
	 * 0004f282e824.cfg are all one device asking. What is left is the part a
	 * resource can be named after -- phone.cfg, and .cfg for the main config,
	 * which is the one name the profile itself answers to.
	 *
	 * A filename that does not begin with this device's MAC comes back
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
	 * @param string $mac      This device's normalised MAC.
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
	 * What a rendered file is served as.
	 *
	 * Read off the name rather than stored against the resource: an author
	 * who called a file directory.xml has already said what it is, and a
	 * second field saying it again is a second field to get wrong. Anything
	 * unrecognised is plain text, which is what a configuration file is and
	 * what every phone here expects.
	 *
	 * @param string $name Resource name, or '' for the main config.
	 *
	 * @return string Content type, without the charset.
	 */
	private function contentType($name)
	{
		switch (strtolower((string) pathinfo($name, PATHINFO_EXTENSION))) {
			case 'xml':
				return 'text/xml';

			case 'json':
				return 'application/json';

			default:
				return 'text/plain';
		}
	}

	/**
	 * Write a plain-text response and end the request.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @param string $type Content type, without the charset.
	 *
	 * @return void Never returns.
	 */
	private function sendText($code, $body, $type = 'text/plain')
	{
		http_response_code($code);
		header('Content-Type: ' . $type . '; charset=utf-8');
		// A phone that re-reads its config expects what is stored now, not
		// what a cache kept from the last time it asked.
		header('Cache-Control: no-store');

		echo $body;
		exit;
	}

	/**
	 * The association a MAC names, with the device and profile behind it.
	 *
	 * One statement rather than three lookups: the whole of what rendering
	 * needs is one row wide.
	 *
	 * @param string $mac Normalised MAC address.
	 *
	 * @return array<string, mixed>|null The row, or null when the MAC is unknown.
	 */
	private function associationByMac($mac)
	{
		$stmt = $this->db->prepare(
			"SELECT
				pd.mac,
				pd.device_id,
				pd.profile_id,
				d.user AS extension,
				d.description,
				d.tech,
				p.name AS profile_name,
				p.template
			FROM `{$this->devicesTable}` pd
			LEFT JOIN devices d ON d.id = pd.device_id
			LEFT JOIN `{$this->profilesTable}` p ON p.id = pd.profile_id
			WHERE pd.mac = :mac"
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
	 * An association with no FreePBX device still renders -- everything the
	 * device would have answered for is simply empty, which is what a profile
	 * of pure static configuration wants anyway.
	 *
	 * @param array<string, mixed> $row Association row from associationByMac().
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
	 * The FreePBX devices an association can point at.
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
	 * The profiles an association can point at.
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
