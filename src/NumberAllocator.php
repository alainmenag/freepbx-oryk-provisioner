<?php

// src/NumberAllocator.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Which numbers are free, and what the next one is.
 *
 * An Extension/User number is a device id, an extension and a User Manager
 * account at once, so it is free only when all three are. A number accepted
 * while something holds it does not fail, it overwrites that extension.
 * Generated ids come from above the highest number held anywhere, never
 * from a gap. Deleting the highest user frees its number for the next one, as
 * in any PBX -- but not while Apply Config still has it in the PJSIP files,
 * where its old endpoint would win over the new user's Realtime bridge rows.
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

	/** @var string Asterisk's config directory, where the applied endpoints are read. */
	private $etc;

	/**
	 * @param object         $freepbx FreePBX application instance.
	 * @param UsermanManager $userman User Manager accounts.
	 * @param string|null    $etc     Asterisk's config directory, if not ASTETCDIR.
	 */
	public function __construct($freepbx, UsermanManager $userman, $etc = null)
	{
		parent::__construct($freepbx);

		$this->userman = $userman;

		if ($etc === null) {
			try {
				$etc = (string) \FreePBX::Config()->get('ASTETCDIR');
			} catch (\Throwable $e) {
				$etc = '';
			}
		}

		$this->etc = rtrim($etc !== '' ? $etc : '/etc/asterisk', '/');
	}

	/**
	 * Generate the next sequential device identifier.
	 *
	 * NUMBER_LENGTH digits starting with NUMBER_PREFIX; the highest id held
	 * by a device or a user is incremented, stepping over any number Apply
	 * Config still has an endpoint for -- a user deleted but not yet applied.
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
		$applied = $this->appliedEndpoints();

		while (isset($applied[(string) $next])) {
			$next++;
		}

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
	 * The ids Apply Config last wrote an endpoint section for.
	 *
	 * Read off pjsip.endpoint.conf, as SignupSweep reads it; a file that
	 * cannot be read steps over nothing.
	 *
	 * @return array<string, true> Section names, as keys.
	 */
	private function appliedEndpoints()
	{
		$text = (string) @file_get_contents($this->etc . '/pjsip.endpoint.conf');

		preg_match_all('/^\[([0-9]+)\]/m', $text, $matches);

		return array_fill_keys($matches[1], true);
	}

	/**
	 * Validate an Extension/User number typed into the form.
	 *
	 * Must be digits only and free as device, extension and User Manager
	 * account: anything already holding it would be overwritten.
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
