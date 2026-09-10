<?php

// src/Mac.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * A MAC address, as written and as found.
 *
 * Twelve lowercase hex characters with the separators stripped is how a MAC
 * is stored and compared everywhere in this module; a phone may write it any
 * of half a dozen ways. Static because neither of these needs anything --
 * they are the two things everything else has to agree on.
 */
class Mac
{
	/**
	 * A MAC address as it is stored: lowercase hexadecimal, no separators.
	 *
	 * @param mixed $mac MAC address as it was typed.
	 *
	 * @return string The normalised MAC, or an empty string when it is not one.
	 */
	public static function normalize($mac)
	{
		$mac = strtolower(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));

		return preg_match('/^[0-9a-f]{12}$/', $mac) ? $mac : '';
	}

	/**
	 * The MAC a requested filename carries, if it carries one.
	 *
	 * The endpoint's own reading of a path, kept to the same pattern: twelve
	 * hexadecimal characters, optionally paired off with colons or dashes,
	 * not run up against more hex on either side.
	 *
	 * @param string $filename Filename as it would be asked for.
	 *
	 * @return string The normalised MAC, or '' when there is none.
	 */
	public static function find($filename)
	{
		$pattern = '/(?<![0-9A-Fa-f])(?:[0-9A-Fa-f]{2}[:-]?){5}[0-9A-Fa-f]{2}(?![0-9A-Fa-f])/';

		return preg_match($pattern, (string) $filename, $match) ? self::normalize($match[0]) : '';
	}
}
