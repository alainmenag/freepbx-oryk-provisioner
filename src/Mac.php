<?php

// src/Mac.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * A MAC address, as written and as found.
 *
 * Twelve lowercase hex characters with the separators stripped is how a MAC is
 * stored and compared everywhere in this module; a phone may write it any of
 * half a dozen ways. Static because neither of these needs anything.
 */
class Mac
{
	/**
	 * The MAC a phone asks with to be provisioned by its credentials; see
	 * Endpoint::openProvision().
	 */
	const OPEN = '000000000000';

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
	 * The MAC a client saved without one is given.
	 *
	 * Locally administered (`02` prefix) and built from the client id, so it
	 * cannot collide with a real phone or another client. Clients::INTERNAL_EXPR
	 * is the same thing in SQL; the two must agree.
	 *
	 * @param int $id Client id.
	 *
	 * @return string The MAC, normalised.
	 */
	public static function internal($id)
	{
		return '02' . str_pad((string) (int) $id, 10, '0', STR_PAD_LEFT);
	}

	/**
	 * A MAC as the provisioning log stores it.
	 *
	 * Normalised when it is a MAC, so a row can be read back by what asked for it,
	 * and kept as it was sent when it is not: on a row like that, what the thing
	 * at the other end actually sent is the whole of what the row is worth having.
	 *
	 * @param mixed $mac MAC address as it was written.
	 *
	 * @return string The normalised MAC, or what was asked with.
	 */
	public static function stored($mac)
	{
		$normalised = self::normalize($mac);

		return $normalised !== '' ? $normalised : trim((string) $mac);
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
