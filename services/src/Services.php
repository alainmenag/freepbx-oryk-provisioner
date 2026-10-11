<?php

// services/src/Services.php

namespace FreePBX\Modules\Oryk_Services;

use PDO;

/**
 * The services table, and the links between its rows.
 *
 * A service is a name and a slug. Which services sit under which is the links
 * table: one row per parent and child, each named by its slug, so a service
 * has any number of parents and
 * any number of children. One with a child is a service pack: that is read
 * off the links, never stored. A row of `kind` REQUIRES in the same table is
 * a requirement instead: the parent cannot work without the child, and
 * holding it holds that too, the way a pack holds what is under it, but it
 * is no pack for that.
 *
 * **An edge is [from, to], a pack link, or [from, to, 'requires']**: links()
 * answers both kinds, and every walk of what a user holds -- held(),
 * descendants(), ServiceEngine::changes() -- takes them alike. Where the
 * question is the pack tree (packs(), related(), the form) only the links
 * count: packLinks(), or `empty($edge[2])`.
 *
 * A user is assigned services in a third table, by extension and slug. A
 * row there is a service **given** to the user (`status` NULL) or one
 * **withheld** from it (STATUS_WITHHELD): held through a given pack, but
 * taken away from this one user. Everywhere in this class a user's
 * assignments are that table's rows for it, as slug => status, and
 * held() is what they amount to; see userServices() and setUserServices().
 *
 * Every change here that changes what a user holds -- an assignment, a
 * deleted service, a pack's links, a regrouping of the defaults -- writes that
 * user a job in the same transaction, and a page's change starts the worker;
 * see ARCHITECTURE.md, "Jobs".
 *
 * Every service has a slug, made from its name and never typed, and the ones whose slug is in DEFAULTS are the
 * module's: written by seed() on install, and refused every edit and delete.
 */
class Services extends Component
{
	/**
	 * The services the module ships, by slug: `name`, under `services` the
	 * slugs of the services directly under it, and under `requires` the
	 * slugs it brings with it.
	 *
	 * **Adding, renaming or regrouping one is an edit here and nothing else**;
	 * seed() carries it to the tables on the next install or upgrade. A slug
	 * is the identity, so a changed name renames the row and a changed slug is
	 * a new service. A slug taken out is deleted, with its links and
	 * assignments, on the next install.
	 */
	const DEFAULTS = [
		'advanced-user' => ['name' => 'Advanced User', 'services' => ['basic-user', 'call-recording', 'on-demand-recording']],
		'basic-user' => ['name' => 'Basic User', 'services' => ['lobby-user', 'find-me-follow', 'voicemail']],
		'lobby-user' => ['name' => 'Lobby User', 'services' => ['guest-user', 'support']],
		'guest-user' => ['name' => 'Guest User', 'services' => ['knowledge-base']],
		'call-recording' => ['name' => 'Call Recording', 'services' => ['call-recording-external', 'call-recording-internal']],
		'call-recording-external' => ['name' => 'Call Recording External'],
		'call-recording-internal' => ['name' => 'Call Recording Internal'],
		'calendar' => ['name' => 'Calendar', 'requires' => ['find-me-follow']],
		'find-me-follow' => ['name' => 'Find Me Follow'],
		'on-demand-recording' => ['name' => 'On Demand Recording'],
		'support' => ['name' => 'Support'],
		'knowledge-base' => ['name' => 'Knowledge Base'],
		'voicemail' => ['name' => 'Voicemail'],
	];

	/**
	 * Default slugs a release changed: old => new. seed() carries every link,
	 * assignment and job from the old to the new before it deletes the
	 * module's rows, so whoever held the one holds the other and nothing is
	 * revoked. **A slug changed in DEFAULTS without an entry here is a
	 * default dropped and another added**: its users lose it. An entry stays
	 * while any PBX may still upgrade across it.
	 */
	const RENAMED = [];

	/** `status` of an assignment withheld; NULL is one given. Nothing else is written. */
	const STATUS_WITHHELD = 0;

	/** `owner` of a service the module ships: seed() deletes and rewrites every one. */
	const OWNER_MODULE = 0;

	/** `owner` of a service made on the Services page. */
	const OWNER_OPERATOR = 1;

	/** The `kind` of a links row, and of an edge, that is a requirement; '' is a pack link. */
	const REQUIRES = 'requires';

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
	 * module's; `holders` is how many users have it, assigned it themselves or
	 * through a pack. That is worked out here and not by the query, so a list
	 * sorted by it is read whole, sorted, and then cut to the page.
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
		$byHolders = (string) ($_REQUEST['sort'] ?? '') === 'holders';
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

		if ($source === 'module' || $source === 'custom') {
			$clauses[] = 's.owner ' . ($source === 'custom' ? '!=' : '=') . ' ' . self::OWNER_MODULE;
		}

		$kind = (string) ($_REQUEST['kind'] ?? '');

		if ($kind === 'pack' || $kind === 'single') {
			$clauses[] = ($kind === 'single' ? 'NOT ' : '')
				. "EXISTS (SELECT 1 FROM `{$this->serviceLinksTable}` k WHERE k.parent = s.slug AND k.kind = '')";
		}

		$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

		$countStmt = $this->db->prepare("SELECT COUNT(*) FROM `{$this->servicesTable}` s $where");
		$countStmt->execute($params);
		$total = (int) $countStmt->fetchColumn();

		$sql = "
			SELECT
				s.name,
				s.slug,
				s.owner
			FROM `{$this->servicesTable}` s
			$where
			ORDER BY s.name " . ($byHolders ? 'ASC' : $order . ' LIMIT :limit OFFSET :offset') . "
		";

		$stmt = $this->db->prepare($sql);
		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
		}

		if (!$byHolders) {
			$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
			$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		}

		$stmt->execute();

		$holders = [];
		$links = $this->links();

		foreach ($this->assignments() as $assigned) {
			foreach (self::held($links, $assigned) as $slug) {
				$holders[$slug] = ($holders[$slug] ?? 0) + 1;
			}
		}

		$rows = [];
		$packs = array_column(self::packsOnly($links), 0);

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$rows[] = $this->marked($row) + [
				'holders' => $holders[(string) $row['slug']] ?? 0,
				'pack' => in_array((string) $row['slug'], $packs, true),
			];
		}

		// By name within a count, as the query left them: usort() is stable.
		if ($byHolders) {
			usort($rows, function ($a, $b) use ($order) {
				return $order === 'DESC' ? $b['holders'] <=> $a['holders'] : $a['holders'] <=> $b['holders'];
			});

			$rows = array_slice($rows, max(0, $offset), max(0, $limit));
		}

		return [
			'total' => $total,
			'rows' => $rows,
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
			"SELECT s.slug, s.owner,
				EXISTS (SELECT 1 FROM `{$this->serviceLinksTable}` k WHERE k.parent = s.slug AND k.kind = '') AS pack
			FROM `{$this->servicesTable}` s"
		);
		$stmt->execute();

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			if ($scope !== null && !in_array((string) $row['slug'], array_map('strval', $scope), true)) {
				continue;
			}

			$counts[(int) $row['owner'] === self::OWNER_MODULE ? 'module' : 'custom'][(int) $row['pack'] ? 'pack' : 'single']++;
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
			"SELECT name, slug, owner FROM `{$this->servicesTable}` WHERE slug = :slug"
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
			"SELECT name, slug, owner, updated_at AS touched FROM `{$this->servicesTable}` ORDER BY name"
		);
		$stmt->execute();

		return array_map([$this, 'marked'], $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	/**
	 * What one service is linked to, and what it may not be linked to.
	 *
	 * A service already under this one cannot also be its parent, and one it
	 * is already under cannot also be its child: either closes a loop. So
	 * does requiring a service that, at any remove, requires this one.
	 *
	 * @param string $slug The service's slug, or '' for a new one.
	 *
	 * @return array<string, array<int, string>> parents, children: slugs linked
	 *										   directly; requires: the slugs
	 *										   it requires; barredParents,
	 *										   barredChildren, barredRequires: slugs not to
	 *										   offer. The module's own are not
	 *										   among them: serviceChoices()
	 *										   marks those.
	 */
	public function related($slug)
	{
		$slug = (string) $slug;
		$links = $slug !== '' ? $this->packLinks() : [];
		$requires = $slug !== '' ? $this->requires() : [];
		$found = ['parents' => [], 'children' => [], 'requires' => []];

		foreach ($links as $link) {
			if ($link[1] === $slug) {
				$found['parents'][] = $link[0];
			}

			if ($link[0] === $slug) {
				$found['children'][] = $link[1];
			}
		}

		foreach ($requires as $edge) {
			if ($edge[0] === $slug) {
				$found['requires'][] = $edge[1];
			}
		}

		return $found + [
			'barredParents' => self::descendants($links, $slug),
			'barredChildren' => self::ancestors($links, $slug),
			'barredRequires' => self::ancestors($requires, $slug),
		];
	}

	/**
	 * Create or update a service, and its links when they were submitted.
	 *
	 * `parents`, `children` and `requires` are each the whole set -- slugs,
	 * comma-separated or an array -- and replace what was there; a key not
	 * submitted leaves that side as it is. All of it is one transaction.
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
	 * Links or requirements changed are a pack edit: every user holding
	 * something different afterwards gets a job, in the transaction, and the
	 * worker is started.
	 *
	 * @param array<string, mixed> $request slug, name, parents, children, requires.
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

		foreach (['parents', 'children', 'requires'] as $side) {
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
				// Only links and requirements change what anyone holds; a rename alone changes no one's.
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
						"INSERT INTO `{$this->servicesTable}` (name, slug, owner) VALUES (:name, :slug, " . self::OWNER_OPERATOR . ")"
					);
					$stmt->execute([':name' => $name, ':slug' => $slug]);
				}

				foreach ($sides as $side => $others) {
					$this->relink($slug, $side, $others);
				}

				if ($sides) {
					$this->pruneWithheld($this->links(), $this->assignments());
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
	 * Remove a service, and every link and requirement to or from it.
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

		$row = $slug === '' ? null : $this->serviceBySlug($slug);

		if (!$row) {
			return ['status' => true];
		}

		if ($row['managed']) {
			return ['status' => false, 'message' => _('This service is managed by the module and cannot be deleted.')];
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

				$this->pruneWithheld($this->links(), $this->assignments());

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
	 * through those whether or not it is assigned as well. `withheld` is a
	 * withheld row: it reaches the user through `via` and is taken away.
	 *
	 * @param mixed $extension The user's extension.
	 *
	 * @return array<int, array<string, mixed>> slug, name, managed, pack,
	 *                                          assigned, withheld, via,
	 *                                          children (the slugs directly
	 *                                          under it); ordered by name.
	 */
	public function userServices($extension)
	{
		$choices = $this->serviceChoices();
		$names = array_column($choices, 'name', 'slug');
		$links = $this->links();
		$rows = $this->assigned($extension);
		$assigned = self::given($rows);
		$withheld = self::withheld($rows);
		$packs = array_column(self::packsOnly($links), 0);
		$via = [];
		$children = [];
		$requires = [];

		foreach ($links as $link) {
			if (empty($link[2])) {
				$children[(string) $link[0]][] = (string) $link[1];
			} else {
				$requires[(string) $link[0]][] = (string) $link[1];
			}
		}

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
				'withheld' => in_array($slug, $withheld, true),
				'via' => $via[$slug] ?? [],
				'children' => $children[$slug] ?? [],
				'requires' => $requires[$slug] ?? [],
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
		$rows = $this->assigned($extension);

		return $rows ? self::held($this->links(), $rows) : [];
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
	 * A save is worked out from the edges it would write -- `parents`,
	 * `children` and `requires` as saveService() takes them -- and a delete
	 * from the service gone. Whether the save would be refused is
	 * saveService()'s to say.
	 *
	 * @param array<string, mixed> $request action (save|delete), slug ('' for a
	 *                                      new one), name, parents, children, requires.
	 *
	 * @return array<string, mixed> status, users (how many would get a job),
	 *                              granted and revoked: [name, users, effect]
	 *                              each, by name; effect is Reactions::effect().
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

			foreach ($after as $extension => $rows) {
				unset($after[$extension][$was]);
			}
		} else {
			// 'new' is never a slug (freeSlug() refuses it), so it stands in for one not yet made.
			$key = $was !== '' ? $was : 'new';
			$name = trim((string) ($request['name'] ?? ''));
			$names[$key] = $name !== '' ? $name : ($names[$key] ?? $key);

			foreach (['parents' => 1, 'children' => 0, 'requires' => 0] as $side => $mine) {
				if (!array_key_exists($side, $request)) {
					continue;
				}

				$kind = $side === 'requires' ? self::REQUIRES : '';

				$linksAfter = array_values(array_filter($linksAfter, function ($link) use ($key, $mine, $kind) {
					return $link[$mine] !== $key || (string) ($link[2] ?? '') !== $kind;
				}));

				foreach (self::slugs($request[$side]) as $other) {
					$linksAfter[] = $kind !== '' ? [$key, $other, $kind] : ($mine ? [$other, $key] : [$key, $other]);
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
				$rows[] = ['name' => (string) ($names[(string) $slug] ?? $slug), 'users' => $count, 'effect' => Reactions::effect($slug, $event)];
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
		return array_values(array_unique(array_column($this->packLinks(), 0)));
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
			"SELECT DISTINCT service FROM `{$this->serviceAssignmentsTable}` WHERE status IS NULL AND "
			. $this->inClause('extension', $extensions, 'extension', $params)
		);
		$stmt->execute($params);

		return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * The users who have a service either way: assigned it themselves, or
	 * through a pack it is under.
	 *
	 * @param mixed $slug The service's slug.
	 *
	 * @return array<int, string> Their extensions.
	 */
	public function holdersOf($slug)
	{
		$links = $this->links();
		$found = [];

		foreach ($this->assignments() as $extension => $assigned) {
			if (in_array((string) $slug, self::held($links, $assigned), true)) {
				$found[] = (string) $extension;
			}
		}

		return $found;
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
			"SELECT extension FROM `{$this->serviceAssignmentsTable}` WHERE service = :service AND status IS NULL"
		);
		$stmt->execute([':service' => (string) $slug]);

		return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * A user's assignments once some services are assigned, unassigned,
	 * withheld or restored, and which of those change anything.
	 *
	 * Assigning replaces a withheld row; unassigning and restoring each
	 * delete one. Withholding is only of a service the user would then hold
	 * through a given pack and does not hold itself: anything else named is
	 * reported under `refused` and left alone, and one that something the
	 * user would hold requires under `required`. Nothing here checks that
	 * the slugs exist.
	 *
	 * @param array<int, array{0: string, 1: string, 2?: string}> $links    Every edge, as held() takes them.
	 * @param array<string, int|null>                              $before   The user's rows, slug => status.
	 * @param array<int, string>                                   $assign   Slugs to assign.
	 * @param array<int, string>                                   $unassign Slugs to unassign.
	 * @param array<int, string>                                   $withhold Slugs to withhold.
	 * @param array<int, string>                                   $restore  Slugs to restore.
	 *
	 * @return array<string, mixed> after (slug => status); assign, unassign,
	 *                              withhold, restore (what changes); refused
	 *                              (withholds that could not be); required
	 *                              (withholds of a service something requires).
	 */
	public static function assignedAfter(array $links, array $before, array $assign, array $unassign, array $withhold = [], array $restore = [])
	{
		$after = [];

		foreach ($before as $slug => $status) {
			$after[(string) $slug] = $status === null ? null : (int) $status;
		}

		$clean = function (array $slugs) {
			return array_values(array_unique(array_map('strval', $slugs)));
		};

		$added = [];
		$removed = [];
		$restored = [];
		$withheld = [];
		$refused = [];

		foreach ($clean($unassign) as $slug) {
			if (array_key_exists($slug, $after) && $after[$slug] === null) {
				unset($after[$slug]);
				$removed[] = $slug;
			}
		}

		foreach ($clean($restore) as $slug) {
			if (array_key_exists($slug, $after) && $after[$slug] === self::STATUS_WITHHELD) {
				unset($after[$slug]);
				$restored[] = $slug;
			}
		}

		foreach ($clean($assign) as $slug) {
			if (!array_key_exists($slug, $after) || $after[$slug] !== null) {
				$after[$slug] = null;
				$added[] = $slug;
			}
		}

		$through = self::held($links, array_fill_keys(self::given($after), null));
		$needed = self::required($links, $through);
		$required = [];

		foreach ($clean($withhold) as $slug) {
			if (array_key_exists($slug, $after)) {
				if ($after[$slug] === null) {
					$refused[] = $slug;
				}

				continue;
			}

			if (!in_array($slug, $through, true)) {
				$refused[] = $slug;
				continue;
			}

			if (in_array($slug, $needed, true)) {
				$required[] = $slug;
				continue;
			}

			$after[$slug] = self::STATUS_WITHHELD;
			$withheld[] = $slug;
		}

		return [
			'after' => $after,
			'assign' => $added,
			'unassign' => $removed,
			'withhold' => $withheld,
			'restore' => $restored,
			'refused' => $refused,
			'required' => $required,
		];
	}

	/**
	 * A request to change a user's services, read and checked against what
	 * the user is assigned now. Writes nothing.
	 *
	 * @param array<string, mixed> $request extension, assign, unassign,
	 *                                      withhold and restore (slugs; see slugs()).
	 *
	 * @return array<string, mixed> status and a message when refused; else
	 *                              extension, links, names, before, after,
	 *                              assign, unassign, withhold and restore
	 *                              (assignedAfter()).
	 */
	private function userChange($request)
	{
		$extension = trim((string) ($request['extension'] ?? ''));

		if (!ctype_digit($extension) || !$this->userExists($extension)) {
			return ['status' => false, 'message' => _('That user no longer exists.')];
		}

		$lists = [];

		foreach (['assign', 'unassign', 'withhold', 'restore'] as $key) {
			$lists[$key] = self::slugs($request[$key] ?? []);
		}

		if (count(array_unique(array_merge(...array_values($lists)))) !== array_sum(array_map('count', $lists))) {
			return ['status' => false, 'message' => _('A service cannot be changed two ways at once.')];
		}

		$names = $this->names();

		// A service deleted since the page was drawn took its assignment with
		// it, so only one to assign or withhold can be missing.
		if (array_diff(array_merge($lists['assign'], $lists['withhold']), array_map('strval', array_keys($names)))) {
			return ['status' => false, 'message' => _('A service no longer exists; reload the page.')];
		}

		$links = $this->links();
		$before = $this->assigned($extension);
		$change = self::assignedAfter($links, $before, $lists['assign'], $lists['unassign'], $lists['withhold'], $lists['restore']);

		if ($change['refused']) {
			return ['status' => false, 'message' => _('A service can only be withheld while it is included in a pack the user is assigned.')];
		}

		if ($change['required']) {
			return ['status' => false, 'message' => _('A service cannot be withheld while another the user holds requires it.')];
		}

		return [
			'status' => true,
			'extension' => $extension,
			'links' => $links,
			'names' => $names,
			'before' => $before,
			'after' => $change['after'],
			'assign' => $change['assign'],
			'unassign' => $change['unassign'],
			'withhold' => $change['withhold'],
			'restore' => $change['restore'],
		];
	}

	/**
	 * What setUserServices() would do with the same request, without doing
	 * it: what the user's Services tab asks with before it saves.
	 *
	 * @param array<string, mixed> $request As setUserServices() takes it.
	 *
	 * @return array<string, mixed> status; assign, unassign, withhold,
	 *                              restore, granted and revoked, each [name,
	 *                              pack] by name, the last two with `effect`
	 *                              (Reactions::effect(), what the module's
	 *                              own job does); kept -- unassigned but
	 *                              still held -- with `via`, the assigned
	 *                              services it stays through; job, whether
	 *                              one would be made. Or a message when the
	 *                              save would be refused.
	 */
	public function userServicesImpact($request)
	{
		$change = $this->userChange($request);

		if (!$change['status']) {
			return $change;
		}

		$links = $change['links'];
		$names = $change['names'];
		$packs = array_column(self::packsOnly($links), 0);

		$named = function (array $slugs, $event = '') use ($names, $packs) {
			$rows = [];

			foreach ($slugs as $slug) {
				$rows[] = ['name' => (string) ($names[(string) $slug] ?? $slug), 'pack' => in_array((string) $slug, $packs, true)]
					+ ($event !== '' ? ['effect' => Reactions::effect($slug, $event)] : []);
			}

			usort($rows, function ($a, $b) {
				return strcasecmp($a['name'], $b['name']);
			});

			return $rows;
		};

		$events = ['granted' => [], 'revoked' => []];

		foreach (ServiceEngine::changes($links, $change['before'], $links, $change['after']) as $step) {
			$events[$step['event']][] = $step['service'];
		}

		$kept = [];

		foreach (array_intersect($change['unassign'], self::held($links, $change['after'])) as $slug) {
			$via = [];

			foreach (self::given($change['after']) as $over) {
				if (in_array($slug, self::descendants($links, $over), true)) {
					$via[] = (string) ($names[$over] ?? $over);
				}
			}

			sort($via, SORT_FLAG_CASE | SORT_STRING);
			$kept[] = $named([$slug])[0] + ['via' => $via];
		}

		usort($kept, function ($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});

		return [
			'status' => true,
			'assign' => $named($change['assign']),
			'unassign' => $named($change['unassign']),
			'withhold' => $named($change['withhold']),
			'restore' => $named($change['restore']),
			'granted' => $named($events['granted'], 'granted'),
			'revoked' => $named($events['revoked'], 'revoked'),
			'kept' => $kept,
			'job' => (bool) ($events['granted'] || $events['revoked']),
		];
	}

	/**
	 * Change which services a user is assigned, and which are withheld from
	 * it: several at once, as one change.
	 *
	 * **The state of each service named is sent, not toggled, and one not
	 * named is not touched**: the same request twice leaves the user where
	 * the first put it, and a page drawn before someone else's change cannot
	 * undo that change. Everything named is one write and one net change to
	 * what the user holds, so at most one job -- a service given up with one
	 * pack and gained with another is never revoked on the way. A withheld
	 * row the change leaves outside every given pack is deleted with it, so
	 * it cannot come back with the pack months later. Nothing about the user
	 * itself is written, so this raises no Apply Config.
	 *
	 * @param array<string, mixed> $request extension, assign, unassign,
	 *                                      withhold and restore (slugs; see slugs()).
	 *
	 * @return array<string, mixed> Status, userServices() as it now is and
	 *                              `jobs` (Jobs::statusFor()), or a message when refused.
	 */
	public function setUserServices($request)
	{
		$queued = [];

		if (!$this->lockChanges()) {
			return ['status' => false, 'message' => _('Another change to services is being saved; try again.')];
		}

		try {
			$change = $this->userChange($request);

			if (!$change['status']) {
				return $change;
			}

			$extension = $change['extension'];
			$changed = array_merge($change['assign'], $change['unassign'], $change['withhold'], $change['restore']);

			// One service is a job named for it; several are one job named for none.
			$one = count($changed) === 1 ? (string) $changed[0] : '';
			$reason = $one === '' ? 'changed' : (($change['assign'] || $change['restore']) ? 'assigned' : 'unassigned');

			$this->db->beginTransaction();

			try {
				$delete = $this->db->prepare(
					"DELETE FROM `{$this->serviceAssignmentsTable}` WHERE extension = :extension AND service = :service"
				);
				$insert = $this->db->prepare(
					"INSERT INTO `{$this->serviceAssignmentsTable}` (extension, service, status) VALUES (:extension, :service, :status)"
				);

				foreach (array_merge($change['unassign'], $change['restore'], $change['assign']) as $slug) {
					$delete->execute([':extension' => $extension, ':service' => $slug]);
				}

				foreach ($change['assign'] as $slug) {
					$insert->execute([':extension' => $extension, ':service' => $slug, ':status' => null]);
				}

				foreach ($change['withhold'] as $slug) {
					$insert->execute([':extension' => $extension, ':service' => $slug, ':status' => self::STATUS_WITHHELD]);
				}

				$this->pruneWithheld($change['links'], [$extension => $change['after']]);

				if ($changed) {
					$queued = $this->queue(
						$change['links'],
						[$extension => $change['before']],
						$change['links'],
						[$extension => $change['after']],
						$one,
						$one === '' ? '' : (string) ($change['names'][$one] ?? $one),
						$reason,
						'gui',
						$change['names']
					);
				}

				$this->db->commit();
			} catch (\Exception $e) {
				$this->db->rollBack();
				$this->logError('assigning services failed: ' . $e->getMessage());

				return ['status' => false, 'message' => _('The services could not be saved.')];
			}
		} finally {
			$this->unlockChanges();
		}

		if ($queued) {
			$this->jobs->startFor($queued);
		}

		return [
			'status' => true,
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
	 * rather than colliding with it. Then **every row the module owns is
	 * deleted and DEFAULTS written again**; an operator's row holding a
	 * default's slug becomes the module's. Assignments and links name a slug,
	 * so they stay with a default written again, and go with one DEFAULTS no
	 * longer has -- which install says, by name. A slug in RENAMED is carried
	 * to its new one first, and read as if it had always been that, so it
	 * makes no job. The links between two defaults are made to match DEFAULTS:
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
				$namesBefore = $this->jobs ? $this->names() : [];

				foreach ($this->regroup($notes) as $was => $slug) {
					list($linksBefore, $before) = self::renamed($linksBefore, $before, $was, $slug);
				}

				$this->pruneWithheld($this->links(), $this->assignments());

				if ($this->jobs) {
					$queued = $this->queue($linksBefore, $before, $this->links(), $this->assignments(), '', '', 'pack-changed', 'upgrade', $this->names() + $namesBefore);
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
	 * seed()'s writes: slugs for rows without one, RENAMED carried, the
	 * module's rows deleted and DEFAULTS written again, what named a service
	 * now gone deleted, and the defaults' links and requirements made to match DEFAULTS.
	 *
	 * @param array<int, string> $notes What could not be done and what was removed, added to.
	 *
	 * @return array<string, string> The RENAMED entries carried: old => new.
	 */
	private function regroup(array &$notes)
	{
		$bare = $this->db->prepare("SELECT name FROM `{$this->servicesTable}` WHERE slug IS NULL OR slug = '' ORDER BY name");
		$bare->execute();
		$fill = $this->db->prepare("UPDATE `{$this->servicesTable}` SET slug = :slug WHERE name = :name");

		foreach ($bare->fetchAll(PDO::FETCH_COLUMN) as $name) {
			$fill->execute([':slug' => $this->freeSlug(self::slugify($name), '', false), ':name' => $name]);
		}

		$mine = $this->db->prepare("SELECT slug, name FROM `{$this->servicesTable}` WHERE owner = " . self::OWNER_MODULE);
		$mine->execute();
		$mine = array_column($mine->fetchAll(PDO::FETCH_ASSOC), 'name', 'slug');
		$carried = [];

		// Only from a row the module owns to a default with no row yet: the
		// references are moved, and two rows' would collide.
		foreach (static::RENAMED as $was => $slug) {
			$was = (string) $was;

			if (!isset($mine[$was]) || isset(self::DEFAULTS[$was]) || !isset(self::DEFAULTS[$slug])) {
				continue;
			}

			if ($this->slugTaken($slug, '')) {
				$notes[] = sprintf('%s was not carried to %s: a service already has that slug', $was, $slug);

				continue;
			}

			$this->renameLinks($was, $slug);
			$carried[$was] = (string) $slug;
		}

		$this->db->exec("DELETE FROM `{$this->servicesTable}` WHERE owner = " . self::OWNER_MODULE);

		// Only an operator's row can be found now: one holding a default's slug.
		$find = $this->db->prepare("SELECT name FROM `{$this->servicesTable}` WHERE slug = :slug");
		$written = [];

		foreach (self::DEFAULTS as $slug => $default) {
			$find->execute([':slug' => $slug]);
			$row = $find->fetch(PDO::FETCH_ASSOC);

			try {
				if (!$row) {
					$add = $this->db->prepare("INSERT INTO `{$this->servicesTable}` (name, slug, owner) VALUES (:name, :slug, " . self::OWNER_MODULE . ")");
					$add->execute([':name' => $default['name'], ':slug' => $slug]);
					$written[] = $slug;

					continue;
				}

				$adopt = $this->db->prepare("UPDATE `{$this->servicesTable}` SET name = :name, owner = " . self::OWNER_MODULE . " WHERE slug = :slug");
				$adopt->execute([':name' => $default['name'], ':slug' => $slug]);

				$written[] = $slug;
			} catch (\Exception $e) {
				// The name is unique, and another service has it under its own slug.
				$notes[] = sprintf('the service "%s" (%s) was not written: another service already has that name', $default['name'], $slug);
			}
		}

		// A default DEFAULTS no longer has, or one not written, is no service:
		// nothing may go on naming it. Said, with what it takes from users.
		$held = $this->db->prepare("SELECT COUNT(*) FROM `{$this->serviceAssignmentsTable}` WHERE service = :service");

		foreach ($mine as $slug => $name) {
			if (in_array((string) $slug, $written, true) || isset($carried[(string) $slug])) {
				continue;
			}

			$held->execute([':service' => (string) $slug]);
			$notes[] = sprintf('the service "%s" (%s) is no longer one of the module\'s and was removed, with its links and %d assignments', $name, $slug, (int) $held->fetchColumn());
		}

		$this->db->exec(
			"DELETE k FROM `{$this->serviceLinksTable}` k
			WHERE NOT EXISTS (SELECT 1 FROM `{$this->servicesTable}` s WHERE s.slug = k.parent)
				OR NOT EXISTS (SELECT 1 FROM `{$this->servicesTable}` s WHERE s.slug = k.child)"
		);
		$this->db->exec(
			"DELETE a FROM `{$this->serviceAssignmentsTable}` a
			WHERE NOT EXISTS (SELECT 1 FROM `{$this->servicesTable}` s WHERE s.slug = a.service)"
		);

		// A default requires exactly what DEFAULTS says, of the defaults written.
		// Before the links below, so a pair that turns from one kind to the other is free.
		$clear = $this->db->prepare("DELETE FROM `{$this->serviceLinksTable}` WHERE parent = :parent AND kind = :kind");
		$need = $this->db->prepare("INSERT INTO `{$this->serviceLinksTable}` (parent, child, kind) VALUES (:parent, :child, :kind)");

		foreach ($written as $slug) {
			$clear->execute([':parent' => $slug, ':kind' => self::REQUIRES]);

			foreach (array_intersect(self::DEFAULTS[$slug]['requires'] ?? [], $written) as $required) {
				$need->execute([':parent' => $slug, ':child' => $required, ':kind' => self::REQUIRES]);
			}
		}

		$links = $this->packLinks();
		$drop = $this->db->prepare("DELETE FROM `{$this->serviceLinksTable}` WHERE parent = :parent AND child = :child AND kind = ''");
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

		return $carried;
	}

	/**
	 * Whether a slug is one the module ships: seed() writes it, and no
	 * service of an operator's is given it. Whether a *row* is the module's
	 * is its `owner`.
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
	 * What a user's assignments amount to: every given service, everything
	 * under it and everything those require, less every withheld service and
	 * everything under it -- except what is given itself, which a withhold
	 * never takes. A withhold walks the links only: what a withheld service
	 * requires stays if anything else held needs it, and a service that is
	 * required is never withheld (assignedAfter()).
	 *
	 * @param array<int, array{0: string, 1: string, 2?: string}> $links Every edge: links, and requirements marked REQUIRES.
	 * @param array<string, int|null>                              $rows  The user's assignments, slug => status.
	 *
	 * @return array<int, string> Slugs, each once, in order.
	 */
	public static function held(array $links, array $rows)
	{
		$given = self::given($rows);
		$withheld = self::withheld($rows);
		$taken = array_merge($withheld, self::reach(self::packsOnly($links), $withheld, 0, 1, ''));

		$held = array_values(array_unique(array_merge($given, array_diff(self::reach($links, $given, 0, 1, ''), $taken))));
		$needed = array_diff(self::reach(self::requiresOnly($links), $held, 0, 1, ''), $held);

		if ($needed) {
			$held = array_values(array_unique(array_merge($held, $needed, self::reach($links, $needed, 0, 1, ''))));
		}

		sort($held, SORT_STRING);

		return $held;
	}

	/**
	 * The services some held ones require, at any depth: never withheld.
	 *
	 * @param array<int, array{0: string, 1: string, 2?: string}> $links Every edge, as held() takes them.
	 * @param array<int, string>                                   $held  What is held.
	 *
	 * @return array<int, string> Slugs, each once.
	 */
	public static function required(array $links, array $held)
	{
		return self::reach(self::requiresOnly($links), $held, 0, 1, '');
	}

	/**
	 * The services given to a user: the rows assigning it something.
	 *
	 * @param array<string, int|null> $rows Assignments, slug => status.
	 *
	 * @return array<int, string> Slugs.
	 */
	public static function given(array $rows)
	{
		return array_map('strval', array_keys(array_filter($rows, function ($status) {
			return $status === null;
		})));
	}

	/**
	 * The services withheld from a user.
	 *
	 * @param array<string, int|null> $rows Assignments, slug => status.
	 *
	 * @return array<int, string> Slugs.
	 */
	public static function withheld(array $rows)
	{
		return array_map('strval', array_keys(array_filter($rows, function ($status) {
			return $status === self::STATUS_WITHHELD;
		})));
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
	 * A user's assignment rows: given and withheld.
	 *
	 * @param mixed $extension The user's extension.
	 *
	 * @return array<string, int|null> slug => status.
	 */
	private function assigned($extension)
	{
		$stmt = $this->db->prepare(
			"SELECT service, status FROM `{$this->serviceAssignmentsTable}` WHERE extension = :extension"
		);
		$stmt->execute([':extension' => (string) $extension]);

		return self::rowsToMap($stmt->fetchAll(PDO::FETCH_NUM));
	}

	/**
	 * Assignment rows as the class carries them.
	 *
	 * @param array<int, array{0: string, 1: int|string|null}> $rows [service, status] each.
	 *
	 * @return array<string, int|null> slug => status.
	 */
	private static function rowsToMap(array $rows)
	{
		$map = [];

		foreach ($rows as $row) {
			$map[(string) $row[0]] = $row[1] === null ? null : (int) $row[1];
		}

		return $map;
	}

	/**
	 * Delete every withheld row that no given pack reaches any more: an
	 * unassigned pack, a pack edit or a deleted service left it meaning
	 * nothing, and it must not mean something again later.
	 *
	 * @param array<int, array{0: string, 1: string}> $links Every link, as they are now.
	 * @param array<string, array<string, int|null>>  $rows  Assignments to check, by extension.
	 *
	 * @return void
	 */
	private function pruneWithheld(array $links, array $rows)
	{
		$delete = $this->db->prepare(
			"DELETE FROM `{$this->serviceAssignmentsTable}` WHERE extension = :extension AND service = :service AND status = " . self::STATUS_WITHHELD
		);

		foreach ($rows as $extension => $map) {
			$through = self::held($links, array_fill_keys(self::given($map), null));

			foreach (array_diff(self::withheld($map), $through) as $slug) {
				$delete->execute([':extension' => (string) $extension, ':service' => $slug]);
			}
		}
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
	 * @return array<string, array<string, int|null>> slug => status, by extension.
	 */
	private function assignments()
	{
		$stmt = $this->db->prepare(
			"SELECT extension, service, status FROM `{$this->serviceAssignmentsTable}` ORDER BY extension, service"
		);
		$stmt->execute();

		$assigned = [];

		foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
			$assigned[(string) $row[0]][(string) $row[1]] = $row[2] === null ? null : (int) $row[2];
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
	 * @param array<int, array{0: string, 1: string, 2?: string}> $linksBefore Every edge before the change, as Services::held() takes them.
	 * @param array<string, array<int, string>>       $before      Assignments before it, by extension.
	 * @param array<int, array{0: string, 1: string, 2?: string}> $linksAfter  Every edge after it.
	 * @param array<string, array<int, string>>       $after       Assignments after it, by extension.
	 * @param string                                  $service     The service the change was made to, '' for an upgrade or several at once.
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
	 * @param array<string, array<string, int|null>>  $assigned Assignments, by extension.
	 * @param string                                  $was      The slug they hold.
	 * @param string                                  $slug     The slug to read it as.
	 *
	 * @return array{0: array<int, array{0: string, 1: string}>, 1: array<string, array<string, int|null>>} Both.
	 */
	private static function renamed(array $links, array $assigned, $was, $slug)
	{
		$swap = function ($one) use ($was, $slug) {
			return (string) $one === (string) $was ? (string) $slug : (string) $one;
		};

		foreach ($links as $at => $link) {
			$links[$at] = [$swap($link[0]), $swap($link[1])];
		}

		foreach ($assigned as $extension => $rows) {
			$renamed = [];

			foreach ($rows as $one => $status) {
				$renamed[$swap($one)] = $status;
			}

			$assigned[$extension] = $renamed;
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
	 * A row, with `managed` added: whether the module owns it.
	 *
	 * @param array<string, mixed> $row A services row carrying `owner`.
	 *
	 * @return array<string, mixed> The same row, widened.
	 */
	private function marked(array $row)
	{
		return $row + ['managed' => (int) ($row['owner'] ?? self::OWNER_OPERATOR) === self::OWNER_MODULE];
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
		$sides += ['parents' => $kept['parents'], 'children' => $kept['children'], 'requires' => $kept['requires']];

		if (in_array($slug, array_merge($sides['parents'], $sides['children']), true)
			|| self::loops($this->packLinks(), $was, $sides['parents'], $sides['children'])) {
			return _('A service cannot be under itself, or under a service that is under it.');
		}

		// A pair is one row of the links table, so it is a link or a requirement, never both.
		$ownPairs = array_intersect($sides['children'], $sides['requires']);
		$theirPairs = array_filter($this->requires(), function ($edge) use ($was, $sides) {
			return $was !== '' && $edge[1] === $was && in_array($edge[0], $sides['parents'], true);
		});

		if ($ownPairs || $theirPairs) {
			return _('A service cannot both include and require the same service.');
		}

		// Its own requirements are the ones being replaced, so they are not walked.
		$others = array_values(array_filter($this->requires(), function ($edge) use ($was) {
			return $was === '' || $edge[0] !== $was;
		}));

		return (in_array($slug, $sides['requires'], true)
			|| in_array($was, self::reach($others, $sides['requires'], 0, 1, ''), true))
			? _('A service cannot require itself, or a service that requires it.')
			: null;
	}

	/**
	 * Replace one side of a service's links, or what it requires.
	 *
	 * @param string			 $slug   The service.
	 * @param string			 $side   parents|children|requires.
	 * @param array<int, string> $others The whole of that side, as slugs.
	 *
	 * @return void
	 */
	private function relink($slug, $side, array $others)
	{
		// Columns are interpolated: chosen here, never from $side as sent.
		$mine = $side === 'parents' ? 'child' : 'parent';
		$theirs = $side === 'parents' ? 'parent' : 'child';
		$kind = $side === 'requires' ? self::REQUIRES : '';

		$clear = $this->db->prepare("DELETE FROM `{$this->serviceLinksTable}` WHERE `$mine` = :slug AND kind = :kind");
		$clear->execute([':slug' => $slug, ':kind' => $kind]);

		$add = $this->db->prepare(
			"INSERT INTO `{$this->serviceLinksTable}` (`$mine`, `$theirs`, kind) VALUES (:mine, :theirs, :kind)"
		);

		foreach ($others as $other) {
			$add->execute([':mine' => $slug, ':theirs' => $other, ':kind' => $kind]);
		}
	}

	/**
	 * Carry a changed slug to everything that names the old one.
	 *
	 * **Anything that comes to store a service's slug is added here**: this is
	 * the one place a rename is followed. Today: both ends of a link or a
	 * requirement, a user's assignments, and the jobs and steps naming it.
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
	 * Every edge a user's hold is walked over: the links, then what each
	 * service requires. The whole of both: a loop can run through any row.
	 *
	 * @return array<int, array{0: string, 1: string, 2?: string}> [parent, child] for a link, [service, required, 'requires'] for a requirement.
	 */
	private function links()
	{
		return array_merge($this->packLinks(), $this->requires());
	}

	/**
	 * Every link, and only the links: the pack tree.
	 *
	 * @return array<int, array{0: string, 1: string}> [parent, child] each, slugs.
	 */
	private function packLinks()
	{
		$stmt = $this->db->prepare(
			"SELECT parent, child FROM `{$this->serviceLinksTable}` WHERE kind = ''"
		);
		$stmt->execute();

		return array_map(function ($row) {
			return [(string) $row[0], (string) $row[1]];
		}, $stmt->fetchAll(PDO::FETCH_NUM));
	}

	/**
	 * Every requirement, as edges: the links rows of `kind` REQUIRES.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}> [service, required, 'requires'] each.
	 */
	private function requires()
	{
		$stmt = $this->db->prepare(
			"SELECT parent, child FROM `{$this->serviceLinksTable}` WHERE kind = :kind"
		);
		$stmt->execute([':kind' => self::REQUIRES]);

		return array_map(function ($row) {
			return [(string) $row[0], (string) $row[1], self::REQUIRES];
		}, $stmt->fetchAll(PDO::FETCH_NUM));
	}

	/**
	 * Only the links among some edges: the pack tree.
	 *
	 * @param array<int, array{0: string, 1: string, 2?: string}> $edges As links() answers them.
	 *
	 * @return array<int, array{0: string, 1: string}> The edges with no kind.
	 */
	private static function packsOnly(array $edges)
	{
		return array_values(array_filter($edges, function ($edge) {
			return empty($edge[2]);
		}));
	}

	/**
	 * Only the requirements among some edges.
	 *
	 * @param array<int, array{0: string, 1: string, 2?: string}> $edges As links() answers them.
	 *
	 * @return array<int, array{0: string, 1: string, 2: string}> The edges marked REQUIRES.
	 */
	private static function requiresOnly(array $edges)
	{
		return array_values(array_filter($edges, function ($edge) {
			return !empty($edge[2]);
		}));
	}
}
