<?php

// src/Bans.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * Listing, adding and lifting fail2ban bans.
 *
 * A ban is a row in none of this module's tables: it is one (jail, address)
 * pair in fail2ban, asked for every time it is shown. Its key is `jail/ip`
 * everywhere a single string has to name one -- a jail name cannot hold a
 * slash and an address does not. See ARCHITECTURE.md, "Bans".
 */
class Bans extends Service
{
	/** @var Fail2ban */
	private $fail2ban;

	/** @var array<int, string>|null jailChoices(), once per request. */
	private $jails;

	/**
	 * @param object   $freepbx  FreePBX application instance.
	 * @param Fail2ban $fail2ban The helper, through sudo.
	 */
	public function __construct($freepbx, Fail2ban $fail2ban)
	{
		parent::__construct($freepbx);

		$this->fail2ban = $fail2ban;
	}

	/**
	 * One page of the Bans tab, the way bootstrap-table asks for it.
	 *
	 * There is no SQL to page in, so every ban is read and the page is cut here.
	 *
	 * @return array<string, mixed> total, rows; and message when fail2ban
	 *                              could not be asked.
	 */
	public function listBans()
	{
		$answer = $this->fail2ban->bans();

		if (empty($answer['ok'])) {
			return ['total' => 0, 'rows' => [], 'message' => (string) ($answer['error'] ?? '')];
		}

		return self::page(
			$this->withClients(self::rows($answer)),
			(string) ($_REQUEST['sort'] ?? ''),
			(string) ($_REQUEST['order'] ?? ''),
			(string) ($_REQUEST['search'] ?? ''),
			(int) ($_REQUEST['offset'] ?? 0),
			(int) ($_REQUEST['limit'] ?? 10)
		);
	}

	/**
	 * The helper's `list` answer as table rows: keyed, with ages worked out.
	 *
	 * Ages are subtracted on the helper's clock (`now`), which is the clock the
	 * times were written in -- see ARCHITECTURE.md, "Bans".
	 *
	 * @param array<string, mixed> $answer What `oryk-fail2ban list` answered.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public static function rows(array $answer)
	{
		$now = (int) ($answer['now'] ?? time());
		$rows = [];

		foreach ((array) ($answer['bans'] ?? []) as $ban) {
			$jail = (string) ($ban['jail'] ?? '');
			$ip = self::canonical($ban['ip'] ?? '');

			if ($jail === '' || $ip === null) {
				continue;
			}

			$expiresAt = isset($ban['expires_at']) ? (int) $ban['expires_at'] : null;

			$rows[] = [
				'id' => self::key($jail, $ip),
				'jail' => $jail,
				'ip' => $ip,
				'banned' => (string) ($ban['banned'] ?? ''),
				'banned_at' => (int) ($ban['banned_at'] ?? 0),
				'banned_age' => $now - (int) ($ban['banned_at'] ?? $now),
				'permanent' => !empty($ban['permanent']),
				'expires' => $expiresAt === null ? null : (string) ($ban['expires'] ?? ''),
				'expires_at' => $expiresAt,
				'expires_in' => $expiresAt === null ? null : $expiresAt - $now,
				'client_id' => null,
				'client' => null,
			];
		}

		return $rows;
	}

	/**
	 * Search, sort and cut rows into one page.
	 *
	 * The sort key is looked up in a whitelist like every sort column in this
	 * module, though nothing here reaches SQL.
	 *
	 * @param array<int, array<string, mixed>> $rows   Every row.
	 * @param string                           $sort   Column asked to sort by.
	 * @param string                           $order  asc|desc.
	 * @param string                           $search Text to find in ip, jail or client.
	 * @param int                              $offset First row of the page.
	 * @param int                              $limit  Rows on the page.
	 *
	 * @return array<string, mixed> total (after search), rows.
	 */
	public static function page(array $rows, $sort, $order, $search, $offset, $limit)
	{
		$search = strtolower(trim((string) $search));

		if ($search !== '') {
			$rows = array_values(array_filter($rows, function ($row) use ($search) {
				foreach (['ip', 'jail', 'client'] as $field) {
					if (strpos(strtolower((string) $row[$field]), $search) !== false) {
						return true;
					}
				}

				return false;
			}));
		}

		$keys = [
			// An address sorts by its bytes: 9.x before 10.x, every IPv4 before IPv6.
			'ip' => function ($row) {
				return self::sortKey($row['ip']);
			},
			'jail' => function ($row) {
				return strtolower($row['jail']) . ' ' . self::sortKey($row['ip']);
			},
			'client' => function ($row) {
				return strtolower((string) $row['client']);
			},
			'banned_at' => function ($row) {
				return sprintf('%020d', $row['banned_at']);
			},
			// A permanent ban expires last.
			'expires_at' => function ($row) {
				return $row['expires_at'] === null ? 'z' : sprintf('%020d', $row['expires_at']);
			},
		];

		$key = $keys[(string) $sort] ?? $keys['ip'];
		$direction = strtolower((string) $order) === 'desc' ? -1 : 1;

		usort($rows, function ($a, $b) use ($key, $direction) {
			return $direction * strcmp($key($a), $key($b));
		});

		return [
			'total' => count($rows),
			'rows' => array_slice($rows, max(0, (int) $offset), max(1, (int) $limit)),
		];
	}

	/**
	 * Whether bans can be listed and written at all.
	 *
	 * @return bool True when the helper is set up and fail2ban answers.
	 */
	public function ready()
	{
		return $this->fail2ban->ready();
	}

	/**
	 * Every ban, sorted by address, for the navigator. Empty when not ready().
	 *
	 * @return array<int, array<string, mixed>> Rows: id, jail, ip.
	 */
	public function banChoices()
	{
		if (!$this->ready()) {
			return [];
		}

		$answer = $this->fail2ban->bans();

		return empty($answer['ok']) ? [] : self::page(self::rows($answer), 'ip', 'asc', '', 0, PHP_INT_MAX)['rows'];
	}

	/**
	 * One ban, or null when it is not banned (any more) or the names are no good.
	 *
	 * @param string $jail Jail name.
	 * @param string $ip   IP address, however it is spelled.
	 *
	 * @return array<string, mixed>|null The row, as the list draws it.
	 */
	public function banRow($jail, $ip)
	{
		$jail = (string) $jail;
		$ip = self::canonical($ip);

		if ($ip === null || !in_array($jail, $this->jailChoices(), true)) {
			return null;
		}

		$answer = $this->fail2ban->bans($jail);

		if (empty($answer['ok'])) {
			return null;
		}

		foreach ($this->withClients(self::rows($answer)) as $row) {
			if ($row['ip'] === $ip) {
				return $row;
			}
		}

		return null;
	}

	/**
	 * The jails a ban can be added to, once per request.
	 *
	 * @return array<int, string> Jail names.
	 */
	public function jailChoices()
	{
		if ($this->jails === null) {
			$this->jails = $this->fail2ban->jails();
		}

		return $this->jails;
	}

	/**
	 * Every client that has a public address written on it, by that address.
	 *
	 * What the ban editor warns with, and what the Client column links to.
	 *
	 * @return array<string, array<string, mixed>> Keyed by canonical address:
	 *                                             id, label, count.
	 */
	public function clientAddresses()
	{
		try {
			$stmt = $this->db->prepare(
				"SELECT pc.id, pc.mac, pc.public_ip, d.description
					FROM `{$this->clientsTable}` pc
					LEFT JOIN devices d ON d.id = pc.device_id
					WHERE pc.public_ip IS NOT NULL AND pc.public_ip <> ''
					ORDER BY pc.mac"
			);
			$stmt->execute();
			$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			return [];
		}

		$byAddress = [];

		foreach ($clients as $client) {
			$ip = self::canonical($client['public_ip']);

			if ($ip === null) {
				continue;
			}

			if (isset($byAddress[$ip])) {
				$byAddress[$ip]['count']++;

				continue;
			}

			$byAddress[$ip] = [
				'id' => (int) $client['id'],
				'label' => (string) ($client['description'] ?: $client['mac'] ?: ('#' . $client['id'])),
				'count' => 1,
			];
		}

		return $byAddress;
	}

	/**
	 * Ban one address in one jail.
	 *
	 * Refused, with nothing written: an address that is not one address, a jail
	 * fail2ban does not have, and the addresses banning which would cut off the
	 * PBX from itself or from the person pressing Save.
	 *
	 * @param array<string, mixed> $request jail, ip.
	 *
	 * @return array<string, mixed> status, id (`jail/ip`) or message.
	 */
	public function saveBan($request)
	{
		if (!$this->fail2ban->ready()) {
			return ['status' => false, 'message' => $this->fail2ban->status()['message']];
		}

		$jail = trim((string) ($request['jail'] ?? ''));
		$ip = self::canonical(trim((string) ($request['ip'] ?? '')));

		if (!in_array($jail, $this->jailChoices(), true)) {
			return ['status' => false, 'message' => _('Choose a jail.')];
		}

		if ($ip === null) {
			return ['status' => false, 'message' => _('Type one IP address. Ranges are not accepted.')];
		}

		$refused = self::refusal($ip, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $this->ownAddresses());

		if ($refused !== null) {
			return ['status' => false, 'message' => $refused];
		}

		$answer = $this->fail2ban->ban($jail, $ip);

		if (empty($answer['ok'])) {
			return ['status' => false, 'message' => (string) ($answer['error'] ?? _('fail2ban refused the ban.'))];
		}

		$this->logInfo("banned $ip in $jail");

		return ['status' => true, 'id' => self::key($jail, $ip)];
	}

	/**
	 * Why an address may not be banned from here, or null when it may.
	 *
	 * @param string             $ip     Canonical address to ban.
	 * @param string             $remote Address the request came from.
	 * @param array<int, string> $own    The PBX's own addresses.
	 *
	 * @return string|null The refusal, said to the operator.
	 */
	public static function refusal($ip, $remote, array $own)
	{
		if ($ip === self::canonical($remote)) {
			return _('That is the address you are connected from. Banning it would lock you out of this page.');
		}

		if (strpos($ip, '127.') === 0 || in_array($ip, ['::1', '0.0.0.0', '::'], true)) {
			return _('That is a loopback or unspecified address, which is the PBX talking to itself.');
		}

		foreach ($own as $address) {
			if ($ip === self::canonical($address)) {
				return _('That is one of this PBX\'s own addresses.');
			}
		}

		return null;
	}

	/**
	 * Lift one ban. One that has already gone is a success.
	 *
	 * @param string $id `jail/ip`.
	 *
	 * @return array<string, mixed> status, or message.
	 */
	public function deleteBan($id)
	{
		$parts = self::splitKey($id);

		if ($parts === null || !in_array($parts[0], $this->jailChoices(), true)) {
			return ['status' => false, 'message' => _('No such ban.')];
		}

		$answer = $this->fail2ban->unban($parts[0], $parts[1]);

		if (empty($answer['ok'])) {
			return ['status' => false, 'message' => (string) ($answer['error'] ?? _('fail2ban refused to lift the ban.'))];
		}

		$this->logInfo("unbanned {$parts[1]} from {$parts[0]}");

		return ['status' => true];
	}

	/**
	 * The one string that names a ban.
	 *
	 * @param string $jail Jail name.
	 * @param string $ip   Canonical address.
	 *
	 * @return string `jail/ip`.
	 */
	public static function key($jail, $ip)
	{
		return $jail . '/' . $ip;
	}

	/**
	 * A `jail/ip` key taken apart, or null when it is not one.
	 *
	 * @param mixed $id Key, as posted.
	 *
	 * @return array{0: string, 1: string}|null Jail and canonical address.
	 */
	public static function splitKey($id)
	{
		$parts = explode('/', (string) $id, 2);

		if (count($parts) !== 2 || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $parts[0])) {
			return null;
		}

		$ip = self::canonical($parts[1]);

		return $ip === null ? null : [$parts[0], $ip];
	}

	/**
	 * One address in the spelling fail2ban and this module both compare by.
	 *
	 * @param mixed $ip An address, however it is written.
	 *
	 * @return string|null Canonical address, or null when it is not one.
	 */
	public static function canonical($ip)
	{
		$ip = trim((string) $ip);

		if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
			return null;
		}

		$packed = @inet_pton($ip);

		return $packed === false ? null : inet_ntop($packed);
	}

	/**
	 * An address's sort key on its own, for sorts that tie-break on it.
	 *
	 * @param string $ip Canonical address.
	 *
	 * @return string Comparable with strcmp().
	 */
	private static function sortKey($ip)
	{
		$packed = (string) @inet_pton($ip);

		return bin2hex(chr(strlen($packed)) . $packed);
	}

	/**
	 * Fill in the Client column: the client whose public address this is.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from rows().
	 *
	 * @return array<int, array<string, mixed>> The same rows.
	 */
	private function withClients(array $rows)
	{
		$clients = $this->clientAddresses();

		foreach ($rows as &$row) {
			if (isset($clients[$row['ip']])) {
				$client = $clients[$row['ip']];
				$row['client_id'] = $client['id'];
				$row['client'] = $client['count'] > 1
					? sprintf(_('%s and %d more'), $client['label'], $client['count'] - 1)
					: $client['label'];
			}
		}
		unset($row);

		return $rows;
	}

	/**
	 * The addresses this PBX answers on, as far as PHP can see.
	 *
	 * @return array<int, string> Addresses.
	 */
	private function ownAddresses()
	{
		$own = [(string) ($_SERVER['SERVER_ADDR'] ?? '')];
		$byName = @gethostbynamel((string) gethostname());

		return array_values(array_filter(array_merge($own, $byName ?: [])));
	}
}
