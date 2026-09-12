<?php

// src/Mac.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * A MAC address, as written, as found and as assigned.
 *
 * Twelve lowercase hex characters with the separators stripped is how a MAC
 * is stored and compared everywhere in this module; a phone may write it any
 * of half a dozen ways. Static because none of these needs anything -- they
 * are what everything else has to agree on.
 */
class Mac
{
	/**
	 * The prefix an address the module made up carries.
	 *
	 * `02` sets the locally administered bit and clears the multicast one,
	 * which is IEEE's own way of saying this address was assigned by whoever
	 * runs the network rather than burned into a card at the factory. So an
	 * address this module invents can never collide with a real handset's,
	 * and the two are told apart by looking at them.
	 */
	const ASSIGNED_PREFIX = '02';

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
	 * A MAC as the provisioning log stores it.
	 *
	 * Normalised when it is a MAC, so a row can be read back by what asked
	 * for it, and kept as it was sent when it is not: on a row like that, what
	 * the thing at the other end actually sent is the whole of what the row is
	 * worth having.
	 *
	 * Here rather than in ProvisioningLog because both ends of that table now
	 * use it -- the rows are written and read by one class and counted by
	 * another, and they have to agree on the spelling.
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

	/**
	 * The address a client with no MAC of its own provisions by.
	 *
	 * A softphone, a client reached by token, a row somebody wrote before the
	 * handset arrived: not every client has a MAC to type, and refusing to
	 * write one until somebody invents an address is refusing over a field
	 * that column is not there to hold. So the module assigns one, and the
	 * only thing it has to be is unique and recognisable -- which the id
	 * already is. Client 6 is `020000000006`.
	 *
	 * Padded in decimal rather than hex, because the id is read by a person
	 * off this address and the two spellings agree only up to 9. Ten digits
	 * is every id an INT(11) can hold, so the result is always the twelve
	 * characters the column takes.
	 *
	 * **A pure function of the id, which is what makes it worth storing.**
	 * The address goes in the `mac` column like any other, so the endpoint,
	 * the provisioning log, the log directory and `{{device.mac}}` never
	 * learn there are two kinds -- and whether a stored MAC was assigned is
	 * still answerable at any time by asking this again.
	 *
	 * @param mixed $id Client id.
	 *
	 * @return string The assigned address, or '' when there is no id yet.
	 */
	public static function assigned($id)
	{
		$id = (int) $id;

		return $id > 0 ? self::ASSIGNED_PREFIX . str_pad((string) $id, 10, '0', STR_PAD_LEFT) : '';
	}

	/**
	 * Whether this is the address the module gave that client.
	 *
	 * Asked by the pages that say so, and by nothing that serves: a client
	 * with an assigned address is served exactly like one whose MAC is on a
	 * label, and the difference is only ever worth telling a person.
	 *
	 * @param mixed $mac Stored MAC address.
	 * @param mixed $id  Client id.
	 *
	 * @return bool
	 */
	public static function isAssigned($mac, $id)
	{
		$assigned = self::assigned($id);

		return $assigned !== '' && (string) $mac === $assigned;
	}

	/**
	 * A unique address that holds a row's place for the length of one insert.
	 *
	 * assigned() needs the id, and the id does not exist until the INSERT has
	 * run -- so a client being written without a MAC is inserted with this and
	 * updated to its real address a statement later, inside one transaction.
	 * Random rather than a fixed placeholder like `''`, because the column is
	 * UNIQUE: a constant would let only one such insert be in flight at a
	 * time and refuse the second as a duplicate MAC, which is not what went
	 * wrong.
	 *
	 * It carries the assigned prefix, so should a crash ever strand one it is
	 * still a valid, unique, locally administered address and not something
	 * that looks like a handset.
	 *
	 * @return string Twelve hexadecimal characters.
	 */
	public static function provisional()
	{
		return self::ASSIGNED_PREFIX . bin2hex(random_bytes(5));
	}
}
