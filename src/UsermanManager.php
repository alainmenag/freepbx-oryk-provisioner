<?php

// src/UsermanManager.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The User Manager account behind an Extension/User device.
 *
 * An account named after the extension, or carrying OWNER, is this
 * module's: it follows the extension and is deleted with it. An account that
 * merely has the extension assigned belongs to a person and is only ever
 * unassigned, never renamed or removed. Getting that backwards deletes
 * somebody's login, so every write checks which it holds.
 */
class UsermanManager extends Service
{
	/**
	 * The User Manager module setting that marks an account this module made
	 * under a custom username, so it is still owned once it is not named after
	 * the extension.
	 */
	const OWNER = ['oryk_provisioner', 'owned'];

	/** The User Manager module setting that says whether an account may log in to UCP. */
	const UCP = ['ucp|Global', 'allowLogin'];

	/**
	 * Whether User Manager is installed and enabled.
	 *
	 * @return bool True when accounts can be read and written.
	 */
	public function available()
	{
		return $this->moduleActive('userman');
	}

	/**
	 * The account a username and password log in as.
	 *
	 * Checked by User Manager against the account's directory, so the stored
	 * hash is never read here.
	 *
	 * @param string $username Username offered.
	 * @param string $password Password offered.
	 *
	 * @return array<string, mixed>|null The account, or null when they do not log in.
	 */
	public function authenticate($username, $password)
	{
		if (!$this->available()) {
			return null;
		}

		try {
			$userman = \FreePBX::Userman();
			$id = $userman->checkCredentials($username, $password);

			if (!$id) {
				return null;
			}

			$user = $userman->getUserByID($id);
		} catch (\Exception $e) {
			return null;
		}

		return empty($user['id']) ? null : $user;
	}

	/**
	 * Whether any account holds a username.
	 *
	 * @param string $username Username to look for.
	 *
	 * @return bool True when it is taken.
	 */
	public function usernameTaken($username)
	{
		if (!$this->available()) {
			return false;
		}

		try {
			$user = \FreePBX::Userman()->getUserByUsername($username);
		} catch (\Exception $e) {
			return false;
		}

		return !empty($user['id']);
	}

	/**
	 * Give the account this module owns for an extension a custom username
	 * and password -- the extension form's "Use Custom Username" -- and mark
	 * it OWNER so it stays owned once renamed.
	 *
	 * @param int|string $extension Extension/user number.
	 * @param string     $username  Username to log in with.
	 * @param string     $password  Password, as typed; User Manager hashes it.
	 *
	 * @return void
	 *
	 * @throws \Exception When there is no owned account or User Manager refuses.
	 */
	public function setLogin($extension, $username, $password)
	{
		$user = $this->ownedAccount($extension);

		if ($user === null) {
			throw new \Exception(sprintf('no User Manager account owned for %s', $extension));
		}

		$userman = \FreePBX::Userman();

		// Marked first: renamed and unmarked, the account would no longer be ours
		$userman->setModuleSettingByID($user['id'], self::OWNER[0], self::OWNER[1], '1');

		$status = $userman->updateUser(
			$user['id'],
			$user['username'],
			$username,
			$user['default_extension'] ?? $extension,
			$user['description'] ?? null,
			[],
			$password,
			true
		);

		if (is_array($status) && isset($status['status']) && !$status['status']) {
			throw new \Exception(trim(strip_tags((string) ($status['message'] ?? ''))));
		}
	}
	/**
	 * Switch UCP login off for the account this module owns for an extension:
	 * a per-user setting, which wins over whatever its groups allow.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return bool True when it was written.
	 */
	public function denyUcp($extension)
	{
		return $this->setUcpLogin($extension, false);
	}

	/**
	 * Undo denyUcp(): the per-user setting is cleared, so the account follows
	 * its groups again rather than being switched on for its own sake.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return bool True when it was written.
	 */
	public function restoreUcp($extension)
	{
		return $this->setUcpLogin($extension, null);
	}

	/**
	 * Write the per-user UCP login setting of an account this module owns.
	 *
	 * @param int|string $extension Extension/user number.
	 * @param bool|null  $allowed   False to refuse login; null to leave it to the groups.
	 *
	 * @return bool True when it was written.
	 */
	private function setUcpLogin($extension, $allowed)
	{
		if (!$this->available()) {
			return false;
		}

		try {
			$user = $this->ownedAccount($extension);

			if ($user === null) {
				return false;
			}

			\FreePBX::Userman()->setModuleSettingByID($user['id'], self::UCP[0], self::UCP[1], $allowed);
		} catch (\Exception $e) {
			$this->logError('unable to set UCP login for ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Look up the User Manager account tied to an extension.
	 *
	 * An account named after the extension is preferred over one that merely
	 * has it assigned.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return array<string, mixed>|null The account, or null when there is none.
	 */
	public function findByExtension($extension)
	{
		if (trim((string) $extension) === '' || !$this->moduleActive('userman')) {
			return null;
		}

		try {
			$userman = \FreePBX::Userman();
			$user = $userman->getUserByUsername($extension);

			if (empty($user['id'])) {
				$user = $userman->getUserByDefaultExtension($extension);
			}
		} catch (\Exception $e) {
			return null;
		}

		return empty($user['id']) ? null : $user;
	}

	/**
	 * The account this module owns for an extension, if it owns one.
	 *
	 * Owned means named after the extension, or marked OWNER. Everything that
	 * writes asks this first.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return array<string, mixed>|null The account, or null when this
	 *                                   module owns none for the extension.
	 */
	public function ownedAccount($extension)
	{
		$user = $this->findByExtension($extension);

		if (empty($user['id'])) {
			return null;
		}

		if ((string) $user['username'] === (string) $extension) {
			return $user;
		}

		try {
			$marked = \FreePBX::Userman()->getModuleSettingByID($user['id'], self::OWNER[0], self::OWNER[1]);
		} catch (\Exception $e) {
			$marked = false;
		}

		return (string) $marked === '1' ? $user : null;
	}

	/**
	 * Make sure a User Manager account exists for the given extension.
	 *
	 * Goes through Userman::processQuickCreate, like the extension screen, so
	 * the account gets the default directory, groups, UCP template and
	 * welcome email.
	 *
	 * @param int|string  $extension   Extension/user number.
	 * @param string|null $displayname Display name for a new account.
	 * @param string      $tech        Device technology.
	 * @param string|null $email       Email address for the account.
	 *
	 * @return bool True when the account exists after the call.
	 */
	public function ensure($extension, $displayname = null, $tech = 'pjsip', $email = null)
	{
		if (!$this->moduleActive('userman')) {
			return false;
		}

		if ($this->findByExtension($extension)) {
			return true;
		}

		try {
			$userman = \FreePBX::Userman();

			$userman->processQuickCreate($tech, $extension, [
				'um' => 'yes',
				'name' => ($displayname === null || $displayname === '') ? (string) $extension : $displayname,
				'email' => (string) $email,
				'um-groups' => [],
			]);
		} catch (\Exception $e) {
			$this->logError('unable to create userman user ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		$created = $userman->getUserByUsername($extension);

		return !empty($created['id']);
	}

	/**
	 * Keep the User Manager display name in step with the device description.
	 *
	 * Only an account this module owns is touched, and only its display
	 * name and email: every field left out of the update is carried
	 * over by User Manager, so groups and the rest survive.
	 *
	 * @param int|string  $extension   Extension/user number.
	 * @param string      $displayname Display name to store.
	 * @param string|null $email       Email to store, null to leave it alone.
	 *
	 * @return bool True when the account was updated.
	 */
	public function sync($extension, $displayname, $email = null)
	{
		if (!$this->moduleActive('userman')) {
			return false;
		}

		try {
			$userman = \FreePBX::Userman();

			// Never rename an account a person linked to this extension by hand
			$user = $this->ownedAccount($extension);

			if ($user === null) {
				return false;
			}

			$extraData = ['displayname' => $displayname];

			// A supplied email is written through, including a blank one to clear it
			if ($email !== null) {
				$extraData['email'] = $email;
			}

			$sameName = (string) ($user['displayname'] ?? '') === (string) $displayname;
			$sameEmail = $email === null || (string) ($user['email'] ?? '') === (string) $email;

			if ($sameName && $sameEmail) {
				return true;
			}

			$userman->updateUser(
				$user['id'],
				$user['username'],
				$user['username'],
				$user['default_extension'] ?? $extension,
				$user['description'] ?? null,
				$extraData,
				null,
				true
			);
		} catch (\Exception $e) {
			$this->logError('unable to update userman user ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Carry a User Manager account over to a new extension.
	 *
	 * Updated rather than replaced, so password, groups and UCP settings
	 * survive. This module's own account is renamed; a person's account only
	 * has the assignment moved.
	 *
	 * @param array<string, mixed>|null $account     Account on the old number.
	 * @param int|string                $old         Number being left behind.
	 * @param int|string                $new         Number being moved to.
	 * @param string                    $displayname Display name to store.
	 * @param string                    $tech        Device technology.
	 * @param string|null               $email       Email address for the account.
	 *
	 * @return bool True when an account is on the new number afterwards.
	 */
	public function move($account, $old, $new, $displayname, $tech = 'pjsip', $email = null)
	{
		if (!$this->moduleActive('userman')) {
			return false;
		}

		// Nothing to carry over: the new number gets a fresh account
		if (empty($account['id'])) {
			$this->ensure($new, $displayname, $tech, $email);

			return $this->sync($new, $displayname, $email);
		}

		$username = ((string) $account['username'] === (string) $old) ? (string) $new : $account['username'];
		$extraData = ['displayname' => $displayname];

		if ($email !== null) {
			$extraData['email'] = $email;
		}

		try {
			$status = \FreePBX::Userman()->updateUser(
				$account['id'],
				$account['username'],
				$username,
				(string) $new,
				$account['description'] ?? null,
				$extraData,
				null,
				true
			);

			if (is_array($status) && isset($status['status']) && !$status['status']) {
				throw new \Exception(trim(strip_tags((string) ($status['message'] ?? ''))));
			}
		} catch (\Exception $e) {
			$this->logError('unable to move userman user ' . $old . ' to ' . $new . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Delete the User Manager account this module owns for an extension.
	 *
	 * An account that merely has the extension assigned is left alone.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return bool True when an account was deleted.
	 */
	public function removeOwnedAccount($extension)
	{
		if (!$this->moduleActive('userman')) {
			return false;
		}

		try {
			$userman = \FreePBX::Userman();
			$user = $this->ownedAccount($extension);

			if ($user === null) {
				return false;
			}

			$userman->deleteUserByID($user['id']);
		} catch (\Exception $e) {
			$this->logError('unable to delete userman user ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}
}
