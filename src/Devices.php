<?php

// src/Devices.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * FreePBX's devices, listed, and the one thing changed about one here: the
 * user it is on. Deleting one is Users::deleteDeviceById().
 *
 * Every device, whatever its technology and whether or not it is a user's
 * own -- so the handsets on an extension and the devices on none
 * (Users::createDevice()) are here too. See ARCHITECTURE.md, "Devices".
 */
class Devices extends Service
{
	/** What `devices.user` holds for a device nobody is on: FreePBX's own word. */
	const NOBODY = 'none';

	/**
	 * Where a device is read from, with the extension it is on beside it.
	 * Aliased `u` so Users::SHAPE reads over it. Interpolated, so a literal.
	 */
	const FROM = 'FROM devices d LEFT JOIN users u ON u.extension = d.user';

	/** @var ExtensionManager */
	private $extensions;

	/**
	 * @param object           $freepbx    FreePBX application instance.
	 * @param ExtensionManager $extensions Core extensions, and a device's place on one.
	 */
	public function __construct($freepbx, ExtensionManager $extensions)
	{
		parent::__construct($freepbx);

		$this->extensions = $extensions;
	}

	/**
	 * Rows for the Devices table.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	public function listDevices()
	{
		// Written into the statement, so only these; anything else sorts by id.
		$sortable = [
			'id' => 'd.id + 0',
			'description' => 'd.description',
			'user' => 'd.user + 0',
			'client_mac' => 'client_mac',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['id'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$where = '';

		if ($search !== '') {
			$where = 'WHERE (d.id LIKE :search OR d.description LIKE :search OR d.user LIKE :search OR u.name LIKE :search)';
			$params[':search'] = '%' . $search . '%';
		}

		try {
			$countStmt = $this->db->prepare('SELECT COUNT(*) ' . self::FROM . " $where");
			$countStmt->execute($params);
			$total = (int) $countStmt->fetchColumn();

			$stmt = $this->db->prepare(
				'SELECT ' . $this->columns() . '
				' . self::FROM . "
				$where
				ORDER BY $sort $order, d.id
				LIMIT :limit OFFSET :offset"
			);
			foreach ($params as $key => $value) {
				$stmt->bindValue($key, $value, PDO::PARAM_STR);
			}
			$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
			$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
			$stmt->execute();

			return ['total' => $total, 'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC)];
		} catch (\Exception $e) {
			return ['total' => 0, 'rows' => []];
		}
	}

	/**
	 * One device, for its page.
	 *
	 * @param mixed $id Device id.
	 *
	 * @return array<string, mixed>|null The device, or null when there is none.
	 */
	public function deviceRow($id)
	{
		$id = trim((string) $id);

		if ($id === '') {
			return null;
		}

		try {
			$stmt = $this->db->prepare('SELECT ' . $this->columns() . ' ' . self::FROM . ' WHERE d.id = :id');
			$stmt->execute([':id' => $id]);
			$row = $stmt->fetch(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return null;
		}

		return $row ?: null;
	}

	/**
	 * Every extension a device can be put on: FreePBX's, not only the ones
	 * the Users list shows. Unpaged, like Users::userChoices().
	 *
	 * @return array<int, array<string, mixed>> Rows: extension, name.
	 */
	public function userChoices()
	{
		try {
			$stmt = $this->db->prepare('SELECT extension, name FROM users ORDER BY extension + 0, extension');
			$stmt->execute();

			return $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * Save from the device page: put the device on another user, or on none.
	 *
	 * The device is looked up and the user checked against `users`, so
	 * neither is taken on a request's word. Apply Config is raised, never run.
	 *
	 * @param array<string, mixed> $request id, and user: an extension, or
	 *                                      '' or 'none' for nobody.
	 *
	 * @return array<string, mixed> Status and the device's id, and `reload`
	 *                              when something changed; or a message.
	 */
	public function saveDevice($request)
	{
		$device = $this->deviceRow($request['id'] ?? '');

		if (!$device) {
			return ['status' => false, 'message' => _('That device no longer exists.')];
		}

		$id = (string) $device['id'];
		$old = (string) $device['user'];
		$new = trim((string) ($request['user'] ?? ''));
		$new = $new === '' ? self::NOBODY : $new;

		if ($new === $old) {
			return ['status' => true, 'id' => $id];
		}

		if ($new !== self::NOBODY && !$this->extensions->exists($new)) {
			return ['status' => false, 'message' => _('That user does not exist.')];
		}

		try {
			$this->extensions->assignDevice($id, $old, $new);
		} catch (\Exception $e) {
			$this->logError('unable to put device ' . $id . ' on ' . $new . ': ' . $e->getMessage());

			return ['status' => false, 'message' => _('The device could not be saved; see the FreePBX log.')];
		}

		if (function_exists('needreload')) {
			needreload();
		}

		return ['status' => true, 'id' => $id, 'reload' => true];
	}

	/**
	 * The columns a device row is read with, by the list and the page alike.
	 *
	 * `is_user` is 1 when its user is one the Users list shows, so has a page
	 * to link to; `own` is 1 on the device that is a user's own; `clients` is
	 * how many provisioner clients point at it, and `client_id` and
	 * `client_mac` the first of them, by id.
	 *
	 * @return string A SELECT list over FROM.
	 */
	private function columns()
	{
		return "d.id AS id, d.tech AS tech, d.description AS description, d.user AS user,
			u.name AS user_name,
			(u.extension IS NOT NULL AND " . Users::SHAPE . ") AS is_user,
			(d.tech = 'pjsip' AND d.user = d.id) AS own,
			(SELECT COUNT(*) FROM `{$this->clientsTable}` pc WHERE pc.device_id = d.id) AS clients,
			(SELECT pc.id FROM `{$this->clientsTable}` pc WHERE pc.device_id = d.id ORDER BY pc.id LIMIT 1) AS client_id,
			(SELECT pc.mac FROM `{$this->clientsTable}` pc WHERE pc.device_id = d.id ORDER BY pc.id LIMIT 1) AS client_mac";
	}
}
