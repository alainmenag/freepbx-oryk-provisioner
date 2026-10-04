<?php

// src/LobbyContext.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The dialplan behind ORYK_OPEN_CONTEXT, written on Apply Config.
 *
 * When the setting is `lobby` this writes three contexts:
 *
 *   lobby       the calls-at-once limit, then on to lobby-dial
 *   lobby-dial  what a lobby phone may reach: extensions, conferences,
 *               voicemail, and the outbound routes flagged emergency
 *   lobby-deny  everything else: "no service"
 *
 * and, for every extension in the setting's context whatever its name, a
 * forward guard: a call to it that the phone redirects (a 302) is followed in
 * that context rather than from-internal, so a lobby phone cannot forward a
 * caller to the PSTN. See ARCHITECTURE.md, "Open provisioning".
 *
 * **Unverified on a PBX**: the context names it includes and the forward
 * guard are on the open-signup plan's checklist.
 */
class LobbyContext extends Service
{
	/** The context the module writes; any other setting is the admin's own. */
	const NAME = 'lobby';

	/** The contexts a lobby phone may reach, before the emergency routes. */
	const INCLUDES = ['ext-local', 'ext-meetme', 'app-vmmain', 'app-dialvm'];

	/** What a lobby phone may dial: a digit, *, # or +, then anything. */
	const PATTERNS = ['_[0-9*#+]', '_[0-9*#+].'];

	/** The group a lobby phone's calls are counted in. */
	const GROUP = 'oryk-lobby';

	/** @var Settings */
	private $settings;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Settings $settings)
	{
		parent::__construct($freepbx);

		$this->settings = $settings;
	}

	/**
	 * Write the lobby into the dialplan being generated.
	 *
	 * @param object $ext FreePBX's extensions object, as a dialplan hook gets it.
	 *
	 * @return void
	 */
	public function generate($ext)
	{
		$context = (string) $this->settings->get(Settings::OPEN_CONTEXT);

		if (!preg_match(Settings::CONTEXT_PATTERN, $context)) {
			return;
		}

		if ($context === self::NAME) {
			$this->contexts($ext, max(0, (int) $this->settings->get(Settings::OPEN_CALLS)));
		}

		$this->forwardGuards($ext, $context);
	}

	/**
	 * lobby, lobby-dial and lobby-deny.
	 *
	 * @param object $ext   FreePBX's extensions object.
	 * @param int    $calls ORYK_OPEN_CALLS; 0 is no limit.
	 *
	 * @return void
	 */
	private function contexts($ext, $calls)
	{
		$dial = self::NAME . '-dial';
		$deny = self::NAME . '-deny';

		foreach (self::PATTERNS as $pattern) {
			// Counted by endpoint, which a phone cannot set as it can its caller id;
			// a forwarded call arrives on a Local channel and is not a lobby phone's.
			if ($calls > 0) {
				$ext->add(self::NAME, $pattern, '', new \ext_gotoif('$["${CHANNEL(channeltype)}" != "PJSIP"]', 'dial'));
				$ext->add(self::NAME, $pattern, '', new \ext_set('GROUP(' . self::GROUP . ')', '${CHANNEL(endpoint)}'));
				$ext->add(self::NAME, $pattern, '', new \ext_gotoif('$[${GROUP_COUNT(${CHANNEL(endpoint)}@' . self::GROUP . ')} > ' . (int) $calls . ']', 'busy'));
			}

			$ext->add(self::NAME, $pattern, 'dial', new \ext_goto('1', '${EXTEN}', $dial));

			if ($calls > 0) {
				$ext->add(self::NAME, $pattern, 'busy', new \ext_playback('all-circuits-busy-now'));
				$ext->add(self::NAME, $pattern, '', new \ext_hangup());
			}

			$ext->add($deny, $pattern, '', new \ext_answer());
			$ext->add($deny, $pattern, '', new \ext_playback('ss-noservice'));
			$ext->add($deny, $pattern, '', new \ext_hangup());
		}

		// Searched in order, and the first that matches wins: deny is last.
		foreach (array_merge(self::INCLUDES, $this->emergencyRoutes()) as $include) {
			$ext->addInclude($dial, $include);
		}

		$ext->addInclude($dial, $deny);
	}

	/**
	 * Make a call to each lobby extension follow a redirect in the lobby.
	 *
	 * FORWARD_CONTEXT is where Dial places a call its callee redirected; set
	 * inherited on the caller before ext-local dials, so a lobby phone answering
	 * with a 302 to an outside number reaches lobby-deny.
	 *
	 * @param object $ext     FreePBX's extensions object.
	 * @param string $context ORYK_OPEN_CONTEXT.
	 *
	 * @return void
	 */
	private function forwardGuards($ext, $context)
	{
		foreach ($this->lobbyExtensions($context) as $extension) {
			try {
				$ext->splice('ext-local', $extension, 1, new \ext_set('__FORWARD_CONTEXT', $context));
			} catch (\Throwable $e) {
				$this->logWarning('could not guard forwards to lobby extension ' . $extension . ': ' . $e->getMessage());
			}
		}
	}

	/**
	 * The extensions whose device is in a context.
	 *
	 * @param string $context Context name.
	 *
	 * @return array<int, string> Extensions.
	 */
	public function lobbyExtensions($context)
	{
		try {
			$stmt = $this->db->prepare("SELECT id FROM sip WHERE keyword = 'context' AND data = :context ORDER BY id");
			$stmt->execute([':context' => (string) $context]);

			return array_values(array_filter(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []), 'ctype_digit'));
		} catch (\Exception $e) {
			return [];
		}
	}

	/**
	 * The dialplan contexts of the outbound routes flagged emergency.
	 *
	 * @return array<int, string> outrt-<id>, one per route.
	 */
	private function emergencyRoutes()
	{
		$routes = [];

		try {
			if (function_exists('core_routing_list')) {
				$routes = (array) core_routing_list();
			}
		} catch (\Throwable $e) {
			$this->logWarning('could not list outbound routes: ' . $e->getMessage());
		}

		return self::emergencyContexts($routes);
	}

	/**
	 * The contexts of the routes flagged emergency among some outbound routes.
	 *
	 * @param array<int, array<string, mixed>> $routes Core's route list: route_id, emergency_route.
	 *
	 * @return array<int, string> outrt-<id>.
	 */
	public static function emergencyContexts(array $routes)
	{
		$contexts = [];

		foreach ($routes as $route) {
			$id = (string) ($route['route_id'] ?? '');

			if (ctype_digit($id) && strtoupper((string) ($route['emergency_route'] ?? '')) === 'YES') {
				$contexts[] = 'outrt-' . $id;
			}
		}

		return $contexts;
	}
}
