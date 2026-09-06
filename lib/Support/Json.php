<?php

namespace Oryk\Provisioner\Support;

/**
 * JSON helpers with predictable behaviour: decoding never throws, encoding is
 * pretty printed with unescaped slashes so generated configuration files stay
 * readable on the device.
 */
class Json
{
	/**
	 * Decode a JSON string into an array. Invalid JSON returns $default.
	 *
	 * @param mixed $value
	 * @param array $default
	 * @return array
	 */
	public static function decode($value, $default = array())
	{
		if (is_array($value)) {
			return $value;
		}

		if (!is_string($value) || trim($value) === '') {
			return $default;
		}

		$decoded = json_decode($value, true);

		if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
			return $default;
		}

		return $decoded;
	}

	/**
	 * Encode a value for storage (compact).
	 */
	public static function encode($value)
	{
		return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Encode a value for display / export (pretty).
	 */
	public static function pretty($value)
	{
		return json_encode(
			$value,
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Is the given string valid JSON?
	 */
	public static function isValid($value)
	{
		if (!is_string($value) || trim($value) === '') {
			return false;
		}

		json_decode($value);

		return json_last_error() === JSON_ERROR_NONE;
	}
}
