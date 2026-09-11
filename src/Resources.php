<?php

// src/Resources.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * The resources table: the files a profile serves.
 *
 * A resource is a filename and either a template or an uploaded file.
 * Uploading and removing that file live here rather than in FileRepo,
 * because both are operations on a resource that happen to write a
 * file -- the file is what makes the resource static, so the row and the
 * file are saved together or not at all.
 */
class Resources extends Service
{
	/**
	 * @var Profiles
	 */
	private $profiles;

	/**
	 * @var FileRepo
	 */
	private $files;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Profiles $profiles, FileRepo $files)
	{
		parent::__construct($freepbx);

		$this->profiles = $profiles;
		$this->files = $files;
	}

	/**
	 * Rows for a profile's Resources table.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	public function listResources()
	{
		$profileId = (int) ($_REQUEST['profile_id'] ?? 0);

		$sortable = [
			'name' => 'name',
			'file_size' => 'file_size',
			'updated_at' => 'updated_at',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['name'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		// Always narrowed to the one profile: the table is on that profile's
		// page and a resource has no meaning away from it.
		$where = 'WHERE profile_id = :profile_id';
		$params = [':profile_id' => $profileId];

		if ($search !== '') {
			$where .= ' AND name LIKE :search';
			$params[':search'] = '%' . $search . '%';
		}

		$countStmt = $this->db->prepare("SELECT COUNT(*) FROM `{$this->resourcesTable}` $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$sql = "
			SELECT id, profile_id, name, file_size, file_uploaded_at, updated_at
			FROM `{$this->resourcesTable}`
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

		$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

		return [
			'total' => $total,
			'rows' => $rows,
		];
	}

	/**
	 * One resource of one profile, for the editor.
	 *
	 * Read on the way into the page rather than fetched by it, the same way
	 * the profile editor reads its profile. The profile is part of the lookup
	 * rather than checked after it: a resource id that belongs to a different
	 * profile names nothing at this URL.
	 *
	 * @param mixed $id        Resource id.
	 * @param int   $profileId Profile it has to belong to.
	 *
	 * @return array<string, mixed>|null The resource, or null when there is none.
	 */
	public function resourceRow($id, $profileId)
	{
		$stmt = $this->db->prepare(
			"SELECT id, profile_id, name, template, file_size, file_uploaded_at
			FROM `{$this->resourcesTable}`
			WHERE id = :id AND profile_id = :profile_id"
		);
		$stmt->execute([':id' => (int) $id, ':profile_id' => (int) $profileId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	/**
	 * Create or update a resource.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function saveResource($request)
	{
		$id = (int) ($request['id'] ?? 0);
		$profileId = (int) ($request['profile_id'] ?? 0);
		$name = trim((string) ($request['name'] ?? ''));
		$template = (string) ($request['template'] ?? '');

		if (!$this->profiles->profileExists($profileId)) {
			return ['status' => false, 'message' => _('That profile no longer exists.')];
		}

		if ($name === '') {
			return ['status' => false, 'message' => _('A resource needs the filename a phone asks for.')];
		}

		// The name is matched against the last segment of a request path, so
		// a separator in it could never match anything. Better said here than
		// found out as a phone quietly failing to provision.
		if (strpbrk($name, '/\\') !== false) {
			return ['status' => false, 'message' => _('A filename cannot contain a slash.')];
		}

		// Counted in characters, which is what the column holds, rather than
		// in bytes: a name is almost always ASCII, where the two are the same.
		if (mb_strlen($name) > 180) {
			return ['status' => false, 'message' => _('That filename is too long.')];
		}

		$taken = $this->db->prepare(
			"SELECT id FROM `{$this->resourcesTable}`
			WHERE profile_id = :profile_id AND name = :name AND id != :id"
		);
		$taken->execute([':profile_id' => $profileId, ':name' => $name, ':id' => $id]);

		if ($taken->fetchColumn()) {
			return ['status' => false, 'message' => _('This profile already serves a file by that name.')];
		}

		if ($id) {
			$existing = $this->resourceRow($id, $profileId);

			if (!$existing) {
				return ['status' => false, 'message' => _('That resource no longer exists.')];
			}

			// Two reasons the template may not be written, and they are the
			// same reason twice: this only writes what it was actually given.
			//
			// A resource serving an uploaded file is not showing a template box
			// at all, so its text stays underneath the file rather than being
			// destroyed by it -- remove the file and the resource is the
			// template it was. And a caller that sent no template did not mean
			// an empty one: uploading a file saves the resource's name along
			// with it, and that is a save of the name and nothing else.
			$set = 'name = :name';
			$params = [':name' => $name, ':id' => $id, ':profile_id' => $profileId];

			if (array_key_exists('template', $request) && $existing['file_size'] === null) {
				$set .= ', template = :template';
				$params[':template'] = $template;
			}

			// The profile is in the WHERE rather than trusted from the form:
			// a resource does not move between profiles, and an id from one
			// profile posted at another is not an edit of anything.
			$stmt = $this->db->prepare(
				"UPDATE `{$this->resourcesTable}`
				SET $set
				WHERE id = :id AND profile_id = :profile_id"
			);
			$stmt->execute($params);

			return ['status' => true, 'id' => $id, 'profile_id' => $profileId, 'name' => $name];
		}

		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->resourcesTable}` (profile_id, name, template)
			VALUES (:profile_id, :name, :template)"
		);
		$stmt->execute([
			':profile_id' => $profileId,
			':name' => $name,
			':template' => $template,
		]);

		return [
			'status' => true,
			'id' => (int) $this->db->lastInsertId(),
			'profile_id' => $profileId,
			'name' => $name,
		];
	}

	/**
	 * Remove a resource.
	 *
	 * Nothing points at a resource the way a client points at a
	 * profile, so there is nothing to refuse this for.
	 *
	 * Its uploaded file goes first, while there is still a row to say the
	 * id: the file is named after the resource and nothing else, so a row
	 * deleted without it would leave a number in the repository that
	 * nothing on the system can account for.
	 *
	 * @param mixed $id Resource id.
	 *
	 * @return array<string, mixed> Status of the removal.
	 */
	/**
	 * The files one profile serves, as the navigator lists them.
	 *
	 * Narrowed to the profile, the way resourceRow() is and for the same
	 * reason: a resource has no existence apart from the profile that serves
	 * it, so there is no such thing as the list of all of them.
	 *
	 * @param int $profileId Profile whose files these are.
	 *
	 * @return array<int, array<string, mixed>> Resource rows: id, name.
	 */
	public function resourceChoices($profileId)
	{
		$stmt = $this->db->prepare(
			"SELECT id, name
				FROM `{$this->resourcesTable}`
				WHERE profile_id = :profile_id
				ORDER BY name"
		);
		$stmt->execute([':profile_id' => (int) $profileId]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function deleteResource($id)
	{
		$this->files->removeRepoFile($id);

		$stmt = $this->db->prepare("DELETE FROM `{$this->resourcesTable}` WHERE id = :id");
		$stmt->execute([':id' => (int) $id]);

		return ['status' => true];
	}

	/**
	 * Save the resource's name, when a file action was given one.
	 *
	 * Uploading and removing a file both save the resource they act on: the
	 * button was pressed on a page that may be carrying a renamed resource,
	 * and it means the page. Neither has an opinion about what a name may be
	 * -- saveResource() already knows, and a second opinion is a second thing
	 * to keep in step with the first.
	 *
	 * @param array<string, mixed> $request   Submitted form values.
	 * @param int                  $id        Resource id.
	 * @param int                  $profileId Profile it belongs to.
	 *
	 * @return array<string, mixed>|null saveResource()'s answer, or null when no
	 *                                   name was sent and nothing was saved.
	 */
	private function saveResourceName($request, $id, $profileId)
	{
		if (!array_key_exists('name', $request)) {
			return null;
		}

		return $this->saveResource([
			'id' => $id,
			'profile_id' => $profileId,
			'name' => $request['name'],
		]);
	}

	/**
	 * Store an uploaded file against a resource.
	 *
	 * The file is what the resource serves from now on, and the template it
	 * had is left in the column underneath -- there is no second thing to
	 * set and nothing to undo but removing the file again.
	 *
	 * The resource is saved as part of it, so choosing a file is the whole of
	 * what has to be done: a filename edited on the way to the upload is
	 * written with it rather than sitting unsaved behind a file that is
	 * already stored.
	 *
	 * The resource has to have been written first, because the file is named
	 * after its id and a resource that has never been saved has not got one.
	 * That is why the control is inert on a new resource rather than absent.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, the size stored, and a message when refused.
	 */
	public function uploadResourceFile($request)
	{
		// A body over post_max_size arrives with $_POST and $_FILES both
		// empty and no error set anywhere -- PHP discards it before any of
		// this runs. The only trace is a Content-Length with nothing behind
		// it, and without this the answer would be 'no file was uploaded',
		// which is true and useless.
		if (!$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
			return [
				'status' => false,
				'message' => sprintf(
					_('That file is larger than this server accepts (%s).'),
					ini_get('post_max_size')
				),
			];
		}

		$id = (int) ($request['id'] ?? 0);
		$profileId = (int) ($request['profile_id'] ?? 0);
		$resource = $this->resourceRow($id, $profileId);

		if (!$resource) {
			return ['status' => false, 'message' => _('That resource no longer exists.')];
		}

		// The filename is saved with the file, and first: a name that cannot be
		// saved refuses the whole action before anything is written.
		$saved = $this->saveResourceName($request, $id, $profileId);

		if ($saved && !$saved['status']) {
			return $saved;
		}

		$name = $saved['name'] ?? (string) $resource['name'];

		$file = $_FILES['file'] ?? null;

		if (!is_array($file) || !isset($file['error'])) {
			return ['status' => false, 'message' => _('No file was uploaded.')];
		}

		if ($file['error'] !== UPLOAD_ERR_OK) {
			return ['status' => false, 'message' => $this->uploadErrorMessage((int) $file['error'])];
		}

		// Nothing else in here reads the name the browser sent, and this is
		// why: the only thing it could be used for is a path.
		if (!is_uploaded_file((string) $file['tmp_name'])) {
			return ['status' => false, 'message' => _('That was not an uploaded file.')];
		}

		if (!$this->files->ensureRepo()) {
			return [
				'status' => false,
				'message' => sprintf(_('%s cannot be written to.'), $this->files->repoPath()),
			];
		}

		$target = $this->files->repoFile($id);

		if (!@move_uploaded_file((string) $file['tmp_name'], $target)) {
			return [
				'status' => false,
				'message' => sprintf(_('The file could not be stored at %s.'), $target),
			];
		}

		@chmod($target, 0640);
		clearstatcache(true, $target);

		// Read back off the file rather than taken from the upload: the size
		// is the column that says this resource is a file at all, so it says
		// what is on the disk and not what was meant to be.
		$size = (int) filesize($target);

		$stmt = $this->db->prepare(
			"UPDATE `{$this->resourcesTable}`
			SET file_size = :size, file_uploaded_at = NOW()
			WHERE id = :id AND profile_id = :profile_id"
		);
		$stmt->execute([':size' => $size, ':id' => $id, ':profile_id' => $profileId]);

		$this->log(sprintf(
			'oryk_provisioner: stored %s bytes for resource %s (%s)',
			$size,
			$id,
			$name
		), null, 'INFO');

		return [
			'status' => true,
			'id' => $id,
			'name' => $name,
			'file_size' => $size,
			'file_uploaded_at' => date('Y-m-d H:i:s'),
		];
	}

	/**
	 * Take the uploaded file off a resource.
	 *
	 * What is left is the resource it was before the upload, template and
	 * all: the file never touched that column, so there is nothing to put
	 * back. The resource is saved on the way through, as it is for an upload.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return array<string, mixed> Status, and a message when refused.
	 */
	public function deleteResourceFile($request)
	{
		$id = (int) ($request['id'] ?? 0);
		$profileId = (int) ($request['profile_id'] ?? 0);
		$resource = $this->resourceRow($id, $profileId);

		if (!$resource) {
			return ['status' => false, 'message' => _('That resource no longer exists.')];
		}

		$saved = $this->saveResourceName($request, $id, $profileId);

		if ($saved && !$saved['status']) {
			return $saved;
		}

		$name = $saved['name'] ?? (string) $resource['name'];

		$this->files->removeRepoFile($id);

		$stmt = $this->db->prepare(
			"UPDATE `{$this->resourcesTable}`
			SET file_size = NULL, file_uploaded_at = NULL
			WHERE id = :id AND profile_id = :profile_id"
		);
		$stmt->execute([':id' => $id, ':profile_id' => $profileId]);

		return ['status' => true, 'id' => $id, 'name' => $name];
	}

	/**
	 * What one of PHP's upload error codes means, in words.
	 *
	 * @param int $code UPLOAD_ERR_* constant.
	 *
	 * @return string Message for the editor.
	 */
	private function uploadErrorMessage($code)
	{
		if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
			return sprintf(
				_('That file is larger than this server accepts (%s).'),
				ini_get('upload_max_filesize')
			);
		}

		$messages = [
			UPLOAD_ERR_PARTIAL => _('The upload did not finish.'),
			UPLOAD_ERR_NO_FILE => _('No file was uploaded.'),
			UPLOAD_ERR_NO_TMP_DIR => _('This server has nowhere to put the upload.'),
			UPLOAD_ERR_CANT_WRITE => _('This server has nowhere to put the upload.'),
			UPLOAD_ERR_EXTENSION => _('A PHP extension refused the upload.'),
		];

		return $messages[$code] ?? _('The upload failed.');
	}
}
