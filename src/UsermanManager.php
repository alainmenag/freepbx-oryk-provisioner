<?php

// src/UsermanManager.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The User Manager account behind an Extension/User device.
 *
 * An account named after the extension is this module's: it follows the
 * extension and is deleted with it. An account that merely has the
 * extension assigned belongs to a person and is only ever unassigned, never
 * renamed or removed. Getting that backwards deletes somebody's login, so
 * every write checks which it holds.
 */
class UsermanManager extends Service
{
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
	 * Owned means named after the extension. Everything that writes asks
	 * this first.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return array<string, mixed>|null The account, or null when this
	 *                                   module owns none for the extension.
	 */
	public function ownedAccount($extension)
	{
		$user = $this->findByExtension($extension);

		if (empty($user['id']) || (string) $user['username'] !== (string) $extension) {
			return null;
		}

		return $user;
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
	 * Only an account whose username matches the extension is touched, and
	 * only its display name: every field left out of the update is carried
	 * over by User Manager, so email, groups and the rest survive.
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
