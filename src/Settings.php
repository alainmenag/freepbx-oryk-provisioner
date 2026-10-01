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
	/**
	 * The PBX-wide from domain. The keyword oryk_connect registered, kept so
	 * taking it over keeps its value.
	 */
	const FROM_DOMAIN = 'ORYK_FROM_DOMAIN';

	/**
	 * What every setting is filed under in Advanced Settings.
	 */
	const CATEGORY = 'Oryk Provisioner';

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
			self::FROM_DOMAIN => [
				'name' => 'From Domain',
				'description' => 'The domain a PJSIP endpoint puts in the From header. '
					. 'It is written to pjsip.endpoint_custom_post.conf on the next save of each '
					. 'user, and a user given a From Domain of its own uses that instead. '
					. 'Left blank, the hostname of this PBX is used when that is a domain name, '
					. 'and nothing is written when it is not.',
				'type' => 'text',
				'default' => '',
				'pattern' => EndpointSettings::DOMAIN_PATTERN,
				'emptyok' => true,
			],
		];
	}

	/**
	 * Register every setting with FreePBX.
	 *
	 * Safe on every install and upgrade: a setting that already exists is
	 * defined again with its stored value passed back in, so registering can
	 * never reset one -- including the From Domain oryk_connect left behind.
	 *
	 * @return bool True when every setting was registered.
	 */
	public function register()
	{
		$ok = true;
		$order = 0;

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
	 *               default when FreePBX cannot be asked.
	 */
	public function get($keyword)
	{
		$definitions = $this->definitions();

		if (!isset($definitions[$keyword])) {
			return null;
		}

		try {
			$value = \FreePBX::Config()->get($keyword);
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
