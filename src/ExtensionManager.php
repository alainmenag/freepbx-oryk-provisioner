<?php

// src/ExtensionManager.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The Core extension behind an Extension/User device, and the state
 * Asterisk reads about it.
 *
 * An extension lives in database rows (what FreePBX shows) and Asterisk
 * database keys (what Asterisk acts on). This covers the places Core does
 * not keep the two in step: writing only the name, since rebuilding the
 * extension would reset settings this module does not own, and clearing
 * the keys Core skips in the edit mode a renumbering uses.
 */
class ExtensionManager extends Service
{
	/**
	 * Determine whether a user/extension already exists.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return bool True when the user is present in the users table.
	 */
	public function exists($extension)
	{
		$sth = $this->db->prepare('SELECT extension FROM users WHERE extension = ? LIMIT 1');
		$sth->execute([$extension]);

		return (bool) $sth->fetchColumn();
	}

	/**
	 * Determine whether any device is still assigned to a user/extension.
	 *
	 * @param int|string $user Extension/user number.
	 *
	 * @return bool True when at least one device references the user.
	 */
	public function hasDevices($user)
	{
		$sth = $this->db->prepare('SELECT id FROM devices WHERE user = ? LIMIT 1');
		$sth->execute([$user]);

		return (bool) $sth->fetchColumn();
	}

	/**
	 * Write the voicemail context an extension's mailbox is in.
	 *
	 * Core::addUser() reads the mailbox before a renumbering moves it, so this
	 * must run after the box has moved. Writes both the row FreePBX reads and
	 * the Asterisk key the dialplan reads.
	 *
	 * @param int|string $extension Extension to write.
	 * @param string     $context   Voicemail context the mailbox is in.
	 *
	 * @return bool True when the row was written.
	 */
	public function setVoicemailContext($extension, $context)
	{
		$sth = $this->db->prepare('UPDATE users SET voicemail = ? WHERE extension = ?');
		$sth->execute([$context, $extension]);

		if ($this->astmanReady()) {
			$this->astman->database_put('AMPUSER', $extension . '/voicemail', $context);
		}

		return true;
	}

	/**
	 * Make sure a user/extension exists for the given id.
	 *
	 * Device kinds flagged with `creates_user` are their own user, so the
	 * matching row in the FreePBX users table is created when it is missing.
	 *
	 * @param int|string  $extension   Extension/user number.
	 * @param string|null $displayname Display name used for a new user.
	 *
	 * @return bool True when the user exists after the call.
	 */
	public function ensure($extension, $displayname = null)
	{
		if (trim((string) $extension) === '') {
			return false;
		}

		if ($this->exists($extension)) {
			return true;
		}

		$settings = \FreePBX::Core()->generateDefaultUserSettings(
			$extension,
			($displayname === null || $displayname === '') ? (string) $extension : $displayname
		);

		// Link the user to the device carrying the same id.
		$settings['device'] = (string) $extension;

		try {
			\FreePBX::Core()->addUser($extension, $settings);
		} catch (\Exception $e) {
			$this->logError('unable to create user ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Keep the user/extension name in step with the device description.
	 *
	 * Only the name is touched, so settings this module does not own survive.
	 *
	 * @param int|string $extension Extension/user number.
	 * @param string     $name      Name to store.
	 *
	 * @return bool True when the name was written.
	 */
	public function syncName($extension, $name)
	{
		if (trim((string) $extension) === '' || !$this->exists($extension)) {
			return false;
		}

		$sth = $this->db->prepare('UPDATE users SET name = ? WHERE extension = ?');
		$sth->execute([$name, $extension]);

		// The same value addUser() writes: Asterisk reads it for the caller id name
		if ($this->astmanReady()) {
			$this->astman->database_put('AMPUSER', $extension . '/cidname', $name);
		}

		return true;
	}

	/**
	 * Point every device registered against one extension at another.
	 *
	 * Handsets and softphones carry the extension they belong to, so they
	 * have to follow it when an Extension/User device is renumbered.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return int How many devices were moved.
	 */
	public function repointDevices($old, $new)
	{
		$sth = $this->db->prepare('SELECT id FROM devices WHERE user = ? AND id != ?');
		$sth->execute([$old, $new]);
		$ids = $sth->fetchAll(\PDO::FETCH_COLUMN);

		if (!$ids) {
			return 0;
		}

		$update = $this->db->prepare('UPDATE devices SET user = ? WHERE id = ?');
		$connected = $this->astmanReady();

		foreach ($ids as $device) {
			$update->execute([$new, $device]);

			if ($connected) {
				$this->astman->database_put('DEVICE', $device . '/user', $new);
				$this->astman->database_put('DEVICE', $device . '/default_user', $new);
			}
		}

		if ($connected) {
			$linked = explode('&', (string) $this->astman->database_get('AMPUSER', $new . '/device'));
			$linked = array_unique(array_filter(array_merge($linked, $ids), 'strlen'));

			$this->astman->database_put('AMPUSER', $new . '/device', implode('&', $linked));
		}

		return count($ids);
	}

	/**
	 * Take an extension's Asterisk database entries out.
	 *
	 * Core skips these in the edit mode a renumbering uses to protect a
	 * mailbox, so they would otherwise outlive the number.
	 *
	 * @param int|string $extension Number being retired.
	 *
	 * @return void
	 */
	public function forgetAstDb($extension)
	{
		if (!$this->astmanReady()) {
			return;
		}

		foreach (['AMPUSER/', 'CustomDevstate/FOLLOWME', 'DEVICE/', 'ZULU/'] as $family) {
			$this->astman->database_deltree($family . $extension);
		}
	}
}
