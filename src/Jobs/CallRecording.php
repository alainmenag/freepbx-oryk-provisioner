<?php

// src/Jobs/CallRecording.php

namespace FreePBX\Modules\Oryk_Provisioner\Jobs;

/**
 * Call Recording: every call to and from the user recorded (`force`), or
 * back to Core's default (`dontcare`). Asterisk reads these keys as calls
 * are made, so nothing is applied.
 */
class CallRecording extends Job
{
	const SERVICES = ['call-recording'];

	/**
	 * @return array<string, string> granted, revoked: a phrase each.
	 */
	public static function effects()
	{
		return ['granted' => _('every call is recorded'), 'revoked' => _('recording goes back to the default')];
	}

	/** The four calls it covers, as Core keys them under AMPUSER/<ext>/recording/. */
	const RECORDED = ['in/external', 'out/external', 'in/internal', 'out/internal'];

	/**
	 * Record every call.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function granted(array $event)
	{
		$this->set((string) $event['extension'], 'force');
	}

	/**
	 * Back to Core's default.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function revoked(array $event)
	{
		$this->set((string) $event['extension'], 'dontcare');
	}

	/**
	 * Write all four keys.
	 *
	 * @param string $extension The user.
	 * @param string $value     force|dontcare.
	 *
	 * @return void
	 */
	private function set($extension, $value)
	{
		foreach (self::RECORDED as $key) {
			$this->astdbPut($extension . '/recording/' . $key, $value);
		}
	}
}
