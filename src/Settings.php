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
					. 'banned and deny jails, and Allow puts the address on every jail\'s ignore list. Needs the setup '
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
		];
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
	 * @return array<string, mixed> status, and message when it failed.
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

		return ['status' => true];
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
