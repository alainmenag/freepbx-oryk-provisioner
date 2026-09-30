<?php

// src/UcpAssignments.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * What an account is allowed to open.
 *
 * User Manager stores the extensions a UCP account may see as a list per
 * module, and Sangoma Connect keeps a row of its own naming the device.
 * Both hold the number outright rather than following the account's own
 * extension, so neither notices when a number moves or goes away.
 *
 * Left behind, the two failures look different to the person and are the
 * same mistake. A stale assignment after a renumber opens the call history
 * and voicemail of an extension that is no longer there, which reads as
 * though the records were lost with the number. A stale assignment after a
 * deletion shows an extension that is not assigned to them rather than one
 * that is gone.
 *
 * These tables are absent on installs without UCP or without Sangoma
 * Connect, which is the usual reason either method does nothing.
 */
class UcpAssignments extends Service
{
	/**
	 * The settings tables holding what each account and group may open.
	 */
	const TABLES = ['userman_users_settings', 'userman_groups_settings'];

	/**
	 * Move the extension in the settings that decide what an account may see.
	 *
	 * User Manager stores the extensions a UCP account is allowed to open as
	 * a list per module, and Sangoma Connect keeps its own row naming the
	 * device. Both hold the number outright, so an account left pointing at
	 * the old one opens its call history and voicemail on an extension that
	 * is no longer there, which reads to the person as though the records
	 * were lost along with the number.
	 *
	 * Accounts set to follow their own extension rather than a number are
	 * already correct and are left alone.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return int How many settings were moved.
	 */
	public function move($old, $new)
	{
		$moved = 0;

		foreach (self::TABLES as $table) {
			try {
				// Matched on the stored value rather than on the account, so
				// this does not depend on how either table is keyed
				$sth = $this->db->prepare(
					'SELECT DISTINCT val FROM `' . $table . '` WHERE `key` = ? AND module LIKE ?'
				);
				$sth->execute(['assigned', 'ucp|%']);
				$values = $sth->fetchAll(\PDO::FETCH_COLUMN);
			} catch (\Exception $e) {
				continue; // the table is not there on this install
			}

			$update = null;

			foreach ($values as $val) {
				$assigned = json_decode((string) $val, true);

				if (!is_array($assigned) || !in_array((string) $old, array_map('strval', $assigned), true)) {
					continue;
				}

				// array_values() keeps this a JSON list rather than an object,
				// and array_unique() stops an account already holding both
				// numbers from ending up with the new one listed twice
				$replaced = json_encode(array_values(array_unique(array_map(
					function ($extension) use ($old, $new) {
						return ((string) $extension === (string) $old) ? (string) $new : (string) $extension;
					},
					$assigned
				))));

				try {
					$update = $update ?: $this->db->prepare(
						'UPDATE `' . $table . '` SET val = ? WHERE `key` = ? AND module LIKE ? AND val = ?'
					);
					$update->execute([$replaced, 'assigned', 'ucp|%', $val]);
					$moved += $update->rowCount();
				} catch (\Exception $e) {
					$this->logError('unable to move UCP assignment ' . $old . ' to ' . $new . ': ' . $e->getMessage());
				}
			}
		}

		// Sangoma Connect names the device it registered for the account, and
		// the call history query reads it to find those calls
		try {
			$sth = $this->db->prepare('UPDATE webrtc_clients SET `user` = ? WHERE `user` = ?');
			$sth->execute([$new, $old]);
			$moved += $sth->rowCount();
		} catch (\Exception $e) {
			// Sangoma Connect is not installed on this system
		}

		return $moved;
	}

	/**
	 * Take an extension out of the settings that decide what an account sees.
	 *
	 * An account this module owns goes with the extension, but one belonging
	 * to a person outlives it with the number still listed among the
	 * extensions they may open. UCP shows that as an extension that is not
	 * assigned to them rather than as one that is gone.
	 *
	 * @param int|string $extension Number being deleted.
	 *
	 * @return int How many settings were changed.
	 */
	public function forget($extension)
	{
		$changed = 0;

		foreach (self::TABLES as $table) {
			try {
				$sth = $this->db->prepare(
					'SELECT DISTINCT val FROM `' . $table . '` WHERE `key` = ? AND module LIKE ?'
				);
				$sth->execute(['assigned', 'ucp|%']);
				$values = $sth->fetchAll(\PDO::FETCH_COLUMN);
			} catch (\Exception $e) {
				continue; // the table is not there on this install
			}

			$update = null;

			foreach ($values as $val) {
				$assigned = json_decode((string) $val, true);

				if (!is_array($assigned)) {
					continue;
				}

				$kept = array_values(array_filter($assigned, function ($listed) use ($extension) {
					return (string) $listed !== (string) $extension;
				}));

				if (count($kept) === count($assigned)) {
					continue;
				}

				try {
					$update = $update ?: $this->db->prepare(
						'UPDATE `' . $table . '` SET val = ? WHERE `key` = ? AND module LIKE ? AND val = ?'
					);
					$update->execute([json_encode($kept), 'assigned', 'ucp|%', $val]);
					$changed += $update->rowCount();
				} catch (\Exception $e) {
					$this->logError('unable to unassign ' . $extension . ': ' . $e->getMessage());
				}
			}
		}

		// Any web client registered against the extension, whichever module
		// put it there. The table is absent unless one of them is installed,
		// which is the common reason this does nothing.
		try {
			$sth = $this->db->prepare('DELETE FROM webrtc_clients WHERE `user` = ?');
			$sth->execute([$extension]);
			$changed += $sth->rowCount();
		} catch (\Exception $e) {
			$this->logWarning('no web client rows removed for ' . $extension . ': ' . $e->getMessage());
		}

		return $changed;
	}
}
