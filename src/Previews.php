<?php

// src/Previews.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * Which filename does this phone ask this file by.
 *
 * One URL per row, whichever direction the file travels: a template and an
 * uploaded file are fetched from it, a log is PUT to it and read back from it.
 *
 * The resource editor's Clients tab and the client editor's Resources tab are
 * one idea seen from both ends, so both decorators are here -- which is what
 * keeps Clients and Resources from having to know about each other. Neither
 * reads more than the page of rows it was handed.
 */
class Previews extends Service
{
	/** @var Clients */
	private $clients;

	/** @var Matcher */
	private $matcher;

	/** @var Template */
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
	 * A resource's name is a template, so the file a phone actually asks for is a
	 * different string per client -- which is why a resource has no one URL to
	 * preview and why this belongs on a client row.
	 *
	 * Each row renders against the values the endpoint would use, read the same way
	 * through clientByMac(). A row whose client has since moved to another profile
	 * is left undecorated.
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
			"SELECT id, profile_id, name
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
			$rows[$index]['url'] = $request['url'];
		}

		return $rows;
	}

	/**
	 * The filename one client asks each of these resources for, added to its row.
	 *
	 * withResourceFilenames() the other way round -- one client over many resources
	 * here -- so both go through resourceRequest() rather than either tab having
	 * its own idea of what a phone asks for. The client's values are read once.
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
			$rows[$index]['url'] = $request['url'];
		}

		return $rows;
	}
}
