<?php

// src/Counts.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * How many rows each table holds, for the tabs that are labelled with them.
 *
 * **The number cannot come from the table**: a table asked with something in
 * its search box answers with the total of what matched, so a tab reading its
 * own rows would count down as somebody typed underneath it. It is read here
 * instead -- once on the way into the page, and again over the `counts`
 * command whenever something on the page has changed one.
 *
 * Five COUNT(*)s and one question to fail2ban, and no state: four are
 * rowCount() over this module's tables, the fifth counts users in Core's
 * `devices`, and bans are asked of the helper. That last is a sudo call and
 * two Python start-ups, so it is made only for the unnarrowed scope -- the
 * module page, the one strip with a Bans tab on it.
 *
 * The counts are named for the tables rather than the tabs that show them, and
 * that name is the whole of the contract with views/partials/counts.php: an
 * element with `data-oryk-count="resources"` gets the resources count written
 * into it, wherever it sits.
 *
 * A scope narrows them with the same parameters a list command takes:
 * `profile_id` for the profile editor's tabs and the client editor's
 * Resources, `mac` for a Logs tab, `device_id` for a user editor's Clients.
 * A key that is there is honoured as given rather than falling back to
 * everything -- which is why a client with no profile asks with profile_id=0
 * and means it.
 */
class Counts extends Service
{
	/** @var Fail2ban */
	private $fail2ban;

	/**
	 * @param object   $freepbx  FreePBX application instance.
	 * @param Fail2ban $fail2ban What the bans count is asked of.
	 */
	public function __construct($freepbx, Fail2ban $fail2ban)
	{
		parent::__construct($freepbx);

		$this->fail2ban = $fail2ban;
	}

	/**
	 * Every count a page can label a tab with, for one page's scope.
	 *
	 * All of them whatever the page is: asking for them by name would mean the page,
	 * the command and the JavaScript agreeing on a list, which is three places for
	 * a tab to be left out of.
	 *
	 * @param array<string, mixed> $scope profile_id, mac, device_id: what
	 *                                    narrows them.
	 *                                    Anything else in it is ignored, so a
	 *                                    whole request can be handed over.
	 *
	 * @return array<string, int> Counts, keyed as a badge names them.
	 */
	public function pageCounts(array $scope = [])
	{
		$profileId = array_key_exists('profile_id', $scope) ? (int) $scope['profile_id'] : null;
		$mac = array_key_exists('mac', $scope) ? Mac::stored($scope['mac']) : null;

		// A user editor counts the clients pointing at it rather than a profile's.
		$clients = array_key_exists('device_id', $scope)
			? $this->rowCount($this->clientsTable, 'device_id', (string) $scope['device_id'])
			: $this->rowCount($this->clientsTable, 'profile_id', $profileId);

		$counts = [
			'clients' => $clients,
			'profiles' => $this->rowCount($this->profilesTable),
			'resources' => $this->rowCount($this->resourcesTable, 'profile_id', $profileId),
			'logs' => $this->rowCount($this->logsTable, 'mac', $mac),
			'users' => $this->userCount(),
		];

		if ($profileId === null && $mac === null && !array_key_exists('device_id', $scope)) {
			$counts['bans'] = $this->fail2ban->count();
		}

		return $counts;
	}

	/**
	 * How many Extension/User devices there are. Not a table of this module's,
	 * so not rowCount(): the same shape Users lists, never narrowed.
	 *
	 * @return int Users counted, or zero when Core could not be asked.
	 */
	private function userCount()
	{
		try {
			$stmt = $this->db->prepare('SELECT COUNT(*) FROM devices d WHERE ' . Users::SHAPE);
			$stmt->execute();

			return (int) $stmt->fetchColumn();
		} catch (\Exception $e) {
			return 0;
		}
	}

	/**
	 * The counts for the scope an AJAX request asked with.
	 *
	 * The request goes in whole: pageCounts() takes the two keys it knows and
	 * leaves the rest alone.
	 *
	 * @return array<string, mixed> Status and the counts.
	 */
	public function countsRequest()
	{
		return ['status' => true, 'counts' => $this->pageCounts($_REQUEST)];
	}
}
