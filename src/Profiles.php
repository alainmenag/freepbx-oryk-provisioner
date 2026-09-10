<?php

// src/Profiles.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * The profiles table.
 *
 * A profile is a name, the resources it serves and the clients assigned
 * to it. A profile clients still point at is refused deletion rather
 * than cascading; its resources do cascade, and take their uploaded
 * files with them.
 */
class Profiles extends Service
{
	/**
	 * @var FileRepo
	 */
	private $files;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, FileRepo $files)
	{
		parent::__construct($freepbx);

		$this->files = $files;
	}

	/**
	 * Rows for the Profiles table.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	public function listProfiles()
	{
		$sortable = [
			'name' => 'p.name',
			'assigned' => 'assigned',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['name'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$where = '';

		if ($search !== '') {
			$where = "WHERE p.name LIKE :search";
			$params[':search'] = '%' . $search . '%';
		}

		$countStmt = $this->db->prepare("SELECT COUNT(*) FROM `{$this->profilesTable}` p $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$sql = "
			SELECT
				p.id,
				p.name,
				(
					SELECT COUNT(*)
					FROM `{$this->clientsTable}` pc
					WHERE pc.profile_id = p.id
				) AS assigned
			FROM `{$this->profilesTable}` p
			$where
			ORDER BY $sort $order
			LIMIT :limit OFFSET :offset
		";

		$stmt = $this->db->prepare($sql);
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
		}
		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmt->execute();

		return [
			'total' => $total,
			'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
		];
	}

	/**
	 * One profile, for the editor.
	 *
	 * Read on the way into the page rather than fetched by it: the editor is a
	 * page of its own now, so there is nothing to wait for over AJAX.
	 *
	 * @param mixed $id Profile id.
	 *
	 * @return array<string, mixed>|null The profile, or null when there is none.
	 */
	public function profileRow($id)
	{
		$stmt = $this->db->prepare(
			"SELECT id, name
			FROM `{$this->profilesTable}`
			WHERE id = :id"
		);
		$stmt->execute([':id' => (int) $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	/**
	 * Create or update a profile.
	 *
	 * A name and nothing else. What the profile serves is its resources, each
	 * written on its own page, and who it serves is the clients assigned
	 * to it -- neither is edited here.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function saveProfile($request)
	{
		$id = (int) ($request['id'] ?? 0);
		$name = trim((string) ($request['name'] ?? ''));

		if ($name === '') {
			return ['status' => false, 'message' => _('A profile needs a name.')];
		}

		$taken = $this->db->prepare(
			"SELECT id FROM `{$this->profilesTable}` WHERE name = :name AND id != :id"
		);
		$taken->execute([':name' => $name, ':id' => $id]);

		if ($taken->fetchColumn()) {
			return ['status' => false, 'message' => _('A profile with that name already exists.')];
		}

		if ($id) {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->profilesTable}`
				SET name = :name
				WHERE id = :id"
			);
			$stmt->execute([
				':name' => $name,
				':id' => $id,
			]);

			return ['status' => true, 'id' => $id, 'name' => $name];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->profilesTable}` (name) VALUES (:name)"
		);
		$stmt->execute([':name' => $name]);

		return ['status' => true, 'id' => (int) $this->db->lastInsertId(), 'name' => $name];
	}

	/**
	 * Remove a profile.
	 *
	 * A profile that clients still point at is kept, so a client never
	 * ends up naming a profile that has gone.
	 *
	 * @param mixed $id Profile id.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function deleteProfile($id)
	{
		$id = (int) $id;

		$count = $this->profileClientCount($id);

		if ($count) {
			return [
				'status' => false,
				'message' => sprintf(
					_('This profile is assigned to %s client(s). Reassign them first.'),
					$count
				),
			];
		}

		// Resources go with it. Unlike a client, a resource has
		// no existence apart from the profile that serves it -- there is
		// nothing to reassign it to and nothing left for it to mean.
		//
		// Their ids are read first because an uploaded file is named after
		// the resource it belongs to: once the rows are gone there is
		// nothing left to say which files in the repository were theirs.
		$owned = $this->db->prepare("SELECT id FROM `{$this->resourcesTable}` WHERE profile_id = :id");
		$owned->execute([':id' => $id]);

		foreach ($owned->fetchAll(PDO::FETCH_COLUMN) as $resourceId) {
			$this->files->removeRepoFile($resourceId);
		}

		$resources = $this->db->prepare("DELETE FROM `{$this->resourcesTable}` WHERE profile_id = :id");
		$resources->execute([':id' => $id]);

		$stmt = $this->db->prepare("DELETE FROM `{$this->profilesTable}` WHERE id = :id");
		$stmt->execute([':id' => $id]);

		return ['status' => true];
	}

	/**
	 * How many clients a profile is assigned to.
	 *
	 * Which clients they are is the editor's Clients tab, and that asks for
	 * them itself over listClients, a page at a time. This is the number
	 * alone: what the tab is labelled with, and what a profile still in use
	 * is refused deletion over.
	 *
	 * @param int $profileId Profile id.
	 *
	 * @return int Clients pointing at the profile.
	 */
	public function profileClientCount($profileId)
	{
		$stmt = $this->db->prepare(
			"SELECT COUNT(*) FROM `{$this->clientsTable}` WHERE profile_id = :id"
		);
		$stmt->execute([':id' => (int) $profileId]);

		return (int) $stmt->fetchColumn();
	}

	/**
	 * How many resources a profile serves.
	 *
	 * The rows are a tab's business and it pages through them itself over
	 * listResources; this is the number alone, which labels the tab.
	 *
	 * @param int $profileId Profile id.
	 *
	 * @return int Resources belonging to the profile.
	 */
	public function profileResourceCount($profileId)
	{
		$stmt = $this->db->prepare(
			"SELECT COUNT(*) FROM `{$this->resourcesTable}` WHERE profile_id = :id"
		);
		$stmt->execute([':id' => (int) $profileId]);

		return (int) $stmt->fetchColumn();
	}

	/**
	 * Whether a profile still exists.
	 *
	 * @param int $profileId Profile id.
	 *
	 * @return bool True when the profile is there.
	 */
	public function profileExists($profileId)
	{
		$stmt = $this->db->prepare("SELECT id FROM `{$this->profilesTable}` WHERE id = :id");
		$stmt->execute([':id' => (int) $profileId]);

		return (bool) $stmt->fetchColumn();
	}

	/**
	 * The profiles a client can point at.
	 *
	 * @return array<int, array<string, mixed>> Profile rows, id and name.
	 */
	public function profileChoices()
	{
		$stmt = $this->db->prepare(
			"SELECT id, name FROM `{$this->profilesTable}` ORDER BY name"
		);
		$stmt->execute();

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
}
