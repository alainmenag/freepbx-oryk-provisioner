<?php

// src/Settings.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The module's PBX-wide settings, shown in Advanced Settings and on the
 * Settings tab.
 *
 * Both are the same FreePBX setting -- one row in `freepbx_settings` -- so a
 * value changed in either place is the value the other shows. A new setting
 * is one entry in definitions() and nothing else: it is registered on the
 * next install, drawn on the tab, validated and saved. See ARCHITECTURE.md,
 * "Settings".
 */
class Settings extends Service
{
	/** The PBX-wide hostname. */
	const HOSTNAME = 'ORYK_HOSTNAME';

	/**
	 * The PBX-wide from domain. The keyword oryk_connect registered, kept so
	 * taking it over keeps its value.
	 */
	const FROM_DOMAIN = 'ORYK_FROM_DOMAIN';

	/**
	 * Whether the all-zero MAC logs in, or creates a user, with its credentials.
	 * OPEN, CLOSED or DISABLED (every request refused with a 503); CLOSED
	 * unless an admin changes it.
	 */
	const PROVISIONING = 'ORYK_PROVISIONING';

	/**
	 * Whether bans are synced with fail2ban. Off pauses it: nothing is read
	 * from or written to fail2ban, and nothing already there is undone.
	 */
	const FAIL2BAN_SYNC = 'ORYK_FAIL2BAN_SYNC';

	/**
	 * How many times a Banned ban may come into force before it is made Deny.
	 * Blank is off. See BanEscalation.
	 */
	const BAN_DENY_AFTER = 'ORYK_BAN_DENY_AFTER';

	/** The context every open-provisioning sign-up is put in. See LobbyContext. */
	const OPEN_CONTEXT = 'ORYK_OPEN_CONTEXT';

	/** Open-provisioning sign-ups one address may make in a minute; 0 is no limit. */
	const OPEN_PER_MINUTE = 'ORYK_OPEN_PER_MINUTE';

	/** Open-provisioning sign-ups one address may make in a rolling day; 0 is no limit. */
	const OPEN_PER_DAY = 'ORYK_OPEN_PER_DAY';

	/** Open-provisioning sign-ups the whole PBX takes in a rolling day; 0 is no limit. */
	const OPEN_PER_DAY_TOTAL = 'ORYK_OPEN_PER_DAY_TOTAL';

	/** Calls one lobby extension may place at once; 0 is no limit. */
	const OPEN_CALLS = 'ORYK_OPEN_CALLS';

	/** The emergency caller id a sign-up is given; blank leaves Core's (the extension). */
	const OPEN_EMERGENCY_CID = 'ORYK_OPEN_EMERGENCY_CID';

	/** Days a lobby user may go unseen before the Users tab lists it as expired; 0 is off. */
	const OPEN_EXPIRE_DAYS = 'ORYK_OPEN_EXPIRE_DAYS';

	/** What a context name may be: it is written into the dialplan and the bridge. */
	const CONTEXT_PATTERN = '/^[A-Za-z0-9_-]{1,79}$/';

	/**
	 * What every setting is filed under in Advanced Settings.
	 */
	const CATEGORY = 'Oryk Provisioner';

	/**
	 * Keywords an earlier version registered and this one does not, removed
	 * by register() so Advanced Settings stops offering them.
	 */
	const RETIRED = ['ORYK_FAIL2BAN'];

	/**
	 * Every setting this module provides, in the order they are shown.
	 *
	 * Keyed by FreePBX keyword. Each:
	 *
	 *   name        label, untranslated -- translated where it is drawn
	 *   description help text, untranslated
	 *   type        text|int|bool|select
	 *   default     value a fresh install starts with
	 *   pattern     text: a regex a non-empty value must match
	 *   emptyok     text: whether blank is allowed
	 *   min, max    int: inclusive bounds
	 *   options     select: value => label
	 *
	 * The keyword is written into `freepbx_settings` and posted back from the
	 * tab, so it is only ever looked up here, never taken from a request.
	 *
	 * @return array<string, array<string, mixed>> Definitions, by keyword.
	 */
	public function definitions()
	{
		return [
			self::HOSTNAME => [
				'name' => 'Hostname',
				'description' => 'The name phones register to, as {{server.host}} in a template, and the '
					. 'From Domain when that is blank. Left blank, a template gets the host each request '
					. 'arrived on, and From Domain the hostname of this machine.',
				'type' => 'text',
				'default' => '',
				'pattern' => EndpointSettings::DOMAIN_PATTERN,
				'emptyok' => true,
			],
			self::FROM_DOMAIN => [
				'name' => 'From Domain',
				'description' => 'The domain a PJSIP endpoint puts in the From header. '
					. 'It is written to pjsip.endpoint_custom_post.conf on the next save of each '
					. 'user, and a user given a From Domain of its own uses that instead. '
					. 'Left blank, the Hostname setting is used, or the hostname of this machine when '
					. 'that is a domain name, and nothing is written when neither is.',
				'type' => 'text',
				'default' => '',
				'pattern' => EndpointSettings::DOMAIN_PATTERN,
				'emptyok' => true,
			],
			self::PROVISIONING => [
				'name' => 'Provisioning',
				'description' => 'Open: a request for the MAC address 000000000000 logs in with its '
					. 'credentials as a User Manager login, a user is created for a username no account '
					. 'has, and the request is served as that user\'s client. Closed: that request is '
					. 'handled like any other MAC. Disabled: every request to the provisioning endpoint '
					. 'is refused with a 503.',
				'type' => 'select',
				'default' => 'CLOSED',
				'options' => [
					'OPEN' => 'Open (000000000000 logs in, or registers, with its credentials)',
					'CLOSED' => 'Closed (only existing clients are served)',
					'DISABLED' => 'Disabled (every request is refused)',
				],
			],
			self::FAIL2BAN_SYNC => [
				'name' => 'Fail2ban Sync',
				'description' => 'Keep IP bans in step with fail2ban, both ways, every minute: fail2ban\'s bans '
					. 'appear on the Bans tab, Banned and Deny bans made there are banned (every port) in the '
					. 'banned and deny jails, and Allow puts the address on the ignore lists of the PBX jails setup chose -- '
					. 'never sshd\'s. Needs the setup '
					. 'script run once as root. Off pauses it; nothing already in fail2ban is undone.',
				'type' => 'bool',
				'default' => true,
			],
			self::BAN_DENY_AFTER => [
				'name' => 'Deny After',
				'description' => 'Turn a Banned ban into a Deny when it comes into force this many times -- the '
					. 'Times column -- whether fail2ban banned the address again or it was banned again here. '
					. 'From 2 to 1000; blank is off. Changing a ban back to Banned by hand is not overruled '
					. 'until it comes back into force again.',
				'type' => 'int',
				'emptyok' => true,
				'min' => 2,
				'max' => 1000,
				'default' => '',
			],
			self::OPEN_CONTEXT => [
				'name' => 'Sign-up Context',
				'description' => 'The context every user open provisioning creates is put in. The module generates '
					. 'lobby: internal extensions, conferences, voicemail and emergency routes only, no other '
					. 'outbound route, and no forward or transfer out of it. Any other name is a context you '
					. 'provide yourself. Promote moves a user out of it. Takes effect on Apply Config.',
				'type' => 'text',
				'default' => 'lobby',
				'pattern' => self::CONTEXT_PATTERN,
			],
			self::OPEN_PER_MINUTE => [
				'name' => 'Sign-ups per Minute',
				'description' => 'How many users open provisioning may create for one address (an IPv6 /64) '
					. 'in a minute. 0 is no limit. An Allow ban on the address lifts every limit.',
				'type' => 'int',
				'min' => 0,
				'max' => 1000,
				'default' => '1',
			],
			self::OPEN_PER_DAY => [
				'name' => 'Sign-ups per Day',
				'description' => 'How many users open provisioning may create for one address (an IPv6 /64) '
					. 'in any 24 hours. 0 is no limit. An Allow ban on the address lifts every limit.',
				'type' => 'int',
				'min' => 0,
				'max' => 100000,
				'default' => '5',
			],
			self::OPEN_PER_DAY_TOTAL => [
				'name' => 'Sign-ups per Day, PBX',
				'description' => 'How many users open provisioning may create in any 24 hours, from every '
					. 'address together. 0 is no limit. Reaching it puts a notice on the dashboard.',
				'type' => 'int',
				'min' => 0,
				'max' => 1000000,
				'default' => '0',
			],
			self::OPEN_CALLS => [
				'name' => 'Lobby Calls',
				'description' => 'How many calls one lobby extension may place at once. 0 is no limit. '
					. 'Takes effect on Apply Config.',
				'type' => 'int',
				'min' => 0,
				'max' => 100,
				'default' => '1',
			],
			self::OPEN_EMERGENCY_CID => [
				'name' => 'Lobby Emergency Caller ID',
				'description' => 'The emergency caller id a user open provisioning creates is given -- normally '
					. 'the site\'s main number, which an emergency operator can call back. Left blank, it is the '
					. 'extension itself, which means nothing outside this PBX. Check what your jurisdiction '
					. 'requires of emergency calls from a multi-line system.',
				'type' => 'text',
				'default' => '',
				'pattern' => '/^\+?[0-9]{1,20}$/',
				'emptyok' => true,
			],
			self::OPEN_EXPIRE_DAYS => [
				'name' => 'Lobby Expiry',
				'description' => 'After this many days without its phone being seen -- or, never seen, this many '
					. 'days after it signed up -- a lobby user is listed under Expired on the Users tab, '
					. 'where it can be deleted. Nothing is deleted on its own. 0 is off.',
				'type' => 'int',
				'min' => 0,
				'max' => 3650,
				'default' => '0',
			],
		];
	}

	/**
	 * What a save is allowed to do but should say something about.
	 *
	 * @param array<string, mixed> $values Validated values about to be stored, by keyword.
	 *
	 * @return array<int, string> Warnings, translated.
	 */
	public function warnings(array $values)
	{
		$warnings = [];
		$context = (string) ($values[self::OPEN_CONTEXT] ?? '');

		if (stripos($context, 'from-') === 0) {
			$warnings[] = sprintf(
				_('Sign-ups will be put in %s. A from- context is usually one with outbound routes, which is what the lobby exists to keep strangers out of.'),
				$context
			);
		}

		return $warnings;
	}

	/**
	 * Register every setting with FreePBX.
	 *
	 * Safe on every install and upgrade: a setting that already exists is
	 * defined again with its stored value passed back in, so registering can
	 * never reset one -- including the From Domain oryk_connect left behind.
	 * A RETIRED keyword still there is removed.
	 *
	 * @return bool True when every setting was registered.
	 */
	public function register()
	{
		$ok = true;
		$order = 0;

		try {
			$config = \FreePBX::Config();
			$retired = array_values(array_filter(self::RETIRED, [$config, 'conf_setting_exists']));

			if ($retired && method_exists($config, 'remove_conf_settings')) {
				$config->remove_conf_settings($retired);
			}
		} catch (\Throwable $e) {
			$this->logWarning('unable to remove retired settings: ' . $e->getMessage());
		}

		foreach ($this->definitions() as $keyword => $definition) {
			$order += 10;

			try {
				$config = \FreePBX::Config();
				$value = $config->conf_setting_exists($keyword)
					? $config->get($keyword)
					: $definition['default'];

				$config->define_conf_setting($keyword, [
					'value' => $this->normalize($definition, $value),
					'defaultval' => $this->normalize($definition, $definition['default']),
					'name' => $definition['name'],
					'description' => $definition['description'],
					'type' => $this->confType($definition['type']),
					'options' => $this->confOptions($definition),
					'emptyok' => empty($definition['emptyok']) ? 0 : 1,
					'level' => 0,
					'category' => self::CATEGORY,
					// FreePBX removes a module's settings when it is uninstalled
					'module' => 'oryk_provisioner',
					'sortorder' => $order,
				], true);
			} catch (\Throwable $e) {
				$this->logError('unable to register ' . $keyword . ': ' . $e->getMessage());
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * One setting's current value.
	 *
	 * @param string $keyword A keyword from definitions().
	 *
	 * @return mixed The value -- bool for a bool, string otherwise -- or the
	 *               default when FreePBX cannot be asked or the setting has
	 *               not been registered yet (the module's files are newer
	 *               than its last install).
	 */
	public function get($keyword)
	{
		$definitions = $this->definitions();

		if (!isset($definitions[$keyword])) {
			return null;
		}

		try {
			$config = \FreePBX::Config();
			$value = $config->conf_setting_exists($keyword)
				? $config->get($keyword)
				: $definitions[$keyword]['default'];
		} catch (\Throwable $e) {
			$value = $definitions[$keyword]['default'];
		}

		return $this->normalize($definitions[$keyword], $value);
	}

	/**
	 * Store one setting, validated as the tab would.
	 *
	 * @param string $keyword A keyword from definitions().
	 * @param mixed  $value   Value as typed.
	 *
	 * @return string|null Null when stored, or why it was not.
	 */
	public function set($keyword, $value)
	{
		$result = $this->saveSettings(['settings' => [$keyword => $value]]);

		return $result['status'] ? null : $result['message'];
	}

	/**
	 * Every setting, as the Settings tab draws it.
	 *
	 * @param array<string, string> $placeholders What a blank value comes to,
	 *                                            by keyword, where that is
	 *                                            worth showing.
	 *
	 * @return array<int, array<string, mixed>> keyword, name, description,
	 *                                          type, value, options,
	 *                                          placeholder, in order.
	 */
	public function fields(array $placeholders = [])
	{
		$fields = [];

		foreach ($this->definitions() as $keyword => $definition) {
			$fields[] = [
				'keyword' => $keyword,
				'name' => $definition['name'],
				'description' => $definition['description'],
				'type' => $definition['type'],
				'value' => $this->get($keyword),
				'options' => $definition['options'] ?? [],
				'min' => $definition['min'] ?? null,
				'max' => $definition['max'] ?? null,
				'placeholder' => (string) ($placeholders[$keyword] ?? $this->normalize($definition, $definition['default'])),
			];
		}

		return $fields;
	}

	/**
	 * Save what the Settings tab posted.
	 *
	 * Every value is checked before any is written, so a refused save changes
	 * nothing. A keyword not in definitions() is ignored, and one not posted
	 * is left as it is.
	 *
	 * @param array<string, mixed> $request The request; values under `settings`.
	 *
	 * @return array<string, mixed> status, and message when it failed; on
	 *                              success, warnings() about what changed.
	 */
	public function saveSettings(array $request)
	{
		$posted = isset($request['settings']) && is_array($request['settings']) ? $request['settings'] : [];
		$changes = [];

		foreach ($this->definitions() as $keyword => $definition) {
			if (!array_key_exists($keyword, $posted)) {
				continue;
			}

			$value = $this->validate($definition, $posted[$keyword], $error);

			if ($error !== null) {
				return ['status' => false, 'message' => $error];
			}

			if ($value !== $this->get($keyword)) {
				$changes[$keyword] = $value;
			}
		}

		foreach ($changes as $keyword => $value) {
			try {
				\FreePBX::Config()->update($keyword, $value);
			} catch (\Throwable $e) {
				$this->logError('unable to store ' . $keyword . ': ' . $e->getMessage());

				return ['status' => false, 'message' => sprintf(_('Could not save %s.'), _($this->definitions()[$keyword]['name']))];
			}

			// FreePBX validates against the definition it stored at install, not
			// this one, and on a mismatch keeps the old value without throwing.
			if ($this->get($keyword) !== $value) {
				$this->logError('FreePBX did not store ' . $keyword . '; its registered definition is older than this one');

				return ['status' => false, 'message' => sprintf(
					_('%s was not saved: FreePBX does not accept that value yet. Run "fwconsole ma install oryk_provisioner" and save again.'),
					_($this->definitions()[$keyword]['name'])
				)];
			}
		}

		return ['status' => true, 'warnings' => $this->warnings($changes)];
	}

	/**
	 * A posted value, made into what is stored, or refused.
	 *
	 * @param array<string, mixed> $definition The setting.
	 * @param mixed                $value      Value as posted.
	 * @param string|null          $error      Set to why it was refused.
	 *
	 * @return mixed The value to store.
	 */
	private function validate(array $definition, $value, &$error)
	{
		$error = null;
		$name = _($definition['name']);
		$value = is_scalar($value) ? trim((string) $value) : '';

		switch ($definition['type']) {
			case 'bool':
				return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);

			case 'int':
				if ($value === '' && !empty($definition['emptyok'])) {
					return '';
				}

				if (!preg_match('/^-?\d+$/', $value)
					|| (isset($definition['min']) && (int) $value < $definition['min'])
					|| (isset($definition['max']) && (int) $value > $definition['max'])) {
					$error = sprintf(_('%s has to be a whole number in range.'), $name);
				}

				return $value;

			case 'select':
				if (!array_key_exists($value, $definition['options'] ?? [])) {
					$error = sprintf(_('%s is not one of the choices.'), $name);
				}

				return $value;

			default:
				if ($value === '') {
					if (empty($definition['emptyok'])) {
						$error = sprintf(_('%s cannot be blank.'), $name);
					}

					return '';
				}

				if (!empty($definition['pattern']) && !preg_match($definition['pattern'], $value)) {
					$error = sprintf(_('%s is not valid.'), $name);
				}

				return $value;
		}
	}

	/**
	 * A value as FreePBX hands it back, made comparable to a validated one and
	 * in the shape define_conf_setting() takes.
	 *
	 * @param array<string, mixed> $definition The setting.
	 * @param mixed                $value      Value as stored.
	 *
	 * @return mixed bool for a bool, trimmed string otherwise.
	 */
	private function normalize(array $definition, $value)
	{
		if ($definition['type'] === 'bool') {
			return is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
		}

		return trim((string) $value);
	}

	/**
	 * The FreePBX type a definition's type is.
	 *
	 * @param string $type text|int|bool|select.
	 *
	 * @return string A CONF_TYPE_* value.
	 */
	private function confType($type)
	{
		switch ($type) {
			case 'bool':
				return \CONF_TYPE_BOOL;
			case 'int':
				return \CONF_TYPE_INT;
			case 'select':
				return \CONF_TYPE_SELECT;
			default:
				return \CONF_TYPE_TEXT;
		}
	}

	/**
	 * What FreePBX validates a value against, in the form it takes per type:
	 * a regex for text, "min,max" for an int, the values for a select.
	 *
	 * @param array<string, mixed> $definition The setting.
	 *
	 * @return mixed Options for define_conf_setting().
	 */
	private function confOptions(array $definition)
	{
		switch ($definition['type']) {
			case 'int':
				return isset($definition['min'], $definition['max'])
					? $definition['min'] . ',' . $definition['max']
					: '';
			case 'select':
				return array_keys($definition['options'] ?? []);
			case 'bool':
				return '';
			default:
				return (string) ($definition['pattern'] ?? '');
		}
	}
}
