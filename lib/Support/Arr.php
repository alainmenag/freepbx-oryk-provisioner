<?php

namespace Oryk\Provisioner\Support;

/**
 * Array helpers. The provisioning context is a flat map of dotted keys
 * (sip.username) but templates may also walk nested structures
 * ({{#each directory}}), so both access styles are supported here.
 */
class Arr
{
	/**
	 * Read a value from an array using either a flat dotted key or a nested path.
	 *
	 * @param array  $array
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get(array $array, $key, $default = null)
	{
		$key = (string) $key;

		if ($key === '') {
			return $default;
		}

		// Flat dotted key wins - that is how the resolved context is stored.
		if (array_key_exists($key, $array)) {
			return $array[$key];
		}

		if (strpos($key, '.') === false) {
			return $default;
		}

		$value = $array;

		foreach (explode('.', $key) as $segment) {
			if (is_array($value) && array_key_exists($segment, $value)) {
				$value = $value[$segment];
				continue;
			}

			if (is_object($value) && isset($value->$segment)) {
				$value = $value->$segment;
				continue;
			}

			return $default;
		}

		return $value;
	}

	/**
	 * Does the array contain the given flat or nested key?
	 */
	public static function has(array $array, $key)
	{
		$sentinel = "\0oryk\0";

		return self::get($array, $key, $sentinel) !== $sentinel;
	}

	/**
	 * Flatten a nested array into dotted keys. Lists are left intact so they can
	 * still be iterated by the template engine.
	 *
	 * @return array
	 */
	public static function flatten(array $array, $prefix = '')
	{
		$flat = array();

		foreach ($array as $key => $value) {
			$full = $prefix === '' ? (string) $key : $prefix . '.' . $key;

			if (is_array($value) && !self::isList($value)) {
				$flat = array_merge($flat, self::flatten($value, $full));
				continue;
			}

			$flat[$full] = $value;
		}

		return $flat;
	}

	/**
	 * Is this array a zero indexed list?
	 */
	public static function isList(array $array)
	{
		if ($array === array()) {
			return true;
		}

		return array_keys($array) === range(0, count($array) - 1);
	}

	/**
	 * Only keep the given keys, preserving order.
	 */
	public static function only(array $array, array $keys)
	{
		$result = array();

		foreach ($keys as $key) {
			if (array_key_exists($key, $array)) {
				$result[$key] = $array[$key];
			}
		}

		return $result;
	}

	/**
	 * Normalise a "key => value" style structure coming from the admin UI or
	 * the API, discarding empty keys.
	 */
	public static function keyValue($input)
	{
		$result = array();

		if (is_string($input)) {
			$decoded = json_decode($input, true);
			$input = is_array($decoded) ? $decoded : array();
		}

		if (!is_array($input)) {
			return $result;
		}

		// Repeater style: [ ['key' => 'sip.port', 'value' => 5060], ... ]
		if (self::isList($input)) {
			foreach ($input as $row) {
				if (!is_array($row) || !isset($row['key'])) {
					continue;
				}

				$key = trim((string) $row['key']);

				if ($key === '') {
					continue;
				}

				$result[$key] = isset($row['value']) ? $row['value'] : '';
			}

			return $result;
		}

		foreach ($input as $key => $value) {
			$key = trim((string) $key);

			if ($key === '') {
				continue;
			}

			$result[$key] = $value;
		}

		return $result;
	}
}
