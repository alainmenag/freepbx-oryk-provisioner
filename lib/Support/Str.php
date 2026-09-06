<?php

namespace Oryk\Provisioner\Support;

/**
 * String helpers shared by the provisioning engine and the admin interface.
 */
class Str
{
	/**
	 * Convert an arbitrary string into a url/filename safe slug.
	 */
	public static function slug($value, $fallback = '')
	{
		$value = strtolower(trim((string) $value));
		$value = preg_replace('/[^a-z0-9]+/', '-', $value);
		$value = trim((string) $value, '-');

		if ($value === '') {
			return $fallback;
		}

		return substr($value, 0, 100);
	}

	/**
	 * Normalise a MAC address to bare uppercase hex (001565AABBCC).
	 */
	public static function normalizeMac($mac)
	{
		$mac = preg_replace('/[^0-9a-fA-F]/', '', (string) $mac);

		return strtoupper((string) $mac);
	}

	/**
	 * Format a bare MAC using the given separator, e.g. 00:15:65:AA:BB:CC.
	 */
	public static function formatMac($mac, $separator = ':', $lower = false)
	{
		$mac = self::normalizeMac($mac);

		if ($mac === '') {
			return '';
		}

		$parts = str_split($mac, 2);
		$mac = implode($separator, $parts);

		return $lower ? strtolower($mac) : $mac;
	}

	/**
	 * Is this a syntactically valid MAC address?
	 */
	public static function isMac($mac)
	{
		return (bool) preg_match('/^[0-9A-F]{12}$/', self::normalizeMac($mac));
	}

	/**
	 * Case insensitive comparison used when matching requested filenames.
	 */
	public static function equalsIgnoreCase($a, $b)
	{
		return strcasecmp((string) $a, (string) $b) === 0;
	}

	/**
	 * PHP 7.4 safe "starts with".
	 */
	public static function startsWith($haystack, $needle)
	{
		return $needle !== '' && strncmp((string) $haystack, (string) $needle, strlen($needle)) === 0;
	}

	/**
	 * PHP 7.4 safe "contains".
	 */
	public static function contains($haystack, $needle)
	{
		return $needle !== '' && strpos((string) $haystack, (string) $needle) !== false;
	}

	/**
	 * Strip anything that could be used to traverse out of a provisioning
	 * directory. Requested filenames come straight off the wire.
	 */
	public static function sanitizeFilename($filename)
	{
		$filename = (string) $filename;
		$filename = str_replace(array("\0", "\\"), '', $filename);
		$filename = basename($filename);

		if (!preg_match('/^[A-Za-z0-9._\-]{1,190}$/', $filename)) {
			return '';
		}

		return $filename;
	}

	/**
	 * Mask a secret for display in previews and logs.
	 */
	public static function mask($value)
	{
		$value = (string) $value;

		if ($value === '') {
			return '';
		}

		return str_repeat('*', min(12, max(6, strlen($value))));
	}

	/**
	 * Trim a string to a maximum length, used for log columns.
	 */
	public static function limit($value, $length)
	{
		$value = (string) $value;

		return strlen($value) > $length ? substr($value, 0, $length) : $value;
	}
}
