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
	 * The whole module is one page with two tabs; the tables on it are filled
	 * over AJAX, so the only thing handed to the view is what the two forms
	 * need to offer as choices.
	 *
	 * @return string Rendered page output.
	 */
	public function showPage()
	{
		return load_view(__DIR__ . '/views/admin.php', [
			'freepbxDevices' => $this->freepbxDevices(),
			'profiles' => $this->profileChoices(),
		]);
	}

	/**
	 * Install the module.
	 *
	 * Both tables are created if they are not already there, so installing
	 * over an existing install leaves the data where it is.
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

		return true;
	}

	/**
	 * Uninstall the module.
	 *
	 * The tables are deliberately left in place.
	 *
	 * @return void
	 */
	public function uninstall()
	{
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
	 * @param string $page Current configuration page.
	 *
	 * @return void
	 */
	public function doConfigPageInit($page)
	{
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
			case 'getProfile':
			case 'saveDevice':
			case 'saveProfile':
			case 'deleteDevice':
			case 'deleteProfile':
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

			case 'getProfile':
				return $this->getProfile($_REQUEST['id'] ?? null);

			case 'saveDevice':
				return $this->saveDevice($_REQUEST);

			case 'saveProfile':
				return $this->saveProfile($_REQUEST);

			case 'deleteDevice':
				return $this->deleteDevice($_REQUEST['id'] ?? null);

			case 'deleteProfile':
				return $this->deleteProfile($_REQUEST['id'] ?? null);

			default:
				return null;
		}
	}

	/**
	 * Rows for the Devices table.
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
		$where = '';

		if ($search !== '') {
			$where = "WHERE pd.mac LIKE :search
				OR pd.device_id LIKE :search
				OR d.user LIKE :search
				OR d.description LIKE :search
				OR p.name LIKE :search";
			$params[':search'] = '%' . $search . '%';
		}

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
	 * One profile, for the edit form.
	 *
	 * @param mixed $id Profile id.
	 *
	 * @return array<string, mixed> Status and the profile.
	 */
	private function getProfile($id)
	{
		$stmt = $this->db->prepare(
			"SELECT id, name, template
			FROM `{$this->profilesTable}`
			WHERE id = :id"
		);
		$stmt->execute([':id' => (int) $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$row) {
			return ['status' => false, 'message' => _('Profile not found.')];
		}

		return ['status' => true, 'profile' => $row];
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

		$assigned = $this->db->prepare(
			"SELECT COUNT(*) FROM `{$this->devicesTable}` WHERE profile_id = :id"
		);
		$assigned->execute([':id' => $id]);
		$count = (int) $assigned->fetchColumn();

		if ($count) {
			return [
				'status' => false,
				'message' => sprintf(
					_('This profile is assigned to %s device(s). Reassign them first.'),
					$count
				),
			];
		}

		$stmt = $this->db->prepare("DELETE FROM `{$this->profilesTable}` WHERE id = :id");
		$stmt->execute([':id' => $id]);

		return ['status' => true];
	}

	/**
	 * A MAC address as it is stored: uppercase hexadecimal, no separators.
	 *
	 * @param mixed $mac MAC address as it was typed.
	 *
	 * @return string The normalised MAC, or an empty string when it is not one.
	 */
	private function normalizeMac($mac)
	{
		$mac = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));

		return preg_match('/^[0-9A-F]{12}$/', $mac) ? $mac : '';
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
