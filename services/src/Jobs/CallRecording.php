<?php

// services/src/Jobs/CallRecording.php

namespace FreePBX\Modules\Oryk_Services\Jobs;

/**
 * Call Recording: every external call, or every internal one, to and from
 * the user recorded (`yes`) or not (`no`). The two are one job; which keys
 * a step writes is its service. Asterisk reads these keys as calls are
 * made, so nothing is applied. `yes` rather than `force` so the user can
 * still stop one call's recording, and `no` rather than `dontcare` so a
 * take-away is a take-away whatever the route or trunk says.
 */
class CallRecording extends Job
{
	const SERVICES = ['call-recording-external', 'call-recording-internal'];

	/** The calls each service covers, as Core keys them under AMPUSER/<ext>/recording/. */
	const RECORDED = [
		'call-recording-external' => ['in/external', 'out/external'],
		'call-recording-internal' => ['in/internal', 'out/internal'],
	];

	/**
	 * @param string $slug Which of SERVICES.
	 *
	 * @return array<string, string> granted, revoked: a phrase each.
	 */
	public static function effects($slug = '')
	{
		$which = $slug === 'call-recording-internal' ? _('internal') : _('external');

		return [
			'granted' => sprintf(_('every %s call is recorded'), $which),
			'revoked' => sprintf(_('no %s call is recorded'), $which),
		];
	}

	/**
	 * Record every call the service covers.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function granted(array $event)
	{
		$this->set((string) $event['extension'], (string) $event['service'], 'yes');
	}

	/**
	 * Record none of them.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function revoked(array $event)
	{
		$this->set((string) $event['extension'], (string) $event['service'], 'no');
	}

	/**
	 * Write the service's keys.
	 *
	 * @param string $extension The user.
	 * @param string $slug      Which of SERVICES.
	 * @param string $value     yes|no.
	 *
	 * @return void
	 */
	private function set($extension, $slug, $value)
	{
		foreach (self::RECORDED[$slug] ?? [] as $key) {
			$this->astdbPut($extension . '/recording/' . $key, $value);
		}
	}
}
