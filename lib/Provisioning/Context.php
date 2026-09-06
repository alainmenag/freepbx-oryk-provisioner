<?php

namespace Oryk\Provisioner\Provisioning;

use Oryk\Provisioner\Support\Arr;
use Oryk\Provisioner\Support\Str;

/**
 * The resolved parameter context for one device.
 *
 * Alongside the values it keeps the source of every value (template default,
 * FreePBX, device override, ...) so the admin preview can show exactly why a
 * parameter ended up the way it did, and which values are secret.
 */
class Context
{
	const SOURCE_SYSTEM   = 'system';
	const SOURCE_SCHEMA   = 'schema';
	const SOURCE_TEMPLATE = 'template';
	const SOURCE_FREEPBX  = 'freepbx';
	const SOURCE_DEVICE   = 'device';
	const SOURCE_OVERRIDE = 'override';
	const SOURCE_DERIVED  = 'derived';

	/** @var array Flat dotted key => value */
	private $values = array();

	/** @var array Flat dotted key => source constant */
	private $sources = array();

	/** @var array Parameter names holding secrets. */
	private $secrets = array();

	/**
	 * Human readable source labels for the admin interface.
	 *
	 * @return array
	 */
	public static function sourceLabels()
	{
		return array(
			self::SOURCE_SYSTEM   => 'Module setting',
			self::SOURCE_SCHEMA   => 'Schema default',
			self::SOURCE_TEMPLATE => 'Template default',
			self::SOURCE_FREEPBX  => 'FreePBX',
			self::SOURCE_DEVICE   => 'Device',
			self::SOURCE_OVERRIDE => 'Device override',
			self::SOURCE_DERIVED  => 'Derived',
		);
	}

	/**
	 * Merge a layer of values. Later layers win, which is what implements the
	 * documented resolution order.
	 *
	 * @param array  $values
	 * @param string $source
	 * @return $this
	 */
	public function merge(array $values, $source)
	{
		foreach (Arr::flatten($values) as $key => $value) {
			$this->set($key, $value, $source);
		}

		return $this;
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param string $source
	 * @return $this
	 */
	public function set($key, $value, $source = self::SOURCE_DERIVED)
	{
		$key = trim((string) $key);

		if ($key === '') {
			return $this;
		}

		$this->values[$key] = $value;
		$this->sources[$key] = $source;

		return $this;
	}

	/**
	 * Set a value only when nothing has claimed the key yet.
	 *
	 * @return $this
	 */
	public function fill($key, $value, $source = self::SOURCE_DERIVED)
	{
		if (!$this->has($key) || $this->get($key) === '') {
			$this->set($key, $value, $source);
		}

		return $this;
	}

	/**
	 * @return mixed
	 */
	public function get($key, $default = null)
	{
		return array_key_exists($key, $this->values) ? $this->values[$key] : $default;
	}

	public function has($key)
	{
		return array_key_exists($key, $this->values);
	}

	/**
	 * Flag parameters as secret so previews and logs can mask them.
	 *
	 * @param array $names
	 * @return $this
	 */
	public function markSecret(array $names)
	{
		foreach ($names as $name) {
			$this->secrets[$name] = true;
		}

		return $this;
	}

	public function isSecret($key)
	{
		return isset($this->secrets[$key]);
	}

	/**
	 * The values handed to the template renderer.
	 *
	 * @return array
	 */
	public function toArray()
	{
		return $this->values;
	}

	/**
	 * @return array key => source
	 */
	public function sources()
	{
		return $this->sources;
	}

	/**
	 * Rows for the "Resolved Parameters" preview panel.
	 *
	 * @param bool $revealSecrets
	 * @return array
	 */
	public function toRows($revealSecrets = false)
	{
		$labels = self::sourceLabels();
		$rows = array();
		$keys = array_keys($this->values);
		sort($keys);

		foreach ($keys as $key) {
			$value = $this->values[$key];
			$secret = $this->isSecret($key);

			if (is_array($value)) {
				$display = sprintf('[%d item(s)]', count($value));
			} elseif (is_bool($value)) {
				$display = $value ? 'true' : 'false';
			} else {
				$display = (string) $value;
			}

			if ($secret && !$revealSecrets) {
				$display = Str::mask($display);
			}

			$source = isset($this->sources[$key]) ? $this->sources[$key] : self::SOURCE_DERIVED;

			$rows[] = array(
				'parameter'   => $key,
				'value'       => $display,
				'source'      => $source,
				'sourceLabel' => isset($labels[$source]) ? $labels[$source] : $source,
				'secret'      => $secret,
			);
		}

		return $rows;
	}
}
