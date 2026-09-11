<?php

// src/Counts.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * How many rows each table holds, for the tabs that are labelled with them.
 *
 * Every table in this module is drawn on a tab, and every one of those tabs
 * carries the number of rows behind it. That number cannot come from the
 * table: a table asked with something in its search box answers with the
 * total of what matched, so a tab reading its own rows would count down as
 * somebody typed underneath it. It is read here instead -- once on the way
 * into the page, and again over the `counts` command whenever something on
 * the page has changed one.
 *
 * Four COUNT(*)s and no state, which is why this class holds nothing and is
 * handed nothing: Service already gives every subclass the database and the
 * four table names, and rowCount() is the statement. Going through the four
 * repositories instead would be four methods that exist only to be called
 * from here, and this module already reads across tables where the question
 * is one -- a profile deletes its resources, a log row is joined to the
 * client whose MAC it carries.
 *
 * The counts are named for the tables rather than for the tabs that show
 * them, and a badge in the markup names itself the same way. That name is the
 * whole of the contract with views/partials/counts.php: a page with a
 * `data-oryk-count="resources"` on it gets the resources count written into
 * it, wherever it sits and whatever the tab above it is called.
 *
 * A scope narrows them the way a list command is narrowed, with the same
 * parameters: `profile_id` is the profile editor's two tabs and the client
 * editor's Resources tab; `mac` is a Logs tab. A key that is there is
 * honoured as given rather than falling back to everything -- the rule
 * listClients already follows with the same key, and the reason a client with
 * no profile asks with profile_id=0 and means it.
 */
class Counts extends Service
{
	/**
	 * Every count a page can label a tab with, for one page's scope.
	 *
	 * All four whatever the page is: a page takes the ones it has badges for,
	 * and the rest cost a COUNT(*) each on a table this module owns. Asking
	 * for them by name would mean the page, the command and the JavaScript
	 * behind both agreeing on a list, which is three places for a tab to be
	 * left out of.
	 *
	 * @param array<string, mixed> $scope profile_id, mac: what narrows them.
	 *                                    Anything else in it is ignored, so a
	 *                                    whole request can be handed over.
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
	 * The request goes in whole: what narrows a count is what narrows the
	 * table it counts, and pageCounts() takes the two keys it knows and
	 * leaves the rest -- `module` and `command` included -- alone.
	 *
	 * @return array<string, mixed> Status and the counts.
	 */
	public function countsRequest()
	{
		return ['status' => true, 'counts' => $this->pageCounts($_REQUEST)];
	}
}
