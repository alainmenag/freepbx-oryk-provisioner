<?php

// src/Profiles.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * The profiles table.
 *
 * A profile is a name, the resources it serves and the clients assigned to it.
 * A profile clients still point at is refused deletion rather than cascading;
 * its resources do cascade, and take their uploaded files with them.
 *
 * It can also be switched off, the same switch a client has one level up: a
 * disabled profile serves nothing to anybody, so every client assigned to it
 * is refused without any of them being touched.
 */
class Profiles extends Service
{
	// The switch is the same on both tables, written once -- see src/Enabled.php.
	use Enabled;

	/** @var FileRepo */
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
			'enabled' => 'p.enabled',
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
				p.enabled,
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
	 * page of its own, so there is nothing to wait for over AJAX.
	 *
	 * @param mixed $id Profile id.
	 *
	 * @return array<string, mixed>|null The profile, or null when there is none.
	 */
	public function profileRow($id)
	{
		$stmt = $this->db->prepare(
			"SELECT id, name, enabled
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
	 * A name and whether it serves at all. What the profile serves is its
	 * resources, each written on its own page, and who it serves is the clients
	 * assigned to it -- neither is edited here.
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

		// Enabled unless the page said otherwise -- see enabledSubmitted().
		$enabled = self::enabledSubmitted($request);

		if ($id) {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->profilesTable}`
				SET name = :name, enabled = :enabled
				WHERE id = :id"
			);
			$stmt->execute([
				':name' => $name,
				':enabled' => $enabled,
				':id' => $id,
			]);

			return ['status' => true, 'id' => $id, 'name' => $name];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->profilesTable}` (name, enabled) VALUES (:name, :enabled)"
		);
		$stmt->execute([':name' => $name, ':enabled' => $enabled]);

		return ['status' => true, 'id' => (int) $this->db->lastInsertId(), 'name' => $name];
	}

	/**
	 * Switch a profile on or off.
	 *
	 * The client's switch one level up: a profile switched off stops every phone
	 * assigned to it at once, and switching it back on starts them again with
	 * nothing about any of those clients changed in between.
	 *
	 * Unlike deletion, it does not care how many clients point at the profile.
	 * Deletion is refused while any do, because a client must never name a profile
	 * that has gone; this leaves the profile where it is, which is what makes it
	 * the safe way to take a fleet out of service.
	 *
	 * The write is the trait's; what is this class's is the row and how a missing
	 * one is said.
	 *
	 * @param array<string, mixed> $request id, and the state to put it in.
	 *
	 * @return array<string, mixed> Status, and the state the profile is in.
	 */
	public function setProfileEnabled($request)
	{
		$id = (int) ($request['id'] ?? 0);

		if (!$id || !$this->profileRow($id)) {
			return ['status' => false, 'message' => _('That profile no longer exists.')];
		}

		return $this->setEnabled($this->profilesTable, $request);
	}

	/**
	 * Remove a profile.
	 *
	 * A profile that clients still point at is kept, so a client never ends up
	 * naming a profile that has gone.
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

		// Resources go with it: unlike a client, a resource has no existence apart
		// from the profile that serves it. Their ids are read first because an
		// uploaded file is named after the resource it belongs to -- once the rows
		// are gone there is nothing to say which files were theirs.
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
	 * The rule that a profile still in use is not deleted, and only that: what a
	 * tab is labelled with comes from Counts, which counts the same table the same
	 * way. Both are rowCount(), so there is one statement between them.
	 *
	 * @param int $profileId Profile id.
	 *
	 * @return int Clients pointing at the profile.
	 */
	public function profileClientCount($profileId)
	{
		return $this->rowCount($this->clientsTable, 'profile_id', (int) $profileId);
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
	 * A disabled profile is still one of them, deliberately: it is what a client
	 * being set up against a profile not yet in service is assigned to. The state
	 * travels with the name so the select can say which are switched off rather
	 * than offering them as though they were serving.
	 *
	 * @return array<int, array<string, mixed>> Profile rows: id, name, enabled.
	 */
	public function profileChoices()
	{
		$stmt = $this->db->prepare(
			"SELECT id, name, enabled FROM `{$this->profilesTable}` ORDER BY name"
		);
		$stmt->execute();

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}
}
