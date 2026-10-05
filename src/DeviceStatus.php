<?php
// src/DeviceStatus.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Whether a PJSIP device is registered, asked of Asterisk one device at a time.
 *
 * Registrations are Asterisk's and live in no FreePBX table, so each answer
 * is one manager command, `pjsip show aor <id>`. Asked only for the rows a
 * page actually lists -- never for every device there is.
 */
class DeviceStatus extends Service
{
	/** What a device id may be made of: it is written into a CLI command. */
	const ID_PATTERN = '/^[A-Za-z0-9_.-]{1,40}$/';

	/**
	 * One device's registration.
	 *
	 * @param mixed $id   Device id.
	 * @param mixed $tech Its technology, as `devices.tech` has it.
	 *
	 * @return array<string, mixed> state (registered, unreachable,
	 *                              unregistered, unknown, or none for a
	 *                              device that does not register), contacts
	 *                              (how many), and address and rtt (ms, or
	 *                              null) of the one it is reached at.
	 */
	public function of($id, $tech = 'pjsip')
	{
		$id = (string) $id;

		if (strtolower((string) $tech) !== 'pjsip') {
			return self::answer('none');
		}

		if (!preg_match(self::ID_PATTERN, $id) || !$this->astmanReady()) {
			return self::answer('unknown');
		}

		try {
			$response = $this->astman->Command('pjsip show aor ' . $id);
		} catch (\Throwable $e) {
			return self::answer('unknown');
		}

		return self::parse(is_array($response) ? (string) ($response['data'] ?? '') : '');
	}

	/**
	 * `pjsip show aor` output, as a registration.
	 *
	 * A contact line is `Contact:  <aor>/<uri> <hash> <status> <rtt>`, with
	 * the status Avail, Unavail, or NonQual where the AOR is not qualified --
	 * registered, but nobody has asked whether it answers. One reachable
	 * contact is enough to call the device registered.
	 *
	 * @param string $output What the command printed.
	 *
	 * @return array<string, mixed> As of() returns it.
	 */
	public static function parse($output)
	{
		$contacts = [];

		foreach (preg_split('/\R/', (string) $output) ?: [] as $line) {
			// The legend at the top spells the columns in angle brackets.
			if (preg_match('/^\s*Contact:\s+([^\s<]\S*)\s+\S+\s+([A-Za-z]+)(?:\s+([0-9.]+|nan))?\s*$/', $line, $m)) {
				$uri = strpos($m[1], '/') !== false ? substr($m[1], strpos($m[1], '/') + 1) : $m[1];

				$contacts[] = [
					'status' => strtolower($m[2]),
					'address' => preg_match('/@([^;>]+)/', $uri, $at) ? $at[1] : $uri,
					'rtt' => (isset($m[3]) && is_numeric($m[3])) ? round((float) $m[3], 1) : null,
				];
			}
		}

		if (!$contacts) {
			return self::answer('unregistered');
		}

		$reached = null;

		foreach ($contacts as $contact) {
			if ($contact['status'] !== 'unavail') {
				$reached = $reached ?: $contact;
			}
		}

		return self::answer($reached ? 'registered' : 'unreachable', count($contacts), $reached ?: $contacts[0]);
	}

	/**
	 * One answer, in the shape every caller reads.
	 *
	 * @param string                    $state    registered|unreachable|unregistered|unknown|none.
	 * @param int                       $contacts How many contacts the AOR has.
	 * @param array<string, mixed>|null $contact  The one spoken for, or null.
	 *
	 * @return array<string, mixed> state, contacts, address, rtt.
	 */
	private static function answer($state, $contacts = 0, $contact = null)
	{
		return [
			'state' => $state,
			'contacts' => $contacts,
			'address' => $contact ? (string) $contact['address'] : '',
			'rtt' => $contact ? $contact['rtt'] : null,
		];
	}
}
