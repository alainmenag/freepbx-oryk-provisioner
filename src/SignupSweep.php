<?php

// src/SignupSweep.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The minute job behind open provisioning: sign-ups out of the bridge once
 * Apply Config has written them, and the dashboard notices kept true.
 *
 * **It never reloads.** Only an admin's Apply Config writes a sign-up into the
 * PJSIP files; this notices that it has. See ARCHITECTURE.md, "The Realtime
 * bridge".
 */
class SignupSweep extends Service
{
	/** Seconds a sign-up may wait for Apply Config before BRIDGE_STALE goes up. */
	const STALE_AFTER = 86400;

	/** @var Clients */
	private $clients;

	/** @var RealtimeBridge */
	private $bridge;

	/** @var Settings */
	private $settings;

	/** @var Notices */
	private $notices;

	/** @var string Asterisk's config directory. */
	private $etc;

	/**
	 * @param object      $freepbx FreePBX application instance.
	 * @param string|null $etc     Asterisk's config directory, if not ASTETCDIR.
	 */
	public function __construct($freepbx, Clients $clients, RealtimeBridge $bridge, Settings $settings, Notices $notices, $etc = null)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->bridge = $bridge;
		$this->settings = $settings;
		$this->notices = $notices;

		if ($etc === null) {
			try {
				$etc = (string) \FreePBX::Config()->get('ASTETCDIR');
			} catch (\Throwable $e) {
				$etc = '';
			}
		}

		$this->etc = rtrim($etc !== '' ? $etc : '/etc/asterisk', '/');
	}

	/**
	 * One run.
	 *
	 * @return array<string, int> provisioned (sign-ups taken out of the
	 *                            bridge), pending (still waiting).
	 */
	public function run()
	{
		$pending = $this->clients->pendingSignups();
		$done = [];

		if ($pending && !$this->reloadPending()) {
			$written = $this->writtenEndpoints();

			foreach ($pending as $signup) {
				$extension = (string) $signup['device_id'];

				// Either way it is no longer waiting: a client whose user was
				// deleted has nothing to wait for.
				if ($extension === '' || isset($written[$extension]) || !\FreePBX::Core()->getDevice($extension)) {
					$this->bridge->remove($extension);
					$done[] = (int) $signup['id'];
				}
			}

			$this->clients->markProvisioned($done);
		}

		$waiting = array_values(array_filter($pending, function ($signup) use ($done) {
			return !in_array((int) $signup['id'], $done, true);
		}));

		$this->staleNotice($waiting);
		$this->capNotice();

		return ['provisioned' => count($done), 'pending' => count($waiting)];
	}

	/**
	 * Raise OPEN_CAP. Called when the PBX-wide limit refuses a sign-up; run()
	 * takes it down again.
	 *
	 * @param int $cap ORYK_OPEN_PER_DAY_TOTAL.
	 *
	 * @return void
	 */
	public function capReached($cap)
	{
		$this->notices->raise(Notices::OPEN_CAP, sprintf(
			_('Open provisioning has reached its daily limit of %d sign-ups; new phones are refused until older sign-ups are a day old.'),
			(int) $cap
		), _('Settings -> Sign-ups per Day, PBX (ORYK_OPEN_PER_DAY_TOTAL). Existing logins are not affected.'));
	}

	/**
	 * Whether a sign-up made now would be one only the bridge knows of: Apply
	 * Config is waiting.
	 *
	 * A sign-up that lands during an apply can end with the flag clear and its
	 * endpoint still not written, which is why run() also reads the file.
	 *
	 * @return bool True while FreePBX says Apply Config is needed, or when that
	 *              cannot be asked.
	 */
	private function reloadPending()
	{
		if (function_exists('check_reload_needed')) {
			return (bool) check_reload_needed();
		}

		try {
			$stmt = $this->db->prepare("SELECT value FROM admin WHERE variable = 'need_reload'");
			$stmt->execute();

			return strtolower((string) $stmt->fetchColumn()) !== 'false';
		} catch (\Exception $e) {
			return true;
		}
	}

	/**
	 * The ids Apply Config wrote a section for, read off pjsip.endpoint.conf.
	 *
	 * @return array<string, true> Section names, as keys.
	 */
	private function writtenEndpoints()
	{
		$text = (string) @file_get_contents($this->etc . '/pjsip.endpoint.conf');

		preg_match_all('/^\[([^\]\r\n]+)\]/m', $text, $matches);

		return array_fill_keys($matches[1], true);
	}

	/**
	 * BRIDGE_STALE, up while any sign-up has waited over STALE_AFTER.
	 *
	 * @param array<int, array<string, mixed>> $waiting Sign-ups still waiting.
	 *
	 * @return void
	 */
	private function staleNotice(array $waiting)
	{
		$stale = array_filter($waiting, function ($signup) {
			return (int) $signup['age'] > self::STALE_AFTER;
		});

		if (!$stale) {
			$this->notices->clear(Notices::BRIDGE_STALE);

			return;
		}

		$this->notices->raise(Notices::BRIDGE_STALE, sprintf(
			ngettext(
				'%d phone signed up through open provisioning has waited over a day for Apply Config.',
				'%d phones signed up through open provisioning have waited over a day for Apply Config.',
				count($stale)
			),
			count($stale)
		), _('They work through the Realtime bridge until Apply Config writes them. If this stays up after Apply Config, the minute job is not running or cannot read pjsip.endpoint.conf.'));
	}

	/**
	 * OPEN_CAP, down once the PBX is back under its daily limit.
	 *
	 * @return void
	 */
	private function capNotice()
	{
		$cap = (int) $this->settings->get(Settings::OPEN_PER_DAY_TOTAL);

		if ($cap <= 0 || $this->clients->signupsSince(86400) < $cap) {
			$this->notices->clear(Notices::OPEN_CAP);
		}
	}
}
