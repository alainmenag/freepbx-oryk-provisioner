<?php

// src/ExtensionManager.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The Core extension behind an Extension/User device, and the state
 * Asterisk reads about it.
 *
 * An extension is in two places at once: rows in the database, which are
 * what FreePBX shows, and keys in the Asterisk database, which are what
 * Asterisk acts on. Core normally keeps the two in step, and the places it
 * does not are the reason this class exists.
 *
 * Writing only the name rather than deleting and re-adding the user is one
 * of those places: the extension screen in FreePBX rebuilds the whole
 * extension, which resets voicemail, ring timers and everything else this
 * module does not own. Clearing the Asterisk keys by hand is another: Core
 * skips them in the edit mode a renumbering has to use to protect a
 * mailbox, so a number retired that way keeps its keys unless they are
 * taken out here.
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
	 * Core::addUser() reads the mailbox before a renumbering has moved it,
	 * so the extension is left naming a context its mailbox is no longer in,
	 * or naming none at all. The value has to be written back once the box
	 * has actually moved, in both of the places Core keeps it: the row
	 * FreePBX reads, and the Asterisk key the dialplan reads.
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
	 * Only the name is touched. Deleting and re-adding the user the way the
	 * FreePBX extension screen does would reset voicemail, ring timers and
	 * every other setting this module does not own.
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
	 * Core clears these itself when it deletes a user outright, and skips
	 * them in edit mode. A renumbering that has to use edit mode to protect a
	 * mailbox still wants them gone, so they are removed here.
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
