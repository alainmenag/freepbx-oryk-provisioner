<?php

// src/Notices.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The notices drawn over every module page -- see ARCHITECTURE.md, "Notices".
 *
 * Not FreePBX's dashboard: those are DashboardNotices. These are declared in
 * definitions(), shown one per category, and never leave the module's pages.
 */
class Notices extends Service
{
	/** The key in the module's key-value store holding what was dismissed: version by id. */
	const DISMISSED = 'notices_dismissed';

	/** @var object The module's BMO class, for its getConfig() and setConfig(). */
	private $store;

	/**
	 * @param object $freepbx FreePBX application instance.
	 * @param object $store   Anything with FreePBX's getConfig($key) and setConfig($key, $value).
	 */
	public function __construct($freepbx, $store)
	{
		parent::__construct($freepbx);

		$this->store = $store;
	}

	/**
	 * Every notice, in the order a category is walked.
	 *
	 * `id` is what a dismissal is stored under and `version` what it is stored
	 * as: **raising the version shows the notice again to whoever dismissed
	 * it**, so it is raised only when the notice says something new. `when`
	 * answers whether the notice still applies and is asked on a page load, so
	 * it is one cheap query at most. `target` is the query the action leads to;
	 * on the page that is already there the notice is drawn without it.
	 *
	 * @return array<int, array<string, mixed>> id, version, category, level
	 *                                          (info|warning), dismissible, text,
	 *                                          action, target and when, each.
	 */
	public function definitions()
	{
		return [
			[
				'id' => 'first-profile',
				'version' => 1,
				'category' => 'welcome',
				'level' => 'info',
				'dismissible' => true,
				'text' => _('Welcome to the Provisioner. A phone is answered from a profile, so start by adding your first one: from the library, or empty.'),
				'action' => _('Add Profile'),
				'target' => ['profile' => ''],
				'when' => function () {
					return $this->rowCount($this->profilesTable) === 0;
				},
			],
			[
				'id' => 'first-user',
				'version' => 1,
				'category' => 'welcome',
				'level' => 'info',
				'dismissible' => true,
				'text' => _('There are no users yet. A user is an extension with a device of its own, for a phone to register as: add your first one.'),
				'action' => _('Add User'),
				'target' => ['user' => ''],
				// Counted as the Users list counts them.
				'when' => function () {
					$stmt = $this->db->prepare('SELECT COUNT(*) ' . Users::FROM . ' WHERE ' . Users::SHAPE);
					$stmt->execute();

					return (int) $stmt->fetchColumn() === 0;
				},
			],
			[
				'id' => 'first-client',
				'version' => 1,
				'category' => 'welcome',
				'level' => 'info',
				'dismissible' => true,
				'text' => _('Next, add your first client: a phone\'s MAC address, the device it stands for and the profile it is answered from.'),
				'action' => _('Add Client'),
				'target' => ['client' => ''],
				'when' => function () {
					return $this->rowCount($this->clientsTable) === 0;
				},
			],
		];
	}

	/**
	 * The notices a page draws: per category, the first that is neither
	 * dismissed nor past applying.
	 *
	 * On the page a notice leads to it has no action: `action` and `href` are ''.
	 *
	 * @param array<string, mixed> $request The page's query.
	 *
	 * @return array<int, array<string, mixed>> id, version, category, level,
	 *                                          dismissible, text, action and href, each.
	 */
	public function showing(array $request)
	{
		$dismissed = $this->dismissed();
		$settled = [];
		$showing = [];

		foreach ($this->definitions() as $notice) {
			$category = (string) $notice['category'];

			if (isset($settled[$category])) {
				continue;
			}

			if ($notice['dismissible'] && ($dismissed[$notice['id']] ?? null) === (int) $notice['version']) {
				continue;
			}

			if (!$this->applies($notice)) {
				continue;
			}

			$settled[$category] = true;

			$showing[] = self::drawn(self::at((array) $notice['target'], $request) ? ['target' => []] + $notice : $notice);
		}

		return $showing;
	}

	/**
	 * Dismiss a notice, and say what its category shows now.
	 *
	 * The version posted is the one that was on the page: a notice raised to a
	 * newer one since is left up, and comes back as `next`.
	 *
	 * @param array<string, mixed> $request `id` and `version`.
	 * @param array<string, mixed> $at      The query of the page it was dismissed on.
	 *
	 * @return array<string, mixed> `status`, and `next`: a showing() entry or null.
	 */
	public function dismiss($request, array $at = [])
	{
		$notice = $this->definition((string) ($request['id'] ?? ''));

		if (!$notice || !$notice['dismissible']) {
			return ['status' => false, 'message' => _('That notice cannot be dismissed.')];
		}

		if ((int) ($request['version'] ?? 0) === (int) $notice['version']) {
			$dismissed = $this->dismissed();
			$dismissed[$notice['id']] = (int) $notice['version'];

			if (!$this->write($dismissed)) {
				return ['status' => false, 'message' => _('The notice could not be dismissed.')];
			}
		}

		$next = null;

		foreach ($this->showing($at) as $shown) {
			if ($shown['category'] === $notice['category']) {
				$next = $shown;
			}
		}

		return ['status' => true, 'next' => $next];
	}

	/**
	 * Forget every dismissal, so each notice that still applies is shown again.
	 *
	 * @return array<string, mixed> `status`.
	 */
	public function reset()
	{
		return ['status' => $this->write([])];
	}

	/**
	 * Drop the dismissals no declared notice would read: an id that is gone, or
	 * a version that has been raised.
	 *
	 * @return void
	 */
	public function prune()
	{
		$dismissed = $this->dismissed();
		$kept = [];

		foreach ($this->definitions() as $notice) {
			if (($dismissed[$notice['id']] ?? null) === (int) $notice['version']) {
				$kept[$notice['id']] = (int) $notice['version'];
			}
		}

		if ($kept !== $dismissed) {
			$this->write($kept);
		}
	}

	/**
	 * How many dismissals are stored, for the Settings tab's reset.
	 *
	 * @return int
	 */
	public function dismissedCount()
	{
		return count($this->dismissed());
	}

	/**
	 * What was dismissed. A store that cannot be read has dismissed nothing.
	 *
	 * @return array<string, int> Version by notice id.
	 */
	private function dismissed()
	{
		try {
			$stored = $this->store->getConfig(self::DISMISSED);
		} catch (\Throwable $e) {
			$this->logWarning('could not read the dismissed notices: ' . $e->getMessage());

			return [];
		}

		$dismissed = [];

		foreach (is_array($stored) ? $stored : [] as $id => $version) {
			$dismissed[(string) $id] = (int) $version;
		}

		return $dismissed;
	}

	/**
	 * Store what was dismissed; nothing dismissed removes the key.
	 *
	 * @param array<string, int> $dismissed Version by notice id.
	 *
	 * @return bool False when the store threw.
	 */
	private function write(array $dismissed)
	{
		try {
			$this->store->setConfig(self::DISMISSED, $dismissed ? $dismissed : false);

			return true;
		} catch (\Throwable $e) {
			$this->logWarning('could not store the dismissed notices: ' . $e->getMessage());

			return false;
		}
	}

	/**
	 * Whether a notice still applies. A check that throws says it does not, so
	 * a broken one costs its notice and never the page.
	 *
	 * @param array<string, mixed> $notice A definitions() entry.
	 *
	 * @return bool
	 */
	private function applies(array $notice)
	{
		try {
			return (bool) call_user_func($notice['when']);
		} catch (\Throwable $e) {
			$this->logWarning('notice ' . $notice['id'] . ' could not be checked: ' . $e->getMessage());

			return false;
		}
	}

	/**
	 * One declared notice, or null. The id may come from a request: it is only
	 * ever compared.
	 *
	 * @param string $id Notice id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function definition($id)
	{
		foreach ($this->definitions() as $notice) {
			if ($notice['id'] === $id) {
				return $notice;
			}
		}

		return null;
	}

	/**
	 * Whether a page is the one a target leads to: every key of the target is
	 * in the page's query with the same value.
	 *
	 * @param array<string, string> $target  A notice's `target`; empty leads nowhere.
	 * @param array<string, mixed>  $request The page's query.
	 *
	 * @return bool
	 */
	private static function at(array $target, array $request)
	{
		if (!$target) {
			return false;
		}

		foreach ($target as $key => $value) {
			if (!array_key_exists($key, $request) || !is_scalar($request[$key]) || trim((string) $request[$key]) !== (string) $value) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A definition as a view is handed it: no `when`, and `target` as an address.
	 *
	 * @param array<string, mixed> $notice A definitions() entry.
	 *
	 * @return array<string, mixed>
	 */
	private static function drawn(array $notice)
	{
		$target = (array) $notice['target'];

		return [
			'id' => (string) $notice['id'],
			'version' => (int) $notice['version'],
			'category' => (string) $notice['category'],
			'level' => $notice['level'] === 'warning' ? 'warning' : 'info',
			'dismissible' => (bool) $notice['dismissible'],
			'text' => (string) $notice['text'],
			'action' => $target ? (string) $notice['action'] : '',
			'href' => $target ? '?display=oryk_provisioner&' . http_build_query($target) : '',
		];
	}
}
