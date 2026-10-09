<?php

// src/DashboardNotices.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The module's notices on FreePBX's dashboard.
 *
 * Each is raised or cleared only when its condition changes, so a job that
 * asks every minute does not rewrite the dashboard every minute. Nothing here
 * throws: without the Notifications BMO there is simply no notice.
 */
class DashboardNotices extends Service
{
	/** Open provisioning reached ORYK_OPEN_PER_DAY_TOTAL. */
	const OPEN_CAP = 'OPEN_CAP';

	/** A sign-up has waited over a day for Apply Config. */
	const BRIDGE_STALE = 'BRIDGE_STALE';

	/** The module the notices are filed under. */
	const MODULE = 'oryk_provisioner';

	/**
	 * Raise a warning, unless it is already up.
	 *
	 * @param string $id      One of the constants above.
	 * @param string $text    What the dashboard says, translated.
	 * @param string $details More, translated.
	 *
	 * @return void
	 */
	public function raise($id, $text, $details = '')
	{
		$notifications = $this->notifications();

		try {
			if ($notifications && !$notifications->exists(self::MODULE, $id)) {
				$notifications->add_warning(self::MODULE, $id, $text, $details, '', true, true);
			}
		} catch (\Throwable $e) {
			$this->logWarning('could not raise notice ' . $id . ': ' . $e->getMessage());
		}
	}

	/**
	 * Take a notice down, if it is up.
	 *
	 * @param string $id One of the constants above.
	 *
	 * @return void
	 */
	public function clear($id)
	{
		$notifications = $this->notifications();

		try {
			if ($notifications && $notifications->exists(self::MODULE, $id)) {
				$notifications->delete(self::MODULE, $id);
			}
		} catch (\Throwable $e) {
			$this->logWarning('could not clear notice ' . $id . ': ' . $e->getMessage());
		}
	}

	/**
	 * FreePBX's Notifications BMO.
	 *
	 * @return object|null It, or null where there is none.
	 */
	private function notifications()
	{
		try {
			return $this->FreePBX->Notifications ?? null;
		} catch (\Throwable $e) {
			return null;
		}
	}
}
