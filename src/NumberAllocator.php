<?php

// src/NumberAllocator.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Which numbers are free, and what the next one is.
 *
 * An Extension/User device is its own device id, its own extension and its
 * own User Manager account, so a number is only free when all three of
 * those are free. Everything here is in service of that one question, and
 * of never answering it wrongly: a number handed out or accepted while
 * something already holds it does not fail, it overwrites, and what it
 * overwrites is somebody's extension.
 *
 * So the search for a conflict looks in all three places rather than the
 * one the caller happens to be about to write to, and generated ids are
 * taken from above the highest number any of them holds rather than from
 * the first gap, which means a number freed by a deletion is never handed
 * out again.
 */
class NumberAllocator extends Service
{
	/**
	 * Prefix every generated device id starts with.
	 */
	const NUMBER_PREFIX = '999';

	/**
	 * Total length of a generated device id, prefix included.
	 */
	const NUMBER_LENGTH = 10;

	/**
	 * Whether a User Manager account already holds a number.
	 *
	 * @var UsermanManager
	 */
	private $userman;

	/**
	 * @param object         $freepbx FreePBX application instance.
	 * @param UsermanManager $userman User Manager accounts.
	 */
	public function __construct($freepbx, UsermanManager $userman)
	{
		parent::__construct($freepbx);

		$this->userman = $userman;
	}

	/**
	 * Generate the next sequential device identifier.
	 *
	 * Identifiers are NUMBER_LENGTH digits long and always start with
	 * NUMBER_PREFIX, so the range runs from 9990000001 to 9999999999. The
	 * highest identifier already taken by a device or a user is incremented,
	 * which keeps the numbers sequential and never reuses a freed id.
	 *
	 * @return string Device identifier.
	 *
	 * @throws \Exception When the identifier range is exhausted.
	 */
	public function generate()
	{
		$digits = self::NUMBER_LENGTH - strlen(self::NUMBER_PREFIX);
		$floor = (int) (self::NUMBER_PREFIX . str_repeat('0', $digits)); // 9990000000
		$ceiling = (int) (self::NUMBER_PREFIX . str_repeat('9', $digits)); // 9999999999
		$pattern = '^' . self::NUMBER_PREFIX . '[0-9]{' . $digits . '}$';

		// Users are included so an extension left behind by a device is not reused.
		$sql = 'SELECT MAX(CAST(id AS UNSIGNED)) FROM ('
			. ' SELECT id FROM devices WHERE id REGEXP ?'
			. ' UNION ALL'
			. ' SELECT extension AS id FROM users WHERE extension REGEXP ?'
			. ') AS taken';

		$sth = $this->db->prepare($sql);
		$sth->execute([$pattern, $pattern]);
		$highest = (int) $sth->fetchColumn();

		$next = ($highest >= $floor ? $highest : $floor) + 1;

		if ($next > $ceiling) {
			throw new \Exception(sprintf(
				'No device id left in the %d-%d range',
				$floor + 1,
				$ceiling
			));
		}

		return (string) $next;
	}

	/**
	 * Validate an Extension/User number typed into the form.
	 *
	 * An Extension/User device is its own device id, extension and User
	 * Manager account, so the number has to be digits only and free:
	 * anything already holding it would otherwise be overwritten.
	 *
	 * @param int|string      $number    Number typed into the form.
	 * @param int|string|null $currentId Device being edited, if any.
	 *
	 * @return string The validated number.
	 *
	 * @throws \Exception When the number is malformed or already taken.
	 */
	public function assertAvailable($number, $currentId = null)
	{
		$number = trim((string) $number);

		if (!preg_match('/^[0-9]+$/', $number)) {
			throw new \Exception(sprintf(
				_('"%s" is not a valid Extension/User number: digits only.'),
				$number
			));
		}

		if (strlen($number) > self::NUMBER_LENGTH) {
			throw new \Exception(sprintf(
				_('Extension/User %s is too long: %d digits at most.'),
				$number,
				self::NUMBER_LENGTH
			));
		}

		// The number a device already carries is its own, not a conflict
		if ((string) $currentId !== '' && (string) $currentId === $number) {
			return $number;
		}

		$conflict = $this->findConflict($number);

		if ($conflict !== null) {
			throw new \Exception($conflict);
		}

		return $number;
	}

	/**
	 * Describe what already holds a number.
	 *
	 * @param int|string $number Extension/user number.
	 *
	 * @return string|null Why the number is taken, null when it is free.
	 */
	public function findConflict($number)
	{
		$sth = $this->db->prepare('SELECT description FROM devices WHERE id = ? LIMIT 1');
		$sth->execute([$number]);
		$description = $sth->fetchColumn();

		if ($description !== false) {
			return sprintf(
				_('Extension/User %s is already taken by the device "%s".'),
				$number,
				(string) $description !== '' ? $description : $number
			);
		}

		$sth = $this->db->prepare('SELECT name FROM users WHERE extension = ? LIMIT 1');
		$sth->execute([$number]);
		$name = $sth->fetchColumn();

		if ($name !== false) {
			return sprintf(
				_('Extension/User %s is already taken by the extension "%s".'),
				$number,
				(string) $name !== '' ? $name : $number
			);
		}

		$account = $this->userman->findByExtension($number);

		if ($account) {
			return sprintf(
				_('Extension/User %s is already taken by the User Manager account "%s".'),
				$number,
				$account['username']
			);
		}

		return null;
	}
}
