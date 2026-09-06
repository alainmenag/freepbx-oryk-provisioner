<?php

namespace Oryk\Provisioner\Template;

use Oryk\Provisioner\Support\Json;
use Oryk\Provisioner\Support\Str;

/**
 * Value filters usable in templates: {{ device.mac | mac:colon | lower }}.
 *
 * The registry is open: vendor specific behaviour can be added with
 * Filters::register('myfilter', function ($value, array $args) { ... }) instead
 * of changing the rendering engine.
 */
class Filters
{
	/** @var array name => callable */
	private static $custom = array();

	/**
	 * Register (or replace) a filter.
	 *
	 * @param string   $name
	 * @param callable $callback function($value, array $args)
	 */
	public static function register($name, $callback)
	{
		self::$custom[strtolower($name)] = $callback;
	}

	/**
	 * Names of every available filter, for the admin interface cheat sheet.
	 *
	 * @return array
	 */
	public static function names()
	{
		$built = array(
			'upper', 'lower', 'ucfirst', 'trim', 'default', 'json', 'xml', 'url',
			'base64', 'md5', 'sha1', 'mac', 'replace', 'substr', 'pad', 'int',
			'yesno', 'onoff', 'bool', 'date', 'prefix', 'suffix', 'slug', 'length',
			'raw', 'escape',
		);

		return array_values(array_unique(array_merge($built, array_keys(self::$custom))));
	}

	/**
	 * Apply one filter to a value.
	 *
	 * @param string $name
	 * @param mixed  $value
	 * @param array  $args
	 * @return mixed
	 */
	public static function apply($name, $value, array $args = array())
	{
		$name = strtolower($name);

		if (isset(self::$custom[$name])) {
			return call_user_func(self::$custom[$name], $value, $args);
		}

		$argument = isset($args[0]) ? $args[0] : '';

		switch ($name) {
			case 'upper':
				return strtoupper(Escaper::stringify($value));

			case 'lower':
				return strtolower(Escaper::stringify($value));

			case 'ucfirst':
				return ucfirst(Escaper::stringify($value));

			case 'trim':
				return trim(Escaper::stringify($value));

			case 'default':
				$empty = $value === null || $value === '' || $value === false;

				return $empty ? $argument : $value;

			case 'json':
				return Json::encode($value);

			case 'xml':
				return htmlspecialchars(Escaper::stringify($value), ENT_QUOTES | ENT_XML1, 'UTF-8');

			case 'url':
				return rawurlencode(Escaper::stringify($value));

			case 'base64':
				return base64_encode(Escaper::stringify($value));

			case 'md5':
				return md5(Escaper::stringify($value));

			case 'sha1':
				return sha1(Escaper::stringify($value));

			case 'mac':
				return self::mac($value, $argument);

			case 'replace':
				$search = isset($args[0]) ? $args[0] : '';
				$replace = isset($args[1]) ? $args[1] : '';

				return str_replace($search, $replace, Escaper::stringify($value));

			case 'substr':
				$start = isset($args[0]) ? (int) $args[0] : 0;
				$string = Escaper::stringify($value);

				return isset($args[1]) ? substr($string, $start, (int) $args[1]) : substr($string, $start);

			case 'pad':
				$length = isset($args[0]) ? (int) $args[0] : 0;
				$char = isset($args[1]) && $args[1] !== '' ? $args[1] : '0';
				$side = isset($args[2]) && strtolower($args[2]) === 'right' ? STR_PAD_RIGHT : STR_PAD_LEFT;

				return str_pad(Escaper::stringify($value), $length, $char, $side);

			case 'int':
				return (int) $value;

			case 'yesno':
				return self::truthy($value) ? 'yes' : 'no';

			case 'onoff':
				return self::truthy($value) ? 'on' : 'off';

			case 'bool':
				$true = isset($args[0]) ? $args[0] : '1';
				$false = isset($args[1]) ? $args[1] : '0';

				return self::truthy($value) ? $true : $false;

			case 'date':
				$format = $argument === '' ? 'Y-m-d H:i:s' : $argument;
				$timestamp = is_numeric($value) ? (int) $value : strtotime(Escaper::stringify($value));

				if ($timestamp === false || $value === null || $value === '') {
					$timestamp = time();
				}

				return date($format, $timestamp);

			case 'prefix':
				$string = Escaper::stringify($value);

				return $string === '' ? '' : $argument . $string;

			case 'suffix':
				$string = Escaper::stringify($value);

				return $string === '' ? '' : $string . $argument;

			case 'slug':
				return Str::slug(Escaper::stringify($value));

			case 'length':
				return is_array($value) ? count($value) : strlen(Escaper::stringify($value));

			case 'raw':
			case 'escape':
				return $value;

			default:
				// Unknown filters pass the value through untouched rather than
				// breaking a device's provisioning run.
				return $value;
		}
	}

	/**
	 * MAC formatting helper: mac:colon, mac:dash, mac:dot, mac:lower, mac:upper.
	 */
	private static function mac($value, $format)
	{
		$mac = Str::normalizeMac($value);
		$format = strtolower((string) $format);

		switch ($format) {
			case 'colon':
				return Str::formatMac($mac, ':');
			case 'colon-lower':
				return Str::formatMac($mac, ':', true);
			case 'dash':
				return Str::formatMac($mac, '-');
			case 'dot':
				return Str::formatMac($mac, '.');
			case 'lower':
				return strtolower($mac);
			case 'upper':
				return strtoupper($mac);
			default:
				return $mac;
		}
	}

	/**
	 * Shared truthiness rule for filters and {{#if}}.
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public static function truthy($value)
	{
		if (is_array($value)) {
			return !empty($value);
		}

		if (is_string($value)) {
			$normalized = strtolower(trim($value));

			if (in_array($normalized, array('', '0', 'false', 'no', 'off'), true)) {
				return false;
			}

			return true;
		}

		return !empty($value);
	}
}
