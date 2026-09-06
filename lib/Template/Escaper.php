<?php

namespace Oryk\Provisioner\Template;

/**
 * Escapes rendered values for the syntax of the file being produced.
 *
 * The mode is derived from the output content type, so a JSON template gets
 * JSON-safe values and an XML template gets XML-safe values without the author
 * having to remember a filter. {{{ triple braces }}} bypass escaping entirely.
 */
class Escaper
{
	const MODE_NONE = 'none';
	const MODE_JSON = 'json';
	const MODE_XML  = 'xml';
	const MODE_URL  = 'url';

	/**
	 * Pick an escaping mode for a content type.
	 *
	 * @param string $contentType
	 * @return string
	 */
	public static function modeFor($contentType)
	{
		$contentType = strtolower(trim((string) $contentType));

		if (strpos($contentType, 'json') !== false) {
			return self::MODE_JSON;
		}

		if (strpos($contentType, 'xml') !== false || strpos($contentType, 'html') !== false) {
			return self::MODE_XML;
		}

		return self::MODE_NONE;
	}

	/**
	 * @param mixed  $value
	 * @param string $mode
	 * @return string
	 */
	public static function escape($value, $mode)
	{
		$value = self::stringify($value);

		switch ($mode) {
			case self::MODE_JSON:
				// Escape as a JSON string, then drop the surrounding quotes so the
				// value can be dropped inside quotes already present in the template.
				$encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

				return $encoded === false ? '' : substr($encoded, 1, -1);

			case self::MODE_XML:
				return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');

			case self::MODE_URL:
				return rawurlencode($value);

			default:
				// Key/value and CFG formats: a newline would forge extra settings.
				return str_replace(array("\r\n", "\r", "\n"), ' ', $value);
		}
	}

	/**
	 * Convert any value into the string a configuration file should carry.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function stringify($value)
	{
		if ($value === null) {
			return '';
		}

		if (is_bool($value)) {
			return $value ? '1' : '0';
		}

		if (is_array($value)) {
			return implode(',', array_map(array(__CLASS__, 'stringify'), $value));
		}

		if (is_object($value)) {
			return method_exists($value, '__toString') ? (string) $value : '';
		}

		return (string) $value;
	}
}
