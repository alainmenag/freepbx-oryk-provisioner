<?php

// src/Previews.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * Which filename does this phone ask this file by.
 *
 * The resource editor's Clients tab and the client editor's Resources tab
 * are one idea seen from both ends, so both decorators are here rather
 * than one on each table -- which is also what keeps Clients and Resources
 * from having to know about each other. Neither reads more than the page
 * of rows it was handed: rendering a name costs a client's values, and a
 * row that is not on screen is not worth them.
 */
class Previews extends Service
{
	/**
	 * @var Clients
	 */
	private $clients;

	/**
	 * @var Matcher
	 */
	private $matcher;

	/**
	 * @var Template
	 */
	private $template;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Clients $clients, Matcher $matcher, Template $template)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->matcher = $matcher;
		$this->template = $template;
	}

	/**
	 * The filename each client asks one resource for, added to its row.
	 *
	 * A resource's name is a template, so the file a phone actually asks for
	 * is a different string per client -- which is why a resource has no one
	 * URL to preview and why this belongs on a client row rather than on the
	 * resource itself.
	 *
	 * Each row is rendered against the values the endpoint would render it
	 * against, read the same way through clientByMac(), so what the tab
	 * shows is what a phone gets rather than a second guess at it.
	 *
	 * A row whose client has since moved to another profile is left
	 * undecorated rather than shown a filename this resource would not
	 * answer to.
	 *
	 * @param array<int, array<string, mixed>> $rows       Client rows as read.
	 * @param mixed                            $resourceId Resource they are being asked about.
	 *
	 * @return array<int, array<string, mixed>> The same rows, decorated.
	 */
	public function withResourceFilenames(array $rows, $resourceId)
	{
		if (!$rows) {
			return $rows;
		}

		$stmt = $this->db->prepare(
			"SELECT id, profile_id, name, type
			FROM `{$this->resourcesTable}`
			WHERE id = :id"
		);
		$stmt->execute([':id' => (int) $resourceId]);
		$resource = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$resource) {
			return $rows;
		}

		foreach ($rows as $index => $row) {
			$mac = (string) $row['mac'];
			$provisioning = $this->clients->clientByMac($mac);

			if (!$provisioning || (int) $provisioning['profile_id'] !== (int) $resource['profile_id']) {
				continue;
			}

			$request = $this->matcher->resourceRequest(
				(string) $resource['name'],
				$this->template->provisioningValues($provisioning),
				$mac
			);

			$rows[$index]['filename'] = $request['filename'];
			// A log is not served, so there is nothing to preview: the
			// filename is still worth having -- it is what that phone PUTs
			// to -- and the link would be a 404 with a Render button on it.
			$rows[$index]['url'] = $resource['type'] === 'log' ? '' : $request['url'];
		}

		return $rows;
	}

	/**
	 * The filename one client asks each of these resources for, added to its row.
	 *
	 * withResourceFilenames() the other way round -- one resource over many
	 * clients there, one client over many resources here -- so both go
	 * through resourceRequest() and render against the values the endpoint
	 * would use, rather than either tab having its own idea of what a phone
	 * asks for.
	 *
	 * The client's values are read once and rendered against every row: it is
	 * one phone here, where withResourceFilenames() has one name and a page
	 * of phones.
	 *
	 * A resource whose profile is not the one this client is assigned to is
	 * left undecorated -- it is not served to this phone, whatever its name
	 * renders to.
	 *
	 * @param array<int, array<string, mixed>> $rows     Resource rows as read.
	 * @param mixed                            $clientId Client they are being asked about.
	 *
	 * @return array<int, array<string, mixed>> The same rows, decorated.
	 */
	public function withClientFilenames(array $rows, $clientId)
	{
		if (!$rows) {
			return $rows;
		}

		$client = $this->clients->clientRow($clientId);

		if (!$client || !$client['profile_id']) {
			return $rows;
		}

		$mac = (string) $client['mac'];
		$provisioning = $this->clients->clientByMac($mac);

		if (!$provisioning) {
			return $rows;
		}

		$values = $this->template->provisioningValues($provisioning);

		foreach ($rows as $index => $row) {
			if ((int) $row['profile_id'] !== (int) $client['profile_id']) {
				continue;
			}

			$request = $this->matcher->resourceRequest((string) $row['name'], $values, $mac);

			$rows[$index]['filename'] = $request['filename'];
			$rows[$index]['url'] = ($row['type'] ?? '') === 'log' ? '' : $request['url'];
		}

		return $rows;
	}
}
