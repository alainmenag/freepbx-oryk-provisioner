<?php
// src/Overview.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Everything tied to one user or one client, and removing it.
 *
 * The Overview section's model: what is listed there, and what Delete all
 * takes. See ARCHITECTURE.md, "Overview".
 *
 * Every method takes the row as Navigator::scopeAt() hands it out and works
 * out what is related itself -- nothing here acts on ids a page posted.
 */
class Overview extends Service
{
	/** @var Navigator */
	private $navigator;

	/** @var Users */
	private $users;

	/** @var Clients */
	private $clients;

	/** @var Bans */
	private $bans;

	/** @var ProvisioningLog */
	private $requestLog;

	/** @var LogRepo */
	private $logs;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Navigator $navigator, Users $users, Clients $clients, Bans $bans, ProvisioningLog $requestLog, LogRepo $logs)
	{
		parent::__construct($freepbx);

		$this->navigator = $navigator;
		$this->users = $users;
		$this->clients = $clients;
		$this->bans = $bans;
		$this->requestLog = $requestLog;
		$this->logs = $logs;
	}

	/**
	 * The part of a `&scope=` Overview is about: a user or a client.
	 *
	 * Only an id in the one spelling it is stored by: `05` would find client 5
	 * here and scope nothing in Navigator, which compares ids as strings.
	 *
	 * @param array<string, mixed> $at Navigator::scopeAt().
	 *
	 * @return array<string, string> One of user or client and its id, or empty.
	 */
	public static function target(array $at)
	{
		foreach (['client', 'user'] as $kind) {
			$id = isset($at[$kind]) ? Bans::value($kind, $at[$kind]) : null;

			if ($id !== null) {
				return [$kind => $id];
			}
		}

		return [];
	}

	/**
	 * Whether a `&scope=` names a user or client that exists.
	 *
	 * @param array<string, string> $at Navigator::scopeAt().
	 *
	 * @return bool True when Overview has a row to show.
	 */
	public function exists(array $at)
	{
		$at = self::target($at);

		if (isset($at['user'])) {
			return (bool) $this->users->userRow($at['user']);
		}

		return isset($at['client']) && (bool) $this->clients->clientRow($at['client']);
	}

	/**
	 * Whether a ban names what is being overviewed, rather than only applying to it.
	 *
	 * Naming is by client, extension or MAC. A ban reaching it through its
	 * profile or an address does not: that ban is about something else, and
	 * Delete all leaves it. `user` is null where the extension is not going.
	 *
	 * @param array<string, mixed> $ban     Subject columns, "any" as null or as Bans::ANY.
	 * @param array<string, mixed> $subject user (extension or null), clients (ids), macs.
	 *
	 * @return bool True when it names one of them.
	 */
	public static function names(array $ban, array $subject)
	{
		$client = Bans::value('client', $ban['client_id'] ?? '');
		$user = Bans::value('user', $ban['extension'] ?? '');
		$mac = Bans::value('mac', $ban['mac'] ?? '');

		if ($client !== null && in_array($client, array_map('strval', (array) ($subject['clients'] ?? [])), true)) {
			return true;
		}

		if ($user !== null && isset($subject['user']) && $user === (string) $subject['user']) {
			return true;
		}

		return $mac !== null && in_array($mac, array_map('strval', (array) ($subject['macs'] ?? [])), true);
	}

	/**
	 * What is tied to a user or client, as the Overview pane draws it.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed>|null kind, id, key (the `&scope=` value),
	 *                                   user (Users::userRow() or null), client
	 *                                   (Clients::clientRow() or null), clients
	 *                                   (ids), macs, logs (entries), stored
	 *                                   (files, bytes, and clients: id => files),
	 *                                   named and applying (ban ids), freepbx
	 *                                   (Users::related(), user only); null
	 *                                   when it names no row.
	 */
	public function inventory(array $at)
	{
		$at = self::target($at);
		$kind = (string) key($at);
		$id = $at ? (string) current($at) : '';
		$user = null;
		$client = null;

		if ($kind === 'user') {
			$user = $this->users->userRow($id);

			if (!$user) {
				return null;
			}
		} elseif ($kind === 'client') {
			$client = $this->clients->clientRow($id);

			if (!$client) {
				return null;
			}

			// Shown, never deleted: the user is not this client's to take.
			$user = $this->users->userRow($client['device_id']);
		} else {
			return null;
		}

		$scope = $this->navigator->scope($at);
		$clients = $kind === 'client' ? [(int) $id] : array_map('intval', (array) $scope['clients']);
		$macs = (array) $scope['logs'];
		$stored = ['files' => 0, 'bytes' => 0, 'clients' => []];

		foreach ($clients as $one) {
			$stats = $this->logs->clientLogStats($one);

			if ($stats['files']) {
				$stored['files'] += $stats['files'];
				$stored['bytes'] += $stats['bytes'];
				$stored['clients'][$one] = $stats['files'];
			}
		}

		$freepbx = $kind === 'user' ? $this->users->related($id) : null;

		// An extension another device keeps in service keeps its bans too.
		$subject = ['user' => ($kind === 'user' && empty($freepbx['shared'])) ? $id : null, 'clients' => $clients, 'macs' => $macs];

		return [
			'kind' => $kind,
			'id' => $id,
			'key' => $kind . ':' . $id,
			'user' => $user,
			'client' => $client,
			'clients' => $clients,
			'macs' => $macs,
			'logs' => $this->requestLog->countFor($macs),
			'stored' => $stored,
			'named' => $this->named($subject),
			'applying' => array_map('intval', (array) $scope['bans']),
			'freepbx' => $freepbx,
		];
	}

	/**
	 * The bans naming a subject, in force or not.
	 *
	 * @param array<string, mixed> $subject As names() takes it.
	 *
	 * @return array<int, int> Ban ids.
	 */
	private function named(array $subject)
	{
		$requests = [];

		if ($subject['user'] !== null) {
			$requests[] = ['user' => $subject['user']];
		}

		foreach ($subject['clients'] as $client) {
			$requests[] = ['client' => $client];
		}

		foreach ($subject['macs'] as $mac) {
			$requests[] = ['mac' => $mac];
		}

		$ids = [];

		foreach ($requests ? $this->bans->banChoices($requests) : [] as $row) {
			if (self::names($row, $subject)) {
				$ids[] = (int) $row['id'];
			}
		}

		return $ids;
	}

	/**
	 * The Users table on Overview: the user, or a client's user, as the Users
	 * list draws it, with what it has in FreePBX on the row.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> total, rows; each row with account and mailbox.
	 */
	public function listUsers(array $at)
	{
		$found = $this->inventory($at);

		if (!$found || !$found['user']) {
			return ['total' => 0, 'rows' => []];
		}

		$result = $this->users->listUsers([(string) $found['user']['extension']]);

		foreach ($result['rows'] as &$row) {
			$related = $this->users->related($row['extension']);
			$row['account'] = $related['account'];
			$row['mailbox'] = $related['mailbox'] ? 1 : 0;
		}
		unset($row);

		return $result;
	}

	/**
	 * The Clients table on Overview: the user's clients, or the client, as
	 * the Clients list draws them, with what each has stored on the row.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> total, rows; each row with stored_files and stored_bytes.
	 */
	public function listClients(array $at)
	{
		$found = $this->inventory($at);

		if (!$found) {
			return ['total' => 0, 'rows' => []];
		}

		$result = $this->clients->listClients($found['clients']);

		foreach ($result['rows'] as &$row) {
			$stats = $this->logs->clientLogStats($row['id']);
			$row['stored_files'] = $stats['files'];
			$row['stored_bytes'] = $stats['bytes'];
		}
		unset($row);

		return $result;
	}

	/**
	 * The Bans table on Overview: the bans naming the row and the ones that
	 * apply to it, each row saying which with `names`.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> total, rows.
	 */
	public function listBans(array $at)
	{
		$found = $this->inventory($at);

		if (!$found) {
			return ['total' => 0, 'rows' => []];
		}

		$result = $this->bans->listBans(array_values(array_unique(array_merge($found['named'], $found['applying']))));

		foreach ($result['rows'] as &$row) {
			$row['names'] = in_array((int) $row['id'], $found['named'], true) ? 1 : 0;
		}
		unset($row);

		return $result;
	}

	/**
	 * Delete the provisioning-log entries of the row's MACs.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> Status, and `deleted`.
	 */
	public function clearLogs(array $at)
	{
		$found = $this->inventory($at);

		if (!$found) {
			return ['status' => false, 'message' => _('That user or client no longer exists.')];
		}

		return $this->requestLog->clearFor($found['macs']);
	}

	/**
	 * Delete the log files the row's clients have sent.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> Status.
	 */
	public function clearStored(array $at)
	{
		$found = $this->inventory($at);

		if (!$found) {
			return ['status' => false, 'message' => _('That user or client no longer exists.')];
		}

		$cleared = true;

		foreach (array_keys($found['stored']['clients']) as $client) {
			$cleared = $this->logs->removeClientLogs($client) && $cleared;
		}

		return $cleared
			? ['status' => true]
			: ['status' => false, 'message' => _('Not every stored log could be removed. The Asterisk log says which.')];
	}

	/**
	 * A user's call history, counted: asked for after the page has loaded.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> Status, and calls and recordings, or
	 *                              `available` false when there is none to read.
	 */
	public function history(array $at)
	{
		$at = self::target($at);
		$count = isset($at['user']) ? $this->users->history($at['user']) : null;

		return $count === null
			? ['status' => true, 'available' => false]
			: ['status' => true, 'available' => true] + $count;
	}

	/**
	 * The Call history table on a user's Overview.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> Users::listCalls(); empty on a client.
	 */
	public function listCalls(array $at)
	{
		$at = self::target($at);

		return isset($at['user'])
			? $this->users->listCalls($at['user'])
			: ['total' => 0, 'rows' => [], 'available' => false];
	}

	/**
	 * Clear a user's call history and recordings, keeping the user.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> Users::clearHistory(); refused on a client,
	 *                              whose calls are its user's.
	 */
	public function clearHistory(array $at)
	{
		$at = self::target($at);

		if (!isset($at['user'])) {
			return ['status' => false, 'message' => _('Call history belongs to a user. Open its Overview to clear it.')];
		}

		$cleared = $this->users->clearHistory($at['user']);

		if (!empty($cleared['status'])) {
			$this->logInfo('overview: cleared the call history of user ' . $at['user'] . ': ' . (int) $cleared['rows'] . ' rows, ' . (int) $cleared['recordings'] . ' recordings');
		}

		return $cleared;
	}

	/**
	 * The Voicemail table on a user's Overview.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> Users::listVoicemail(); empty on a client.
	 */
	public function listVoicemail(array $at)
	{
		$at = self::target($at);

		return isset($at['user']) ? $this->users->listVoicemail($at['user']) : ['total' => 0, 'rows' => []];
	}

	/**
	 * Delete a user's voicemail messages, keeping the mailbox and the user.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> Users::clearVoicemail(); refused on a
	 *                              client, whose mailbox is its user's.
	 */
	public function clearVoicemail(array $at)
	{
		$at = self::target($at);

		if (!isset($at['user'])) {
			return ['status' => false, 'message' => _('Voicemail belongs to a user. Open its Overview to clear it.')];
		}

		$cleared = $this->users->clearVoicemail($at['user']);

		if (!empty($cleared['status'])) {
			$this->logInfo('overview: cleared ' . (int) $cleared['removed'] . ' voicemail messages of user ' . $at['user']);
		}

		return $cleared;
	}

	/**
	 * Delete all: the row, and everything inventory() lists for it.
	 *
	 * The bans naming it go first, through Bans::deleteBan() so a copy in
	 * fail2ban is lifted; then the user (Users::deleteUser(), which takes its
	 * clients and the FreePBX side) or the client. Bans that only apply stay.
	 * A ban that will not go stops it there: the bans before it are gone, the
	 * row is not. There is no undo.
	 *
	 * @param array<string, string> $at target().
	 *
	 * @return array<string, mixed> Status; bans and clients removed; `reload`
	 *                              when Apply Config was raised.
	 */
	public function purge(array $at)
	{
		$found = $this->inventory($at);

		if (!$found) {
			return ['status' => false, 'message' => _('That user or client no longer exists.')];
		}

		$bans = 0;

		foreach ($found['named'] as $ban) {
			$deleted = $this->bans->deleteBan($ban);

			if (empty($deleted['status'])) {
				return ['status' => false, 'message' => sprintf(_('Ban #%d could not be deleted, so the rest was left in place.'), $ban)];
			}

			$bans++;
		}

		if ($found['kind'] === 'user') {
			$removed = $this->users->deleteUser($found['id']);

			if (empty($removed['status'])) {
				return $removed;
			}

			$this->logInfo('overview: deleted user ' . $found['id'] . ' with ' . count($found['clients']) . ' clients and ' . $bans . ' bans');

			return ['status' => true, 'reload' => true, 'bans' => $bans, 'clients' => count($found['clients'])];
		}

		$removed = $this->clients->deleteClient($found['id']);

		if (empty($removed['status'])) {
			return $removed;
		}

		$this->logInfo('overview: deleted client ' . $found['id'] . ' with ' . $bans . ' bans');

		return ['status' => true, 'bans' => $bans, 'clients' => 1];
	}
}
