<?php

// src/Freepbx.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * The only file that asks FreePBX about a device.
 *
 * Everything this module knows about a device, an extension and its SIP
 * settings is read here, so a FreePBX that has moved a table is one file's
 * problem. Every lookup degrades to empty data rather than throwing.
 */
class Freepbx extends Service
{
	/**
	 * The FreePBX devices a client can point at.
	 *
	 * @return array<int, array<string, mixed>> Device rows.
	 */
	public function freepbxDevices()
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
	 * Whether a FreePBX device still exists.
	 *
	 * @param string $deviceId FreePBX devices.id.
	 *
	 * @return bool True when the device is there.
	 */
	public function freepbxDeviceExists($deviceId)
	{
		$stmt = $this->db->prepare("SELECT id FROM devices WHERE id = :id");
		$stmt->execute([':id' => $deviceId]);

		return (bool) $stmt->fetchColumn();
	}

	/**
	 * The FreePBX device settings for a device id.
	 *
	 * `sip` is where core keeps them, one keyword per row, for both drivers.
	 *
	 * @param string|null $deviceId FreePBX devices.id.
	 *
	 * @return array<string, string> Keyword to value.
	 */
	public function deviceSipSettings($deviceId)
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
			// Keywords become placeholder names, so anything outside [A-Za-z0-9_] is
			// folded to an underscore rather than producing a name nothing can spell.
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
	public function extensionRow($extension)
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
}
