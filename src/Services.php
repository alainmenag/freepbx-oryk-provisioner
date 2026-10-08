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
 * Every change here that changes what a user holds -- an assignment, a
 * deleted service, a pack's links, a regrouping of the defaults -- writes that
 * user a job in the same transaction, and a page's change starts the worker;
 * see ARCHITECTURE.md, "Jobs".
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
		'lobby-user' => ['name' => 'Lobby User', 'services' => ['support']],
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

	/** Seconds a change to services waits for another to finish being written. */
	const CHANGE_WAIT = 10;

	/** @var Jobs|null Null where no job is written: the tests. */
	private $jobs;

	/**
	 * @param object    $freepbx FreePBX application instance.
	 * @param Jobs|null $jobs    Where a change's jobs are written.
	 */
	public function __construct($freepbx, ?Jobs $jobs = null)
	{
		parent::__construct($freepbx);

		$this->jobs = $jobs;
	}

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
	 * The service with a slug: how a page's address, a save and a delete name one.
	 *
	 * @param mixed $slug Slug, as the request carries it.
	 *
	 * @return array<string, mixed>|null name, slug, managed; null when there is none.
	 */
	public function serviceBySlug($slug)
	{
		$slug = (string) $slug;

		if ($slug === '') {
			return null;
		}

		$stmt = $this->db->prepare(
			"SELECT name, slug FROM `{$this->servicesTable}` WHERE slug = :slug"
		);
		$stmt->execute([':slug' => $slug]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ? $this->marked($row) : null;
	}

	/**
	 * Every service, for the editor's two lists.
	 *
	 * @return array<int, array<string, mixed>> name, slug, managed, ordered by name.
	 */
	public function serviceChoices()
	{
		$stmt = $this->db->prepare(
			"SELECT name, slug FROM `{$this->servicesTable}` ORDER BY name"
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
	 * The request's `slug` says which service is being saved, '' for a new
	 * one, and is never what it is saved with: that is made from the name, on
	 * a new service and again whenever the name changes. A slug that changes
	 * is changed on every link naming it, in the same transaction, so nothing
	 * that names the service by it is left pointing at nothing.
	 *
	 * Links changed are a pack edit: every user holding something different
	 * afterwards gets a job, in the transaction, and the worker is started.
	 *
	 * @param array<string, mixed> $request slug, name, parents, children.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function saveService($request)
	{
		$was = trim((string) ($request['slug'] ?? ''));
		$name = trim((string) ($request['name'] ?? ''));

		if ($name === '') {
			return ['status' => false, 'message' => _('A service needs a name.')];
		}

		if (strlen($name) > self::NAME_MAX) {
			return ['status' => false, 'message' => sprintf(_('A service name is at most %s characters.'), self::NAME_MAX)];
		}

		$row = $was !== '' ? $this->serviceBySlug($was) : null;

		if ($was !== '' && !$row) {
			return ['status' => false, 'message' => _('That service no longer exists.')];
		}

		if ($row && $row['managed']) {
			return ['status' => false, 'message' => _('This service is managed by the module and cannot be changed.')];
		}

		$taken = $this->db->prepare(
			"SELECT 1 FROM `{$this->servicesTable}` WHERE name = :name AND slug != :was"
		);
		$taken->execute([':name' => $name, ':was' => $was]);

		if ($taken->fetchColumn()) {
			return ['status' => false, 'message' => _('A service with that name already exists.')];
		}

		$slug = $was;

		// Only a changed name moves it: a save of anything else leaves the
		// address and the links where they are.
		if (!$row || (string) $row['name'] !== $name) {
			$slug = $this->freeSlug(self::slugify($name), $was);
		}

		$sides = [];

		foreach (['parents', 'children'] as $side) {
			if (array_key_exists($side, $request)) {
				$sides[$side] = self::slugs($request[$side]);
			}
		}

		if ($sides) {
			$refused = $this->refusedLinks($was, $slug, $sides);

			if ($refused !== null) {
				return ['status' => false, 'message' => $refused];
			}
		}

		if (!$this->lockChanges()) {
			return ['status' => false, 'message' => _('Another change to services is being saved; try again.')];
		}

		try {
			$this->db->beginTransaction();
			$queued = [];

			try {
				// Only links change what anyone holds; a rename alone changes no one's.
				$watch = $sides && $this->jobs;

				if ($watch) {
					$linksBefore = $this->links();
					$before = $this->assignments();
					$names = $this->names();
				}

				if ($row) {
					$stmt = $this->db->prepare(
						"UPDATE `{$this->servicesTable}` SET name = :name, slug = :slug WHERE slug = :was"
					);
					$stmt->execute([':name' => $name, ':slug' => $slug, ':was' => $was]);

					if ($was !== $slug) {
						$this->renameLinks($was, $slug);
					}
				} else {
					$stmt = $this->db->prepare(
						"INSERT INTO `{$this->servicesTable}` (name, slug) VALUES (:name, :slug)"
					);
					$stmt->execute([':name' => $name, ':slug' => $slug]);
				}

				foreach ($sides as $side => $others) {
					$this->relink($slug, $side, $others);
				}

				if ($watch) {
					// Before, as if it had always had the new slug: the rename is not a change.
					if ($was !== '' && $was !== $slug) {
						list($linksBefore, $before) = self::renamed($linksBefore, $before, $was, $slug);
					}

					$queued = $this->queue($linksBefore, $before, $this->links(), $this->assignments(), $slug, $name, 'pack-changed', 'gui', $this->names() + $names);
				}

				$this->db->commit();
			} catch (\Exception $e) {
				$this->db->rollBack();
				$this->logError('saving a service failed: ' . $e->getMessage());

				return ['status' => false, 'message' => _('The service could not be saved.')];
			}
		} finally {
			$this->unlockChanges();
		}

		if ($queued) {
			$this->jobs->startFor($queued);
		}

		return ['status' => true, 'name' => $name, 'slug' => $slug];
	}

	/**
	 * Remove a service, and every link to or from it.
	 *
	 * The services it was over or under are kept: only the links go, and it
	 * is taken off every user it was assigned to. One of the module's is
	 * refused. Every user holding something different afterwards -- it, or
	 * what they held only through it -- gets a job, in the same transaction.
	 *
	 * @param mixed $slug The service's slug.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function deleteService($slug)
	{
		$slug = trim((string) $slug);

		if (self::managed($slug)) {
			return ['status' => false, 'message' => _('This service is managed by the module and cannot be deleted.')];
		}

		$row = $slug === '' ? null : $this->serviceBySlug($slug);

		if (!$row) {
			return ['status' => true];
		}

		if (!$this->lockChanges()) {
			return ['status' => false, 'message' => _('Another change to services is being saved; try again.')];
		}

		try {
			$this->db->beginTransaction();
			$queued = [];

			try {
				$linksBefore = $this->links();
				$before = $this->jobs ? $this->assignments() : [];
				$names = $this->names();

				$links = $this->db->prepare(
					"DELETE FROM `{$this->serviceLinksTable}` WHERE parent = :parent OR child = :child"
				);
				$links->execute([':parent' => $slug, ':child' => $slug]);

				$assigned = $this->db->prepare(
					"DELETE FROM `{$this->serviceAssignmentsTable}` WHERE service = :slug"
				);
				$assigned->execute([':slug' => $slug]);

				$stmt = $this->db->prepare("DELETE FROM `{$this->servicesTable}` WHERE slug = :slug");
				$stmt->execute([':slug' => $slug]);

				if ($this->jobs) {
					$queued = $this->queue($linksBefore, $before, $this->links(), $this->assignments(), $slug, (string) $row['name'], 'service-deleted', 'gui', $names);
				}

				$this->db->commit();
			} catch (\Exception $e) {
				$this->db->rollBack();
				$this->logError('deleting a service failed: ' . $e->getMessage());

				return ['status' => false, 'message' => _('The service could not be deleted.')];
			}
		} finally {
			$this->unlockChanges();
		}

		if ($queued) {
			$this->jobs->startFor($queued);
		}

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
	 * Every service a user has: for `{{extension.services}}`.
	 *
	 * @param mixed $extension The user's extension.
	 *
	 * @return array<int, string> Their slugs, each once, in order.
	 */
	public function userSlugs($extension)
	{
		$assigned = $this->assigned($extension);

		return $assigned ? self::held($this->links(), $assigned) : [];
	}

	/**
	 * Whether a user holds a service, assigned it or through a pack.
	 *
	 * @param mixed $extension The user's extension.
	 * @param mixed $slug      The service's slug.
	 *
	 * @return bool True when it does.
	 */
	public function hasService($extension, $slug)
	{
		return in_array((string) $slug, $this->userSlugs($extension), true);
	}

	/**
	 * What saving or deleting a service would change for its users, without
	 * doing it: what the service page asks with before it does.
	 *
	 * A save is worked out from the links it would write -- `parents` and
	 * `children` as saveService() takes them -- and a delete from the
	 * service gone. Whether the save would be refused is saveService()'s to say.
	 *
	 * @param array<string, mixed> $request action (save|delete), slug ('' for a
	 *                                      new one), name, parents, children.
	 *
	 * @return array<string, mixed> status, users (how many would get a job),
	 *                              granted and revoked: [name, users] each, by name.
	 */
	public function serviceImpact($request)
	{
		$was = trim((string) ($request['slug'] ?? ''));
		$links = $this->links();
		$before = $this->assignments();
		$names = $this->names();
		$linksAfter = $links;
		$after = $before;

		if ((string) ($request['action'] ?? '') === 'delete') {
			$linksAfter = array_values(array_filter($links, function ($link) use ($was) {
				return $link[0] !== $was && $link[1] !== $was;
			}));

			foreach ($after as $extension => $slugs) {
				$after[$extension] = array_values(array_diff($slugs, [$was]));
			}
		} else {
			// 'new' is never a slug (freeSlug() refuses it), so it stands in for one not yet made.
			$key = $was !== '' ? $was : 'new';
			$name = trim((string) ($request['name'] ?? ''));
			$names[$key] = $name !== '' ? $name : ($names[$key] ?? $key);

			foreach (['parents' => 1, 'children' => 0] as $side => $mine) {
				if (!array_key_exists($side, $request)) {
					continue;
				}

				$linksAfter = array_values(array_filter($linksAfter, function ($link) use ($key, $mine) {
					return $link[$mine] !== $key;
				}));

				foreach (self::slugs($request[$side]) as $other) {
					$linksAfter[] = $mine ? [$other, $key] : [$key, $other];
				}
			}
		}

		$users = 0;
		$counts = ['granted' => [], 'revoked' => []];

		foreach (array_keys($before + $after) as $extension) {
			$steps = ServiceEngine::changes($links, $before[$extension] ?? [], $linksAfter, $after[$extension] ?? []);

			if ($steps) {
				$users++;
			}

			foreach ($steps as $step) {
				$counts[$step['event']][$step['service']] = ($counts[$step['event']][$step['service']] ?? 0) + 1;
			}
		}

		$result = ['status' => true, 'users' => $users];

		foreach ($counts as $event => $bySlug) {
			$rows = [];

			foreach ($bySlug as $slug => $count) {
				$rows[] = ['name' => (string) ($names[(string) $slug] ?? $slug), 'users' => $count];
			}

			usort($rows, function ($a, $b) {
				return strcasecmp($a['name'], $b['name']);
			});

			$result[$event] = $rows;
		}

		return $result;
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
	 * so this raises no Apply Config. A change to what the user holds is a
	 * job, written with it and started at once; the same state again is none.
	 *
	 * @param array<string, mixed> $request extension, service (a slug), assigned.
	 *
	 * @return array<string, mixed> Status, userServices() as it now is and
	 *                              `jobs` (Jobs::statusFor()), or a message when refused.
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
		$queued = [];

		if (!$this->lockChanges()) {
			return ['status' => false, 'message' => _('Another change to services is being saved; try again.')];
		}

		try {
			$this->db->beginTransaction();

			try {
				$links = $this->links();
				$before = [$extension => $this->assigned($extension)];

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

				$after = [$extension => $this->assigned($extension)];
				$queued = $this->queue($links, $before, $links, $after, $service['slug'], $service['name'], $on ? 'assigned' : 'unassigned', 'gui', $this->names());

				$this->db->commit();
			} catch (\Exception $e) {
				$this->db->rollBack();
				$this->logError('assigning a service failed: ' . $e->getMessage());

				return ['status' => false, 'message' => _('The service could not be saved.')];
			}
		} finally {
			$this->unlockChanges();
		}

		if ($queued) {
			$this->jobs->startFor($queued);
		}

		return [
			'status' => true,
			'assigned' => $on,
			'services' => $this->userServices($extension),
			'jobs' => $this->jobs ? $this->jobs->statusFor($extension) : [],
		];
	}

	/**
	 * Take every service off a user, and delete its jobs: it has been deleted.
	 *
	 * Nothing is revoked: there is no user left to react on.
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

		if ($this->jobs) {
			$this->jobs->forgetUser($extension);
		}
	}

	/**
	 * Carry a user's services and jobs to its new number: it has been
	 * renumbered. Nothing it holds changes, so no job is made.
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

		if ($this->jobs) {
			$this->jobs->moveUser($old, $new);
		}
	}

	/**
	 * Write DEFAULTS to the tables. Run by install(), and safe to run again.
	 *
	 * A row older than the slug column is given a slug from its name first
	 * -- found by its name, the one thing such a row is sure to have -- so a
	 * service already called what a default is called becomes that default
	 * rather than colliding with it. Then each default is made or
	 * renamed, and the links between two defaults are made to match DEFAULTS:
	 * one it no longer has is removed, one it has is added. A link with a
	 * service of the operator's own at either end is theirs and is left.
	 *
	 * All one transaction, with the jobs a regrouping that changes what users
	 * hold writes them -- which it does not start: an install may run as
	 * root, and the minute job runs them.
	 *
	 * @return array<int, string> What could not be done, and how many users
	 *                            were given jobs, for the install's output.
	 */
	public function seed()
	{
		$notes = [];
		$queued = [];

		if (!$this->lockChanges()) {
			return ['the module\'s services were not brought up to date: another change to services held them'];
		}

		try {
			$this->db->beginTransaction();

			try {
				$linksBefore = $this->links();
				$before = $this->jobs ? $this->assignments() : [];

				$this->regroup($notes);

				if ($this->jobs) {
					$queued = $this->queue($linksBefore, $before, $this->links(), $this->assignments(), '', '', 'pack-changed', 'upgrade', $this->names());
				}

				$this->db->commit();
			} catch (\Exception $e) {
				$this->db->rollBack();
				$notes[] = 'the module\'s services could not be brought up to date: ' . $e->getMessage();

				return $notes;
			}
		} finally {
			$this->unlockChanges();
		}

		if ($queued) {
			$notes[] = sprintf('the services of %d users changed with the module\'s defaults; their jobs are queued for the minute job', count($queued));
		}

		return $notes;
	}

	/**
	 * seed()'s writes: slugs for rows without one, the defaults made or
	 * renamed, and the links between two defaults made to match DEFAULTS.
	 *
	 * @param array<int, string> $notes What could not be done, added to.
	 *
	 * @return void
	 */
	private function regroup(array &$notes)
	{
		$bare = $this->db->prepare("SELECT name FROM `{$this->servicesTable}` WHERE slug IS NULL OR slug = '' ORDER BY name");
		$bare->execute();
		$fill = $this->db->prepare("UPDATE `{$this->servicesTable}` SET slug = :slug WHERE name = :name");

		foreach ($bare->fetchAll(PDO::FETCH_COLUMN) as $name) {
			$fill->execute([':slug' => $this->freeSlug(self::slugify($name), '', false), ':name' => $name]);
		}

		$find = $this->db->prepare("SELECT name FROM `{$this->servicesTable}` WHERE slug = :slug");
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
					$rename = $this->db->prepare("UPDATE `{$this->servicesTable}` SET name = :name WHERE slug = :slug");
					$rename->execute([':name' => $default['name'], ':slug' => $slug]);
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
	 * What some assigned services amount to: themselves, and everything under them.
	 *
	 * @param array<int, array{0: string, 1: string}> $links    Every link: [parent, child], slugs.
	 * @param array<int, string>                      $assigned What is assigned, by slug.
	 *
	 * @return array<int, string> Slugs, each once, in order.
	 */
	public static function held(array $links, array $assigned)
	{
		$held = [];

		foreach ($assigned as $slug) {
			$held[] = (string) $slug;

			foreach (self::descendants($links, $slug) as $under) {
				$held[] = (string) $under;
			}
		}

		$held = array_values(array_unique($held));
		sort($held, SORT_STRING);

		return $held;
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
	 * Hold the one lock every change to services is worked out and written
	 * under: two at once would each read what the other was about to change,
	 * and a revoke both owe would be made by neither. A MySQL named lock,
	 * freed by the server if the process dies.
	 *
	 * @return bool False when another change held it for CHANGE_WAIT seconds.
	 */
	private function lockChanges()
	{
		try {
			$stmt = $this->db->prepare('SELECT GET_LOCK(:name, ' . (int) self::CHANGE_WAIT . ')');
			$stmt->execute([':name' => 'oryk_provisioner_services']);

			return (string) $stmt->fetchColumn() !== '0';
		} catch (\Exception $e) {
			// No named locks to be had: the change goes ahead as it always did.
			return true;
		}
	}

	/**
	 * Give lockChanges()'s lock back.
	 *
	 * @return void
	 */
	private function unlockChanges()
	{
		try {
			$stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
			$stmt->execute([':name' => 'oryk_provisioner_services']);
		} catch (\Exception $e) {
			// The server frees it with the connection.
		}
	}

	/**
	 * Every user's assignments, for working out what a change does to them.
	 *
	 * @return array<string, array<int, string>> Slugs assigned, by extension.
	 */
	private function assignments()
	{
		$stmt = $this->db->prepare(
			"SELECT extension, service FROM `{$this->serviceAssignmentsTable}` ORDER BY extension, service"
		);
		$stmt->execute();

		$assigned = [];

		foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
			$assigned[(string) $row[0]][] = (string) $row[1];
		}

		return $assigned;
	}

	/**
	 * Every service's name, for the names a job's steps keep.
	 *
	 * @return array<string, string> Names, by slug.
	 */
	private function names()
	{
		$names = [];

		foreach ($this->serviceChoices() as $row) {
			$names[(string) $row['slug']] = (string) $row['name'];
		}

		return $names;
	}

	/**
	 * Write a job for every user a change leaves holding something different.
	 * Inside the caller's transaction.
	 *
	 * @param array<int, array{0: string, 1: string}> $linksBefore Every link before the change.
	 * @param array<string, array<int, string>>       $before      Assignments before it, by extension.
	 * @param array<int, array{0: string, 1: string}> $linksAfter  Every link after it.
	 * @param array<string, array<int, string>>       $after       Assignments after it, by extension.
	 * @param string                                  $service     The service the change was made to, '' for an upgrade.
	 * @param string                                  $name        Its name.
	 * @param string                                  $reason      Jobs::REASONS.
	 * @param string                                  $source      Jobs::SOURCES.
	 * @param array<string, string>                   $names       Every service's name, by slug, before and after.
	 *
	 * @return array<int, string> The extensions given a job.
	 */
	private function queue(array $linksBefore, array $before, array $linksAfter, array $after, $service, $name, $reason, $source, array $names)
	{
		if (!$this->jobs) {
			return [];
		}

		$queued = [];

		foreach (array_keys($before + $after) as $extension) {
			$extension = (string) $extension;
			$steps = ServiceEngine::changes($linksBefore, $before[$extension] ?? [], $linksAfter, $after[$extension] ?? []);

			if (!$steps) {
				continue;
			}

			foreach ($steps as &$step) {
				$step['name'] = $names[$step['service']] ?? $step['service'];
			}
			unset($step);

			$this->jobs->enqueue($extension, $service, $name, $reason, $source, $steps);
			$queued[] = $extension;
		}

		return $queued;
	}

	/**
	 * Links and assignments with one slug read as another.
	 *
	 * @param array<int, array{0: string, 1: string}> $links    Every link.
	 * @param array<string, array<int, string>>       $assigned Assignments, by extension.
	 * @param string                                  $was      The slug they hold.
	 * @param string                                  $slug     The slug to read it as.
	 *
	 * @return array{0: array<int, array{0: string, 1: string}>, 1: array<string, array<int, string>>} Both.
	 */
	private static function renamed(array $links, array $assigned, $was, $slug)
	{
		$swap = function ($one) use ($was, $slug) {
			return (string) $one === (string) $was ? (string) $slug : (string) $one;
		};

		foreach ($links as $at => $link) {
			$links[$at] = [$swap($link[0]), $swap($link[1])];
		}

		foreach ($assigned as $extension => $slugs) {
			$assigned[$extension] = array_map($swap, $slugs);
		}

		return [$links, $assigned];
	}

	/**
	 * Whether an extension is a FreePBX user: what the module's users are.
	 *
	 * @param string $extension Digits.
	 *
	 * @return bool True when Core's users table has it.
	 */
	public function userExists($extension)
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
	 * @param string $own  The slug of the service it is for, which may keep it; '' for a new one.
	 *
	 * @return bool True when it is taken.
	 */
	private function slugTaken($slug, $own)
	{
		if ($slug === (string) $own) {
			return false;
		}

		$stmt = $this->db->prepare(
			"SELECT 1 FROM `{$this->servicesTable}` WHERE slug = :slug"
		);
		$stmt->execute([':slug' => $slug]);

		return (bool) $stmt->fetchColumn();
	}

	/**
	 * A slug nobody else has: the one given, or it with `-2`, `-3`, ... after it.
	 * Never `new`, which is how a page and the navigator name one not yet written.
	 *
	 * @param string $base     Slug wanted, from slugify().
	 * @param string $own      The slug of the service it is for, or '' for a new one.
	 * @param bool   $reserved Whether the module's slugs are off limits; only seed() says no.
	 *
	 * @return string A free slug.
	 */
	private function freeSlug($base, $own, $reserved = true)
	{
		for ($n = 1; ; $n++) {
			$tail = $n === 1 ? '' : '-' . $n;
			$slug = substr($base, 0, self::SLUG_MAX - strlen($tail)) . $tail;

			if ($slug !== 'new' && !($reserved && self::managed($slug)) && !$this->slugTaken($slug, $own)) {
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
	 * the one place a rename is followed. Today: both ends of a link, a
	 * user's assignments, and the jobs and steps naming it.
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

		if ($this->jobs) {
			$this->jobs->renameService($was, $slug);
		}
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
