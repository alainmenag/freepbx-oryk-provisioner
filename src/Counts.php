<?php

// src/Counts.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * How many rows each table holds, for the tabs that are labelled with them.
 *
 * The number cannot come from the table: a table asked with something in its
 * search box answers with the total of what matched, so a tab reading its own
 * rows would count down as somebody typed underneath it.
 *
 * Four COUNT(*)s and no state, which is why this class holds nothing and is
 * handed nothing: Service already gives every subclass the database and the
 * four table names.
 *
 * Counts are named for the tables rather than the tabs, and that name is the
 * whole of the contract with views/partials/counts.php: a page with
 * `data-oryk-count="resources"` on it gets the resources count written into it.
 *
 * A scope narrows them the way a list command is narrowed, with the same
 * parameters. A key that is there is honoured as given rather than falling back
 * to everything, which is why a client with no profile asks with profile_id=0.
 */
class Counts extends Service
{
	/**
	 * Every count a page can label a tab with, for one page's scope.
	 *
	 * All four whatever the page is: asking for them by name would mean the page,
	 * the command and the JavaScript agreeing on a list, which is three places for
	 * a tab to be left out of.
	 *
	 * @param array<string, mixed> $scope profile_id, mac: what narrows them.
	 *                                    Anything else is ignored, so a whole
	 *                                    request can be handed over.
	 *
	 * @return array<string, int> Counts, keyed as a badge names them.
	 */
	public function pageCounts(array $scope = [])
	{
		$profileId = array_key_exists('profile_id', $scope) ? (int) $scope['profile_id'] : null;
		$mac = array_key_exists('mac', $scope) ? Mac::stored($scope['mac']) : null;

		return [
			'clients' => $this->rowCount($this->clientsTable, 'profile_id', $profileId),
			'profiles' => $this->rowCount($this->profilesTable),
			'resources' => $this->rowCount($this->resourcesTable, 'profile_id', $profileId),
			'logs' => $this->rowCount($this->logsTable, 'mac', $mac),
		];
	}

	/**
	 * The counts for the scope an AJAX request asked with.
	 *
	 * The request goes in whole: pageCounts() takes the two keys it knows and
	 * leaves the rest -- `module` and `command` included -- alone.
	 *
	 * @return array<string, mixed> Status and the counts.
	 */
	public function countsRequest()
	{
		return ['status' => true, 'counts' => $this->pageCounts($_REQUEST)];
	}
}
