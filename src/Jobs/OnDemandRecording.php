<?php

// src/Jobs/OnDemandRecording.php

namespace FreePBX\Modules\Oryk_Provisioner\Jobs;

/**
 * On Demand Recording: the one-touch recording feature, enabled or disabled.
 */
class OnDemandRecording extends Job
{
	const SERVICES = ['on-demand-recording'];

	/**
	 * Enable it.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function granted(array $event)
	{
		$this->astdbPut((string) $event['extension'] . '/recording/ondemand', 'enabled');
	}

	/**
	 * Disable it.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function revoked(array $event)
	{
		$this->astdbPut((string) $event['extension'] . '/recording/ondemand', 'disabled');
	}
}
