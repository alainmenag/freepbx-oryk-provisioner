<?php

// src/Services.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * The services table, and the links between its rows.
 *
 * A service is a name and a slug. Which services sit under which is the links
 * table: one row per parent and child, each named by its slug, so a service
 * has any number of parents and
 * any number of children. One with a child is a service pack: that is read
 * off the links, never stored. Not to be confused with Service, the base class
 * this extends.
 *
 * A user is assigned services in a third table, by extension and slug; see
 * userServices() and setUserService().
 *
 * Every service has a slug, made from its name and never typed, and the ones whose slug is in DEFAULTS are the
 * module's: written by seed() on install, and refused every edit and delete.
 */
class Services extends Service
{
	/**
	 * The services the module ships, by slug: `name`, and under `services`
	 * the slugs of the services directly under it.
	 *
	 * **Adding, renaming or regrouping one is an edit here and nothing else**;
	 * seed() carries it to the tables on the next install or upgrade. A slug
	 * is the identity, so a changed name renames the row and a changed slug is
	 * a new service. A slug taken out leaves its row behind as an ordinary
	 * service, which can then be edited or deleted by hand.
	 */
	const DEFAULTS = [
		'advanced-user' => ['name' => 'Advanced User', 'services' => ['basic-user', 'call-recording', 'on-demand-recording']],
		'basic-user' => ['name' => 'Basic User', 'services' => ['find-me-follow', 'guest-user', 'voicemail']],
		'guest-user' => ['name' => 'Guest User', 'services' => ['support']],
		'call-recording' => ['name' => 'Call Recording'],
		'find-me-follow' => ['name' => 'Find Me Follow'],
		'on-demand-recording' => ['name' => 'On Demand Recording'],
		'support' => ['name' => 'Support'],
		'voicemail' => ['name' => 'Voicemail'],
	];

	/** Longest name stored: the column's width. */
	const NAME_MAX = 191;

	/** Longest slug stored: the column's width. */
	const SLUG_MAX = 64;

	/** A slug: lowercase letters and digits, in runs joined by single hyphens. */
	const SLUG_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

	/**
	 * Rows for the Services table. `managed` is whether each is one of the
	 * module's; `assignments` is how many users are assigned it themselves,
	 * not counting those who have it only through a pack.
	 *
	 * Narrowed by two filters, each off unless it is one of its two values:
	 * `source` (`module`, `custom`) and `kind` (`pack`, a service with at
	 * least one under it, or `single`).
	 *
	 * @param array<int, string>|null $scope Slugs to keep, or null for all: a
	 *                                       navigator scope.
	 *
	 * @return array<string, mixed> Total row count, the page of rows, and
	 *                              counts(), for the filters' own labels.
	 */
	public function listServices($scope = null)
	{
		$sortable = [
			'name' => 's.name',
			'assignments' => 'assignments',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['name'];
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'desc' ? 'DESC' : 'ASC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$clauses = [];

		if ($scope !== null) {
			$clauses[] = '(' . $this->inClause('s.slug', $scope, 'scope', $params) . ')';
		}

		if ($search !== '') {
			$clauses[] = '(s.name LIKE :search OR s.slug LIKE :search_slug)';
			$params[':search'] = '%' . $search . '%';
			$params[':search_slug'] = '%' . $search . '%';
		}

		$source = (string) ($_REQUEST['source'] ?? '');

		// "Module" is the slug being in DEFAULTS, as managed() has it.
		if ($source === 'module' || $source === 'custom') {
			$clauses[] = ($source === 'custom' ? 'NOT ' : '')
				. '(' . $this->inClause('s.slug', array_map('strval', array_keys(self::DEFAULTS)), 'default', $params) . ')';
		}

		$kind = (string) ($_REQUEST['kind'] ?? '');

		if ($kind === 'pack' || $kind === 'single') {
			$clauses[] = ($kind === 'single' ? 'NOT ' : '')
				. "EXISTS (SELECT 1 FROM `{$this->serviceLinksTable}` k WHERE k.parent = s.slug)";
		}

		$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

		$countStmt = $this->db->prepare("SELECT COUNT(*) FROM `{$this->servicesTable}` s $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$sql = "
			SELECT
				s.id,
				s.name,
				s.slug,
				(
					SELECT COUNT(*)
					FROM `{$this->serviceAssignmentsTable}` a
					WHERE a.service = s.slug
				) AS assignments
			FROM `{$this->servicesTable}` s
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
			'rows' => array_map([$this, 'marked'], $stmt->fetchAll(PDO::FETCH_ASSOC)),
			'counts' => $this->counts($scope),
		];
	}

	/**
	 * What the Services page's address says its two filters are set to.
	 *
	 * @param array<string, mixed> $request The page's request: `source`, `kind`.
	 *
	 * @return array<string, string> source (all|custom|module) and kind
	 *                               (all|single|pack); all for either when the
	 *                               address does not name it, or names nonsense.
	 */
	public static function filters(array $request)
	{
		return [
			'source' => in_array($request['source'] ?? '', ['custom', 'module'], true) ? (string) $request['source'] : 'all',
			'kind' => in_array($request['kind'] ?? '', ['single', 'pack'], true) ? (string) $request['kind'] : 'all',
		];
	}

	/**
	 * How many services each pairing of the list's two filters holds.
	 *
	 * Of every service in scope, whatever is in the search box: these label
	 * the filters, and the table's own total is what says how many matched.
	 *
	 * @param array<int, string>|null $scope Slugs to count, or null for all.
	 *
	 * @return array<string, array<string, int>> By source (custom|module), then kind (single|pack).
	 */
	public function counts($scope = null)
	{
		$counts = [
			'custom' => ['single' => 0, 'pack' => 0],
			'module' => ['single' => 0, 'pack' => 0],
		];

		$stmt = $this->db->prepare(
			"SELECT s.slug,
				EXISTS (SELECT 1 FROM `{$this->serviceLinksTable}` k WHERE k.parent = s.slug) AS pack
			FROM `{$this->servicesTable}` s"
		);
		$stmt->execute();

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			if ($scope !== null && !in_array((string) $row['slug'], array_map('strval', $scope), true)) {
				continue;
			}

			$counts[self::managed($row['slug']) ? 'module' : 'custom'][(int) $row['pack'] ? 'pack' : 'single']++;
		}

		return $counts;
	}

	/**
	 * One service, by id: what a save or a delete is posted.
	 *
	 * @param mixed $id Service id.
	 *
	 * @return array<string, mixed>|null id, name, slug, managed; null when there is none.
	 */
	public function serviceRow($id)
	{
		$stmt = $this->db->prepare(
			"SELECT id, name, slug FROM `{$this->servicesTable}` WHERE id = :id"
		);
		$stmt->execute([':id' => (int) $id]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ? $this->marked($row) : null;
	}

	/**
	 * The service with a slug: how a page's address names one.
	 *
	 * @param mixed $slug Slug, as the URL carries it.
	 *
	 * @return array<string, mixed>|null id, name, slug, managed; null when there is none.
	 */
	public function serviceBySlug($slug)
	{
		$slug = (string) $slug;

		if ($slug === '') {
			return null;
		}

		$stmt = $this->db->prepare(
			"SELECT id, name, slug FROM `{$this->servicesTable}` WHERE slug = :slug"
		);
		$stmt->execute([':slug' => $slug]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ? $this->marked($row) : null;
	}

	/**
	 * Every service, for the editor's two lists.
	 *
	 * @return array<int, array<string, mixed>> id, name, slug, managed, ordered by name.
	 */
	public function serviceChoices()
	{
		$stmt = $this->db->prepare(
			"SELECT id, name, slug FROM `{$this->servicesTable}` ORDER BY name"
		);
		$stmt->execute();

		return array_map([$this, 'marked'], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * What one service is linked to, and what it may not be linked to.
	 *
	 * A service already under this one cannot also be its parent, and one it
	 * is already under cannot also be its child: either closes a loop.
	 *
	 * @param string $slug The service's slug, or '' for a new one.
	 *
	 * @return array<string, array<int, string>> parents, children: slugs linked
	 *										   directly; barredParents,
	 *										   barredChildren: slugs not to
	 *										   offer. The module's own are not
	 *										   among them: serviceChoices()
	 *										   marks those.
	 */
	public function related($slug)
	{
		$slug = (string) $slug;
		$links = $slug !== '' ? $this->links() : [];
		$found = ['parents' => [], 'children' => []];

		foreach ($links as $link) {
			if ($link[1] === $slug) {
				$found['parents'][] = $link[0];
			}

			if ($link[0] === $slug) {
				$found['children'][] = $link[1];
			}
		}

		return $found + [
			'barredParents' => self::descendants($links, $slug),
			'barredChildren' => self::ancestors($links, $slug),
		];
	}

	/**
	 * Create or update a service, and its links when they were submitted.
	 *
	 * `parents` and `children` are each the whole set -- slugs, comma-separated
	 * or an array -- and replace what was there; a key not submitted leaves
	 * that side as it is. All of it is one transaction.
	 *
	 * One of the module's is refused outright. A service of the operator's
	 * own can still be put under one, or over one, from its own page.
	 *
	 * The slug is never taken from the request: it is made from the name, on
	 * a new service and again whenever the name changes. A slug that changes
	 * is changed on every link naming it, in the same transaction, so nothing
	 * that names the service by it is left pointing at nothing.
	 *
	 * @param array<string, mixed> $request id, name, parents, children.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function saveService($request)
	{
		$id = (int) ($request['id'] ?? 0);
		$name = trim((string) ($request['name'] ?? ''));

		if ($name === '') {
			return ['status' => false, 'message' => _('A service needs a name.')];
		}

		if (strlen($name) > self::NAME_MAX) {
			return ['status' => false, 'message' => sprintf(_('A service name is at most %s characters.'), self::NAME_MAX)];
		}

		$row = $id ? $this->serviceRow($id) : null;

		if ($id && !$row) {
			return ['status' => false, 'message' => _('That service no longer exists.')];
		}

		if ($row && $row['managed']) {
			return ['status' => false, 'message' => _('This service is managed by the module and cannot be changed.')];
		}

		$taken = $this->db->prepare(
			"SELECT id FROM `{$this->servicesTable}` WHERE name = :name AND id != :id"
		);
		$taken->execute([':name' => $name, ':id' => $id]);

		if ($taken->fetchColumn()) {
			return ['status' => false, 'message' => _('A service with that name already exists.')];
		}

		$slug = $row ? (string) $row['slug'] : '';

		// Only a changed name moves it: a save of anything else leaves the
		// address and the links where they are.
		if ($slug === '' || (string) $row['name'] !== $name) {
			$slug = $this->freeSlug(self::slugify($name), $id);
		}

		$sides = [];

		foreach (['parents', 'children'] as $side) {
			if (array_key_exists($side, $request)) {
				$sides[$side] = self::slugs($request[$side]);
			}
		}

		if ($sides) {
			$refused = $this->refusedLinks($row ? (string) $row['slug'] : '', $slug, $sides);

			if ($refused !== null) {
				return ['status' => false, 'message' => $refused];
			}
		}

		$this->db->beginTransaction();

		try {
			if ($id) {
				$stmt = $this->db->prepare(
					"UPDATE `{$this->servicesTable}` SET name = :name, slug = :slug WHERE id = :id"
				);
				$stmt->execute([':name' => $name, ':slug' => $slug, ':id' => $id]);

				if ((string) $row['slug'] !== '' && (string) $row['slug'] !== $slug) {
					$this->renameLinks((string) $row['slug'], $slug);
				}
			} else {
				$stmt = $this->db->prepare(
					"INSERT INTO `{$this->servicesTable}` (name, slug) VALUES (:name, :slug)"
				);
				$stmt->execute([':name' => $name, ':slug' => $slug]);
				$id = (int) $this->db->lastInsertId();
			}

			foreach ($sides as $side => $others) {
				$this->relink($slug, $side, $others);
			}

			$this->db->commit();
		} catch (\Exception $e) {
			$this->db->rollBack();
			$this->logError('saving a service failed: ' . $e->getMessage());

			return ['status' => false, 'message' => _('The service could not be saved.')];
		}

		return ['status' => true, 'id' => $id, 'name' => $name, 'slug' => $slug];
	}

	/**
	 * Remove a service, and every link to or from it.
	 *
	 * The services it was over or under are kept: only the links go, and it
	 * is taken off every user it was assigned to. One of the module's is
	 * refused.
	 *
	 * @param mixed $id Service id.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function deleteService($id)
	{
		$id = (int) $id;
		$row = $this->serviceRow($id);

		if ($row && $row['managed']) {
			return ['status' => false, 'message' => _('This service is managed by the module and cannot be deleted.')];
		}

		if ($row && (string) $row['slug'] !== '') {
			$links = $this->db->prepare(
				"DELETE FROM `{$this->serviceLinksTable}` WHERE parent = :parent OR child = :child"
			);
			$links->execute([':parent' => $row['slug'], ':child' => $row['slug']]);

			$assigned = $this->db->prepare(
				"DELETE FROM `{$this->serviceAssignmentsTable}` WHERE service = :slug"
			);
			$assigned->execute([':slug' => $row['slug']]);
		}

		$stmt = $this->db->prepare("DELETE FROM `{$this->servicesTable}` WHERE id = :id");
		$stmt->execute([':id' => $id]);

		return ['status' => true];
	}

	/**
	 * Every service, as one user has them: for the user editor's Services tab.
	 *
	 * `assigned` is the service being given to the user itself. `via` is the
	 * assigned services it is somewhere under, by name: it reaches the user
	 * through those whether or not it is assigned as well.
	 *
	 * @param mixed $extension The user's extension.
	 *
	 * @return array<int, array<string, mixed>> slug, name, managed, pack,
	 *                                          assigned, via; ordered by name.
	 */
	public function userServices($extension)
	{
		$choices = $this->serviceChoices();
		$names = array_column($choices, 'name', 'slug');
		$links = $this->links();
		$assigned = $this->assigned($extension);
		$packs = array_column($links, 0);
		$via = [];

		foreach ($assigned as $slug) {
			foreach (self::descendants($links, $slug) as $under) {
				if (isset($names[$slug])) {
					$via[$under][] = (string) $names[$slug];
				}
			}
		}

		$rows = [];

		foreach ($choices as $choice) {
			$slug = (string) $choice['slug'];

			// An assignment names a slug, so a row with none yet cannot be assigned.
			if ($slug === '') {
				continue;
			}

			$rows[] = [
				'slug' => $slug,
				'name' => (string) $choice['name'],
				'managed' => (bool) $choice['managed'],
				'pack' => in_array($slug, $packs, true),
				'assigned' => in_array($slug, $assigned, true),
				'via' => $via[$slug] ?? [],
			];
		}

		return $rows;
	}

	/**
	 * The packs: every service with at least one under it.
	 *
	 * @return array<int, string> Their slugs, each once.
	 */
	public function packs()
	{
		return array_values(array_unique(array_column($this->links(), 0)));
	}

	/**
	 * The services some users are assigned themselves, for the navigator.
	 *
	 * @param array<int, string> $extensions The users' extensions.
	 *
	 * @return array<int, string> Their slugs, each once.
	 */
	public function assignedTo(array $extensions)
	{
		if (!$extensions) {
			return [];
		}

		$params = [];
		$stmt = $this->db->prepare(
			"SELECT DISTINCT service FROM `{$this->serviceAssignmentsTable}` WHERE "
			. $this->inClause('extension', $extensions, 'extension', $params)
		);
		$stmt->execute($params);

		return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * The users assigned a service themselves, for the navigator.
	 *
	 * @param mixed $slug The service's slug.
	 *
	 * @return array<int, string> Their extensions.
	 */
	public function usersOf($slug)
	{
		$stmt = $this->db->prepare(
			"SELECT extension FROM `{$this->serviceAssignmentsTable}` WHERE service = :service"
		);
		$stmt->execute([':service' => (string) $slug]);

		return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * Give a user a service, or take it away.
	 *
	 * **The state is sent, not toggled**: the same request twice leaves the
	 * user where the first put it. Nothing about the user itself is written,
	 * so this raises no Apply Config.
	 *
	 * @param array<string, mixed> $request extension, service (a slug), assigned.
	 *
	 * @return array<string, mixed> Status and userServices() as it now is, or a message when refused.
	 */
	public function setUserService($request)
	{
		$extension = trim((string) ($request['extension'] ?? ''));
		$service = $this->serviceBySlug(trim((string) ($request['service'] ?? '')));

		if (!ctype_digit($extension) || !$this->userExists($extension)) {
			return ['status' => false, 'message' => _('That user no longer exists.')];
		}

		if (!$service) {
			return ['status' => false, 'message' => _('That service no longer exists.')];
		}

		$said = strtolower(trim((string) ($request['assigned'] ?? '1')));
		$on = !in_array($said, ['0', '', 'false', 'off', 'no'], true);
		$bind = [':extension' => $extension, ':service' => $service['slug']];

		$stmt = $this->db->prepare(
			"DELETE FROM `{$this->serviceAssignmentsTable}` WHERE extension = :extension AND service = :service"
		);
		$stmt->execute($bind);

		if ($on) {
			$stmt = $this->db->prepare(
				"INSERT INTO `{$this->serviceAssignmentsTable}` (extension, service) VALUES (:extension, :service)"
			);
			$stmt->execute($bind);
		}

		return ['status' => true, 'assigned' => $on, 'services' => $this->userServices($extension)];
	}

	/**
	 * Take every service off a user: it has been deleted.
	 *
	 * @param mixed $extension The user's extension.
	 *
	 * @return void
	 */
	public function forgetUser($extension)
	{
		try {
			$stmt = $this->db->prepare(
				"DELETE FROM `{$this->serviceAssignmentsTable}` WHERE extension = :extension"
			);
			$stmt->execute([':extension' => (string) $extension]);
		} catch (\Exception $e) {
			// No table before the upgrade that adds it: nothing to delete.
		}
	}

	/**
	 * Carry a user's services to its new number: it has been renumbered.
	 *
	 * @param mixed $old The extension it had.
	 * @param mixed $new The extension it has.
	 *
	 * @return void
	 */
	public function moveUser($old, $new)
	{
		try {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->serviceAssignmentsTable}` SET extension = :new WHERE extension = :old"
			);
			$stmt->execute([':new' => (string) $new, ':old' => (string) $old]);
		} catch (\Exception $e) {
			$this->logError('could not move the services of ' . $old . ' to ' . $new . ': ' . $e->getMessage());
		}
	}

	/**
	 * Write DEFAULTS to the tables. Run by install(), and safe to run again.
	 *
	 * A row older than the slug column is given a slug from its name first,
	 * so a service already called what a default is called becomes that
	 * default rather than colliding with it. Then each default is made or
	 * renamed, and the links between two defaults are made to match DEFAULTS:
	 * one it no longer has is removed, one it has is added. A link with a
	 * service of the operator's own at either end is theirs and is left.
	 *
	 * @return array<int, string> What could not be done, for the install's output.
	 */
	public function seed()
	{
		$notes = [];

		$bare = $this->db->prepare("SELECT id, name FROM `{$this->servicesTable}` WHERE slug IS NULL OR slug = '' ORDER BY id");
		$bare->execute();
		$fill = $this->db->prepare("UPDATE `{$this->servicesTable}` SET slug = :slug WHERE id = :id");

		foreach ($bare->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$fill->execute([':slug' => $this->freeSlug(self::slugify($row['name']), (int) $row['id'], false), ':id' => (int) $row['id']]);
		}

		$find = $this->db->prepare("SELECT id, name FROM `{$this->servicesTable}` WHERE slug = :slug");
		$written = [];

		foreach (self::DEFAULTS as $slug => $default) {
			$find->execute([':slug' => $slug]);
			$row = $find->fetch(PDO::FETCH_ASSOC);

			try {
				if (!$row) {
					$add = $this->db->prepare("INSERT INTO `{$this->servicesTable}` (name, slug) VALUES (:name, :slug)");
					$add->execute([':name' => $default['name'], ':slug' => $slug]);
					$written[] = $slug;

					continue;
				}

				if ((string) $row['name'] !== $default['name']) {
					$rename = $this->db->prepare("UPDATE `{$this->servicesTable}` SET name = :name WHERE id = :id");
					$rename->execute([':name' => $default['name'], ':id' => (int) $row['id']]);
				}

				$written[] = $slug;
			} catch (\Exception $e) {
				// The name is unique, and another service has it under its own slug.
				$notes[] = sprintf('the service "%s" (%s) was not written: another service already has that name', $default['name'], $slug);
			}
		}

		$links = $this->links();
		$drop = $this->db->prepare("DELETE FROM `{$this->serviceLinksTable}` WHERE parent = :parent AND child = :child");
		$add = $this->db->prepare("INSERT INTO `{$this->serviceLinksTable}` (parent, child) VALUES (:parent, :child)");

		foreach ($links as $at => $link) {
			if (self::managed($link[0]) && self::managed($link[1]) && !in_array($link[1], self::DEFAULTS[$link[0]]['services'] ?? [], true)) {
				$drop->execute([':parent' => $link[0], ':child' => $link[1]]);
				unset($links[$at]);
			}
		}

		// Only between defaults that were written: a link never names a slug with no row.
		foreach ($written as $slug) {
			foreach (array_intersect(self::DEFAULTS[$slug]['services'] ?? [], $written) as $child) {
				if (in_array([$slug, $child], $links, true)) {
					continue;
				}

				// The operator's own links can run from the child back up to the parent.
				if (in_array($slug, self::descendants($links, $child), true)) {
					$notes[] = sprintf('%s was not put under %s: services of your own already put %s under %s', $child, $slug, $slug, $child);

					continue;
				}

				$add->execute([':parent' => $slug, ':child' => $child]);
				$links[] = [$slug, $child];
			}
		}

		return $notes;
	}

	/**
	 * Whether a slug is one of the module's.
	 *
	 * @param mixed $slug Slug, or null.
	 *
	 * @return bool True when DEFAULTS has it.
	 */
	public static function managed($slug)
	{
		return isset(self::DEFAULTS[(string) $slug]);
	}

	/**
	 * A slug made from a name: lowercased, everything else a hyphen.
	 *
	 * @param string $name What the service is called.
	 *
	 * @return string A slug that matches SLUG_PATTERN; `service` for a name with nothing usable in it.
	 */
	public static function slugify($name)
	{
		$slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $name)), '-');
		$slug = rtrim(substr($slug, 0, self::SLUG_MAX), '-');

		return $slug !== '' ? $slug : 'service';
	}

	/**
	 * Every service under one, at any depth.
	 *
	 * @param array<int, array{0: string, 1: string}> $links Every link: [parent, child], slugs.
	 * @param string								  $slug  The service asked about.
	 *
	 * @return array<int, string> Their slugs, never $slug itself.
	 */
	public static function descendants(array $links, $slug)
	{
		return self::reach($links, [(string) $slug], 0, 1, (string) $slug);
	}

	/**
	 * Every service one is under, at any depth.
	 *
	 * @param array<int, array{0: string, 1: string}> $links Every link: [parent, child], slugs.
	 * @param string								  $slug  The service asked about.
	 *
	 * @return array<int, string> Their slugs, never $slug itself.
	 */
	public static function ancestors(array $links, $slug)
	{
		return self::reach($links, [(string) $slug], 1, 0, (string) $slug);
	}

	/**
	 * Whether giving a service these parents and children would close a loop.
	 *
	 * It would when a service is on both sides, or when one of the parents is
	 * already somewhere under one of the children.
	 *
	 * @param array<int, array{0: string, 1: string}> $links	Every link: [parent, child], slugs.
	 * @param string								  $slug	 The service, or '' for a new one.
	 * @param array<int, string>					  $parents  What it would be under.
	 * @param array<int, string>					  $children What would be under it.
	 *
	 * @return bool True when it would.
	 */
	public static function loops(array $links, $slug, array $parents, array $children)
	{
		$slug = (string) $slug;

		if (in_array($slug, $parents, true) || in_array($slug, $children, true)) {
			return true;
		}

		// Its own links are the ones being replaced, so they are not walked.
		$others = array_values(array_filter($links, function ($link) use ($slug) {
			return $slug === '' || ($link[0] !== $slug && $link[1] !== $slug);
		}));

		$under = array_merge($children, self::reach($others, $children, 0, 1, ''));

		return (bool) array_intersect($parents, $under);
	}

	/**
	 * Slugs out of a request: an array, or one comma-separated string.
	 *
	 * @param mixed $value What was submitted.
	 *
	 * @return array<int, string> Each thing in it that is a slug, once.
	 */
	public static function slugs($value)
	{
		$parts = is_array($value) ? $value : explode(',', (string) $value);
		$slugs = [];

		foreach ($parts as $part) {
			$slug = is_scalar($part) ? strtolower(trim((string) $part)) : '';

			if (strlen($slug) <= self::SLUG_MAX && preg_match(self::SLUG_PATTERN, $slug) && !in_array($slug, $slugs, true)) {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}

	/**
	 * Everything reachable from some services, walking links one way.
	 *
	 * @param array<int, array{0: string, 1: string}> $links Every link: [parent, child], slugs.
	 * @param array<int, string>					  $from  Where to start.
	 * @param int									 $near  Index of the end walked from: 0 going down, 1 going up.
	 * @param int									 $far   Index of the end walked to.
	 * @param string								  $skip  A slug never reported.
	 *
	 * @return array<int, string> Slugs reached. A loop already in the data is walked once.
	 */
	private static function reach(array $links, array $from, $near, $far, $skip)
	{
		$next = [];

		foreach ($links as $link) {
			$next[(string) $link[$near]][] = (string) $link[$far];
		}

		$found = [];
		$queue = array_map('strval', $from);

		while ($queue) {
			foreach ($next[array_shift($queue)] ?? [] as $other) {
				if ($other !== $skip && !isset($found[$other])) {
					$found[$other] = true;
					$queue[] = $other;
				}
			}
		}

		// A slug of digits is an integer as an array key; hand every one back a string.
		return array_map('strval', array_keys($found));
	}

	/**
	 * The services assigned to a user itself.
	 *
	 * @param mixed $extension The user's extension.
	 *
	 * @return array<int, string> Their slugs.
	 */
	private function assigned($extension)
	{
		$stmt = $this->db->prepare(
			"SELECT service FROM `{$this->serviceAssignmentsTable}` WHERE extension = :extension"
		);
		$stmt->execute([':extension' => (string) $extension]);

		return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * Whether an extension is a FreePBX user: what the module's users are.
	 *
	 * @param string $extension Digits.
	 *
	 * @return bool True when Core's users table has it.
	 */
	private function userExists($extension)
	{
		try {
			$stmt = $this->db->prepare("SELECT 1 FROM `users` WHERE extension = :extension");
			$stmt->execute([':extension' => $extension]);

			return (bool) $stmt->fetchColumn();
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * A row, with `managed` added: whether its slug is one of the module's.
	 *
	 * @param array<string, mixed> $row A services row carrying `slug`.
	 *
	 * @return array<string, mixed> The same row, widened.
	 */
	private function marked(array $row)
	{
		return $row + ['managed' => self::managed($row['slug'] ?? '')];
	}

	/**
	 * Whether another service already has a slug.
	 *
	 * @param string $slug Slug asked about.
	 * @param int    $id   The service it is for, which may keep its own.
	 *
	 * @return bool True when it is taken.
	 */
	private function slugTaken($slug, $id)
	{
		$stmt = $this->db->prepare(
			"SELECT id FROM `{$this->servicesTable}` WHERE slug = :slug AND id != :id"
		);
		$stmt->execute([':slug' => $slug, ':id' => (int) $id]);

		return (bool) $stmt->fetchColumn();
	}

	/**
	 * A slug nobody else has: the one given, or it with `-2`, `-3`, ... after it.
	 * Never `new`, which is how a page and the navigator name one not yet written.
	 *
	 * @param string $base     Slug wanted, from slugify().
	 * @param int    $id       The service it is for, or 0 for a new one.
	 * @param bool   $reserved Whether the module's slugs are off limits; only seed() says no.
	 *
	 * @return string A free slug.
	 */
	private function freeSlug($base, $id, $reserved = true)
	{
		for ($n = 1; ; $n++) {
			$tail = $n === 1 ? '' : '-' . $n;
			$slug = substr($base, 0, self::SLUG_MAX - strlen($tail)) . $tail;

			if ($slug !== 'new' && !($reserved && self::managed($slug)) && !$this->slugTaken($slug, $id)) {
				return $slug;
			}
		}
	}

	/**
	 * Why a set of links cannot be written, or null when it can.
	 *
	 * @param string							$was   The service's slug as stored, or '' for a new one.
	 * @param string							$slug  The slug it is being saved with.
	 * @param array<string, array<int, string>> $sides parents and/or children, as submitted.
	 *
	 * @return string|null The refusal.
	 */
	private function refusedLinks($was, $slug, array $sides)
	{
		$names = array_column($this->serviceChoices(), 'name', 'slug');

		foreach ($sides as $others) {
			foreach ($others as $other) {
				if (!isset($names[$other])) {
					return _('One of the services chosen no longer exists.');
				}
			}
		}

		// A side not submitted stays as it is, and still counts.
		$kept = $this->related($was);
		$sides += ['parents' => $kept['parents'], 'children' => $kept['children']];

		return (in_array($slug, array_merge($sides['parents'], $sides['children']), true)
			|| self::loops($this->links(), $was, $sides['parents'], $sides['children']))
			? _('A service cannot be under itself, or under a service that is under it.')
			: null;
	}

	/**
	 * Replace one side of a service's links.
	 *
	 * @param string			 $slug   The service.
	 * @param string			 $side   parents|children.
	 * @param array<int, string> $others The whole of that side, as slugs.
	 *
	 * @return void
	 */
	private function relink($slug, $side, array $others)
	{
		$mine = $side === 'parents' ? 'child' : 'parent';
		$theirs = $side === 'parents' ? 'parent' : 'child';

		$clear = $this->db->prepare("DELETE FROM `{$this->serviceLinksTable}` WHERE `$mine` = :slug");
		$clear->execute([':slug' => $slug]);

		$add = $this->db->prepare(
			"INSERT INTO `{$this->serviceLinksTable}` (`$mine`, `$theirs`) VALUES (:mine, :theirs)"
		);

		foreach ($others as $other) {
			$add->execute([':mine' => $slug, ':theirs' => $other]);
		}
	}

	/**
	 * Carry a changed slug to everything that names the old one.
	 *
	 * **Anything that comes to store a service's slug is added here**: this is
	 * the one place a rename is followed. Today: both ends of a link, and a
	 * user's assignments.
	 *
	 * @param string $was  The slug the references hold.
	 * @param string $slug The slug they are to hold.
	 *
	 * @return void
	 */
	private function renameLinks($was, $slug)
	{
		foreach (['parent', 'child'] as $column) {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->serviceLinksTable}` SET `$column` = :slug WHERE `$column` = :was"
			);
			$stmt->execute([':slug' => $slug, ':was' => $was]);
		}

		$stmt = $this->db->prepare(
			"UPDATE `{$this->serviceAssignmentsTable}` SET service = :slug WHERE service = :was"
		);
		$stmt->execute([':slug' => $slug, ':was' => $was]);
	}

	/**
	 * Every link. The whole table: a loop can run through any row of it.
	 *
	 * @return array<int, array{0: string, 1: string}> [parent, child] each, slugs.
	 */
	private function links()
	{
		$stmt = $this->db->prepare(
			"SELECT parent, child FROM `{$this->serviceLinksTable}`"
		);
		$stmt->execute();

		return array_map(function ($row) {
			return [(string) $row[0], (string) $row[1]];
		}, $stmt->fetchAll(PDO::FETCH_NUM));
	}
}
