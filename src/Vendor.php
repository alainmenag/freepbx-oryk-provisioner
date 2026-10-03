<?php

// src/Vendor.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The vendor a User-Agent names.
 *
 * The name is what a profile is looked up by when a client has none of its
 * own; see Endpoint::resolveRequest().
 */
class Vendor
{
	/**
	 * Vendor => what its User-Agent carries, asked in order: the first match
	 * wins. Phones come before WebKit, since a phone with a browser in it
	 * says both.
	 */
	const PATTERNS = [
		'Polycom' => '/polycom|\bpoly\b/i',
		'Yealink' => '/yealink/i',
		'Grandstream' => '/grandstream/i',
		'Cisco' => '/cisco/i',
		'Snom' => '/snom/i',
		'AudioCodes' => '/audiocodes|\bAUDC-/i',
		'Fanvil' => '/fanvil/i',
		'Htek' => '/htek/i',
		'Mitel' => '/mitel|aastra/i',
		'Avaya' => '/avaya/i',
		'Obihai' => '/obihai/i',
		'Panasonic' => '/panasonic/i',
		'Gigaset' => '/gigaset/i',
		'Akuvox' => '/akuvox/i',
		'WebKit' => '/webkit/i',
		'PostmanRuntime' => '/postmanruntime/i'
	];

	/**
	 * The vendor a User-Agent names, if it names one.
	 *
	 * @param mixed $agent User-Agent header, as sent.
	 *
	 * @return string|null A key of PATTERNS, or null when none matches.
	 */
	public static function fromUserAgent($agent)
	{
		$agent = (string) $agent;

		foreach (self::PATTERNS as $vendor => $pattern) {
			if (preg_match($pattern, $agent)) {
				return $vendor;
			}
		}

		return null;
	}
}
