<?php

// src/Navigator.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Where you are, and everywhere you can go from here.
 *
 * Two halves. sections() is the module's top level, drawn as the bar over
 * every page by views/partials/sections.php. levels() is the row of dropdowns
 * views/partials/navigator.php draws under it: Users, Clients, Profiles,
 * Resources, Logs and Bans, always all six, always in that order -- who, which
 * phone, what configuration, which file, what it asked, what refuses it.
 *
 * The page being viewed sets the scope; ARCHITECTURE.md, "Conventions that
 * hold everywhere", has the rules. A new row has no links yet, so it scopes
 * nothing. Choosing an option is a page load to that row -- or, on Overview,
 * to Overview on it -- and the row of dropdowns re-scopes around it.
 *
 * Two keys are places rather than values, and are built here because only the
 * level knows what they cost:
 *
 *   - 'title': what this level is, plural, and the list it is drawn from -- the
 *     viewed row's own tab where there is one (a profile's Clients tab), else
 *     the module's list narrowed the same way (`&scope=`, see scope()), else
 *     the whole list where the level is not scoped. Its badge is 'count', the
 *     options the level lists, so it always agrees with the menu under it.
 *   - 'add': where a *new* one is written, or null where that is not a thing
 *     you can do here. Creating is not navigating, so it is not an option.
 */
class Navigator extends Service
{
	/** @var Clients */
	private $clients;

	/** @var Profiles */
	private $profiles;

	/** @var Resources */
	private $resources;

	/** The most log entries the Logs level lists: the newest, in scope. */
	const LOG_LIMIT = 100;

	/** The most bans the Bans level lists when it is not scoped: the newest. */
	const BAN_LIMIT = 100;

	/** @var Users */
	private $users;

	/** @var ProvisioningLog */
	private $requestLog;

	/** @var Bans */
	private $bans;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Clients $clients, Profiles $profiles, Resources $resources, Users $users, ProvisioningLog $requestLog, Bans $bans)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->profiles = $profiles;
		$this->resources = $resources;
		$this->users = $users;
		$this->requestLog = $requestLog;
		$this->bans = $bans;
	}

	/**
	 * The six dropdowns, scoped by the row a page is viewing.
	 *
	 * `$at` names that row: `user` (an extension), `client`, `profile`,
	 * `profile` and `resource` together, `log` or `ban`. Each is an id, or 'new'
	 * on a page writing one that does not exist yet. Empty on a page viewing no row.
	 *
	 * On Overview the Users and Clients options re-open Overview on the row
	 * chosen, since choosing one is how that page is pointed at something.
	 *
	 * @param array<string, mixed> $at      Row being viewed.
	 * @param string               $section Section the page is in, when its
	 *                                      options depend on it: 'overview'.
	 *
	 * @return array<int, array<string, mixed>> Users, clients, profiles,
	 *                                           resources, logs, bans.
	 */
	public function levels(array $at = [], $section = '')
	{
		$overview = $section === 'overview';

		$user = isset($at['user']) ? (string) $at['user'] : null;
		$client = isset($at['client']) ? (string) $at['client'] : null;
		$profile = isset($at['profile']) ? (string) $at['profile'] : null;
		$resource = isset($at['resource']) ? (string) $at['resource'] : null;
		$log = isset($at['log']) ? (string) $at['log'] : null;
		$ban = isset($at['ban']) ? (string) $at['ban'] : null;

		$clientRows = $this->clients->clientChoices();
		$profileNames = [];

		foreach ($this->profiles->profileChoices() as $row) {
			$profileNames[(int) $row['id']] = (string) $row['name'];
		}

		$scope = $this->scoped($at, $clientRows);
		$from = $this->scopeKey($at);

		// Scoped, the rows that apply were read already; unscoped, the newest
		// BAN_LIMIT, plus the ban being viewed when it is older than those.
		$banRows = $scope['banRows'];

		if ($banRows === null) {
			$banRows = $this->bans->banChoices(null, self::BAN_LIMIT);
			$viewed = $this->written($ban) ? $this->bans->banRow($ban) : null;

			if ($viewed && !in_array((int) $viewed['id'], array_map('intval', array_column($banRows, 'id')), true)) {
				$banRows[] = $viewed;
			}
		}

		return [
			$this->userLevel($scope['users'], $user, $from, $overview),
			$this->clientLevel($clientRows, $scope['clients'], $client, $user, $profile, $from, $overview),
			$this->profileLevel($profileNames, $scope['profiles'], $profile, $from),
			$this->resourceLevel($profileNames, $scope['files'], $resource, $client),
			$this->logLevel($scope['logs'], $scope['ip'], $log, $scope['entry'], $client, $from),
			$this->banLevel($banRows, $scope['bans'], $ban, $user, $client, $profile, $scope['entry'], $from),
		];
	}

	/**
	 * What each level is narrowed to by the row `$at` names, as levels() narrows it.
	 *
	 * The one computation behind both a dropdown's options and its title's
	 * list, so the badge and the table that title opens count the same rows.
	 * Logs and unscoped Bans, whose badges stop at LOG_LIMIT and BAN_LIMIT, are
	 * the exception: the table does not.
	 *
	 * @param array<string, mixed> $at Row being viewed, as levels() takes it.
	 *
	 * @return array<string, mixed> users, clients, profiles, files, bans: ids
	 *                              or null for unscoped; logs: MACs or null;
	 *                              ip: address or null; entry: the viewed log
	 *                              entry's logRow() or null; banRows: the bans
	 *                              that apply, when bans is scoped, or null.
	 */
	public function scope(array $at)
	{
		if ($this->scopeKey($at) === '') {
			return $this->scoped([], []);
		}

		return $this->scoped($at, $this->clients->clientChoices());
	}

	/**
	 * The `&scope=` value naming the row `$at` scopes by, or '' when it scopes nothing.
	 *
	 * The row levels() reads first: a resource page scopes by its profile.
	 *
	 * @param array<string, mixed> $at Row being viewed.
	 *
	 * @return string `<kind>:<id>`, or ''.
	 */
	public function scopeKey(array $at)
	{
		foreach (['client', 'user', 'profile', 'log', 'ban'] as $kind) {
			if (isset($at[$kind]) && $this->written((string) $at[$kind])) {
				return $kind . ':' . (string) $at[$kind];
			}
		}

		return '';
	}

	/**
	 * The row a `&scope=` value names, as levels() and scope() take it.
	 *
	 * Anything that is not `<kind>:<id>` with a kind listed here names nothing.
	 *
	 * @param string $key `&scope=` from the request.
	 *
	 * @return array<string, string> One kind and its id, or empty.
	 */
	public static function scopeAt($key)
	{
		$parts = explode(':', (string) $key, 2);

		if (count($parts) !== 2 || !in_array($parts[0], ['user', 'client', 'profile', 'log', 'ban'], true)) {
			return [];
		}

		$id = trim($parts[1]);

		return ($id === '' || $id === 'new') ? [] : [$parts[0] => $id];
	}

	/**
	 * A section's list, narrowed to what a scope key scopes when `$scoped`.
	 *
	 * @param string $section Section key.
	 * @param string $from    scopeKey(), or ''.
	 * @param bool   $scoped  Whether the level this is the title of is scoped.
	 *
	 * @return string Its address.
	 */
	public static function listHref($section, $from, $scoped = true)
	{
		$href = '?display=oryk_provisioner&tab=' . rawurlencode((string) $section);

		if ($scoped && $from !== '') {
			$href .= '&scope=' . implode(':', array_map('rawurlencode', explode(':', $from, 2)));
		}

		return $href;
	}

	/**
	 * Overview, on one user or client.
	 *
	 * @param string     $kind user|client.
	 * @param int|string $id   Extension, or client id.
	 *
	 * @return string Its address.
	 */
	public static function overviewHref($kind, $id)
	{
		return self::listHref('overview', $kind . ':' . $id);
	}

	/**
	 * Whether a section's list is narrowed by a scope(), and so drawn with `&scope=`.
	 *
	 * @param string               $section users|clients|profiles|logs|bans.
	 * @param array<string, mixed> $scope   scope().
	 *
	 * @return bool Narrowed.
	 */
	public static function narrows($section, array $scope)
	{
		if ($section === 'logs') {
			return $scope['logs'] !== null || $scope['ip'] !== null;
		}

		return in_array($section, ['users', 'clients', 'profiles', 'bans'], true) && $scope[$section] !== null;
	}

	/**
	 * scope(), from the rows levels() has already read.
	 *
	 * @param array<string, mixed>             $at         Row being viewed.
	 * @param array<int, array<string, mixed>> $clientRows clientChoices().
	 *
	 * @return array<string, mixed> As scope() returns it.
	 */
	private function scoped(array $at, array $clientRows)
	{
		$user = isset($at['user']) ? (string) $at['user'] : null;
		$client = isset($at['client']) ? (string) $at['client'] : null;
		$profile = isset($at['profile']) ? (string) $at['profile'] : null;
		$log = isset($at['log']) ? (string) $at['log'] : null;
		$ban = isset($at['ban']) ? (string) $at['ban'] : null;

		// null is "not scoped": the level lists everything. An array is the ids
		// linked to the row being viewed, and may be empty. Logs are scoped by
		// MAC, and by address in `ip`.
		$scope = ['users' => null, 'clients' => null, 'profiles' => null, 'files' => null, 'logs' => null, 'bans' => null];
		$ip = null;
		$entry = null;

		// What the viewed row's requests would be, for Bans::applies(); null
		// where the Bans level is not scoped.
		$requests = null;

		if ($this->written($client)) {
			foreach ($clientRows as $row) {
				if ((string) $row['id'] === $client) {
					$scope['users'] = $this->owner($row) !== '' ? [$this->owner($row)] : [];
					$scope['profiles'] = (int) $row['profile_id'] ? [(int) $row['profile_id']] : [];
					$scope['files'] = $scope['profiles'];
					$scope['logs'] = $this->macs([$row]);
					$requests = [$this->subjects($row)];
				}
			}
		} elseif ($this->written($user)) {
			$scope['clients'] = [];
			$scope['profiles'] = [];
			$linked = [];
			$requests = [['user' => $user]];

			foreach ($clientRows as $row) {
				if ($this->owner($row) === $user) {
					$scope['clients'][] = (int) $row['id'];
					$linked[] = $row;
					$requests[] = $this->subjects($row);

					if ((int) $row['profile_id']) {
						$scope['profiles'][] = (int) $row['profile_id'];
					}
				}
			}

			$scope['profiles'] = array_values(array_unique($scope['profiles']));
			$scope['files'] = $scope['profiles'];
			$scope['logs'] = $this->macs($linked);
		} elseif ($this->written($profile)) {
			$scope['clients'] = [];
			$scope['users'] = [];
			$linked = [];
			$requests = [['profile' => $profile]];

			foreach ($clientRows as $row) {
				if ((int) $row['profile_id'] === (int) $profile) {
					$scope['clients'][] = (int) $row['id'];
					$linked[] = $row;
					$requests[] = $this->subjects($row);

					if ($this->owner($row) !== '') {
						$scope['users'][] = $this->owner($row);
					}
				}
			}

			$scope['users'] = array_values(array_unique($scope['users']));
			$scope['files'] = [(int) $profile];
			$scope['logs'] = $this->macs($linked);
		} elseif ($this->written($log)) {
			// A request is scoped like the client that has its MAC now, if any.
			$entry = $this->requestLog->logRow($log);
			$owner = null;

			foreach ($clientRows as $row) {
				if ($entry && (string) $row['id'] === (string) $entry['client_id']) {
					$owner = $row;
				}
			}

			$scope['clients'] = $owner ? [(int) $owner['id']] : [];
			$scope['users'] = ($owner && $this->owner($owner) !== '') ? [$this->owner($owner)] : [];
			$scope['profiles'] = ($owner && (int) $owner['profile_id']) ? [(int) $owner['profile_id']] : [];
			$scope['files'] = $scope['profiles'];

			if ($entry) {
				// Its own address rather than the one the client was last seen at.
				$requests = [array_merge($owner ? $this->subjects($owner) : [], ['mac' => $entry['mac'], 'ip' => $entry['ip']])];
			}
		} elseif ($this->written($ban)) {
			// The other way round: the clients this ban applies to, and what
			// they are linked to, plus anything the ban names that has no client.
			$row = $this->bans->banRow($ban);

			if ($row) {
				$scope = $this->banScope($row, $clientRows) + $scope;
				$ip = $row['ip'] !== null ? (string) $row['ip'] : null;
			}
		}

		$applying = null;

		if ($requests !== null) {
			$scope['bans'] = [];
			$applying = [];

			foreach ($this->bans->banChoices($requests) as $row) {
				foreach ($requests as $subjects) {
					if (Bans::applies($row, $subjects)) {
						$scope['bans'][] = (int) $row['id'];
						$applying[] = $row;

						break;
					}
				}
			}
		}

		return $scope + ['ip' => $ip, 'entry' => $entry, 'banRows' => $applying];
	}

	/**
	 * The module's own sections, for the bar over every page.
	 *
	 * Exactly one is active on every page: the branch the page is in, not the
	 * URL's `tab` -- a resource page is in Profiles.
	 *
	 * On a user's or client's page, Overview opens on that row.
	 *
	 * The sections sectionGroups() puts together are one entry, where the
	 * group's first section would have stood: the active section of the group,
	 * else its first, with every section of the group under 'items'.
	 *
	 * @param string               $section clients|profiles|services|users|logs|bans|overview|settings.
	 * @param array<string, mixed> $at      Row being viewed, as levels() takes it.
	 *
	 * @return array<int, array<string, mixed>> Each: key, text, href, active;
	 *                                          a group also has items[] of the same.
	 */
	public function sections($section, array $at = [])
	{
		$section = $this->section($section);
		$target = Overview::target($at);
		$groupOf = [];

		foreach ($this->sectionGroups() as $group => $keys) {
			$groupOf += array_fill_keys($keys, $group);
		}

		$bar = [];
		$slots = [];

		foreach ($this->sectionNames() as $key => $text) {
			$item = [
				'key' => $key,
				'text' => $text,
				'href' => ($key === 'overview' && $target && $this->written((string) current($target)))
					? self::overviewHref((string) key($target), (string) current($target))
					: '?display=oryk_provisioner&tab=' . $key,
				'active' => $key === $section,
			];

			if (!isset($groupOf[$key])) {
				$bar[] = $item;

				continue;
			}

			$group = $groupOf[$key];

			if (!isset($slots[$group])) {
				$slots[$group] = count($bar);
				$bar[] = $item + ['items' => []];
			}

			$slot = $slots[$group];
			$bar[$slot]['items'][] = $item;

			if ($item['active']) {
				$bar[$slot] = $item + $bar[$slot];
			}
		}

		return $bar;
	}

	/**
	 * The section a request names, if it is one: else the first, Users.
	 *
	 * The one place that decides, so the list page renders the pane the bar
	 * lights -- no section at all is Users in both.
	 *
	 * @param string $section Section asked for.
	 *
	 * @return string A section that exists.
	 */
	public function section($section)
	{
		$names = $this->sectionNames();

		return isset($names[$section]) ? (string) $section : (string) key($names);
	}

	/**
	 * Every section there is, in the bar's order, by key.
	 *
	 * Users, Clients, Profiles first, in the order of the dropdowns under the bar.
	 *
	 * @return array<string, string> Names, by key.
	 */
	private function sectionNames()
	{
		return [
			'users' => _('Users'),
			'clients' => _('Clients'),
			'profiles' => _('Profiles'),
			'services' => _('Services'),
			'logs' => _('Logs'),
			'bans' => _('Bans'),
			'overview' => _('Overview'),
			'settings' => _('Settings'),
		];
	}

	/**
	 * Which sections share one dropdown on the bar.
	 *
	 * Adding a group, or a section to one, is a line here and nothing else.
	 * They are sectionNames() keys and keep that order; a section in no group
	 * stands on the bar alone.
	 *
	 * @return array<int, array<int, string>> Groups, each a list of section keys.
	 */
	private function sectionGroups()
	{
		return [
			['users', 'clients', 'profiles', 'services'],
			['logs', 'bans', 'overview'],
		];
	}

	/**
	 * Users: all of them, or the ones whose phones the viewed row is linked to.
	 *
	 * @param array<int, string>|null $scope Extensions linked, or null for all.
	 * @param string|null             $at    Extension being viewed, 'new', or null.
	 * @param string                  $from     scopeKey() of the viewed row, or ''.
	 * @param bool                    $overview Whether an option opens Overview on its user.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function userLevel($scope, $at, $from, $overview = false)
	{
		$rows = [];

		foreach ($this->users->userChoices() as $row) {
			$extension = (string) $row['extension'];

			$rows[] = [
				'id' => $extension,
				'text' => $extension,
				'note' => (string) (isset($row['name']) ? $row['name'] : ''),
				'href' => $overview ? self::overviewHref('user', $extension) : '?display=oryk_provisioner&user=' . rawurlencode($extension),
				'page' => '?display=oryk_provisioner&user=' . rawurlencode($extension),
			];
		}

		return $this->level($rows, $scope, $at, [
			'key' => 'user',
			'title' => ['text' => _('Users'), 'href' => self::listHref('users', $from, $scope !== null)],
			'mono' => true,
			'new' => _('New user'),
			'search' => _('Search users'),
			'none' => _('No user here'),
			'add' => ['text' => _('New user'), 'href' => '?display=oryk_provisioner&user='],
		]);
	}

	/**
	 * Clients, by the MAC they are and the description they are known by.
	 *
	 * A client without a MAC reads as the dash every list shows it as. Viewing a
	 * user, a new client is written with that user's device already chosen.
	 *
	 * @param array<int, array<string, mixed>> $clientRows clientChoices().
	 * @param array<int, int>|null             $scope      Ids linked, or null for all.
	 * @param string|null                      $at         Client being viewed, 'new', or null.
	 * @param string|null                      $user       Extension being viewed, or null.
	 * @param string|null                      $profile    Profile being viewed, or null.
	 * @param string                           $from       scopeKey() of the viewed row, or ''.
	 * @param bool                             $overview   Whether an option opens Overview on its client.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function clientLevel(array $clientRows, $scope, $at, $user, $profile, $from, $overview = false)
	{
		$rows = [];

		foreach ($clientRows as $row) {
			$mac = (string) $row['mac'];

			$rows[] = [
				'id' => (int) $row['id'],
				'text' => $mac === '' ? '-' : $mac,
				'note' => (string) (isset($row['description']) ? $row['description'] : ''),
				'href' => $overview ? self::overviewHref('client', (int) $row['id']) : '?display=oryk_provisioner&client=' . (int) $row['id'],
				'page' => '?display=oryk_provisioner&client=' . (int) $row['id'],
			];
		}

		// The list this level is drawn from: the viewed row's own Clients tab.
		$list = self::listHref('clients', $from, $scope !== null);
		$add = '?display=oryk_provisioner&client=';

		if ($this->written($user)) {
			$list = '?display=oryk_provisioner&user=' . rawurlencode($user) . '&tab=clients';
			$add .= '&device_id=' . rawurlencode($user);
		} elseif ($this->written($profile)) {
			$list = '?display=oryk_provisioner&profile=' . (int) $profile . '&tab=clients';
		}

		return $this->level($rows, $scope, $at, [
			'key' => 'client',
			'title' => ['text' => _('Clients'), 'href' => $list],
			'mono' => true,
			'new' => _('New client'),
			'search' => _('Search clients'),
			'none' => _('No clients here'),
			'add' => ['text' => _('New client'), 'href' => $add],
		]);
	}

	/**
	 * Profiles: all of them, or the ones the viewed row's phones use.
	 *
	 * @param array<int, string>   $profileNames Every profile's name, by id.
	 * @param array<int, int>|null $scope        Ids linked, or null for all.
	 * @param string|null          $at           Profile being viewed, 'new', or null.
	 * @param string               $from         scopeKey() of the viewed row, or ''.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function profileLevel(array $profileNames, $scope, $at, $from)
	{
		$rows = [];

		foreach ($profileNames as $id => $name) {
			$rows[] = [
				'id' => $id,
				'text' => $name,
				'note' => '',
				'href' => '?display=oryk_provisioner&profile=' . $id,
			];
		}

		return $this->level($rows, $scope, $at, [
			'key' => 'profile',
			'title' => ['text' => _('Profiles'), 'href' => self::listHref('profiles', $from, $scope !== null)],
			'mono' => false,
			'new' => _('New profile'),
			'search' => _('Search profiles'),
			'none' => _('No profile here'),
			'add' => ['text' => _('New profile'), 'href' => '?display=oryk_provisioner&profile='],
		]);
	}

	/**
	 * The files of the profiles in scope.
	 *
	 * A file belongs to a profile and means nothing without one, so with no
	 * profile in scope this level lists nothing and says so. With more than
	 * one, each file carries its profile's name under it.
	 *
	 * @param array<int, string>   $profileNames Every profile's name, by id.
	 * @param array<int, int>|null $profiles     Profiles whose files to list, or null.
	 * @param string|null          $at           Resource being viewed, 'new', or null.
	 * @param string|null          $client       Client being viewed, or null.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function resourceLevel(array $profileNames, $profiles, $at, $client)
	{
		$rows = [];
		$unscoped = $profiles === null;
		$empty = $unscoped ? _('Pick a profile to see its files') : _('No profile here');
		// No profile in scope: there is nothing to pick yet, and the crumb says so.
		$prompt = $profiles === [] ? _('None') : null;
		$profiles = (array) $profiles;

		if ($profiles) {
			$empty = _('Nothing here yet');
		}

		foreach ($profiles as $profileId) {
			foreach ($this->resources->resourceChoices($profileId) as $row) {
				$rows[] = [
					'id' => (int) $row['id'],
					'text' => (string) $row['name'],
					'note' => count($profiles) > 1 ? (isset($profileNames[$profileId]) ? $profileNames[$profileId] : '') : '',
					'href' => '?display=oryk_provisioner&profile=' . (int) $profileId . '&resource=' . (int) $row['id'],
				];
			}
		}

		$one = count($profiles) === 1 ? (int) reset($profiles) : 0;
		$list = '';

		// A client's Resources tab names each file as *that* phone asks for it.
		if ($this->written($client) && $one) {
			$list = '?display=oryk_provisioner&client=' . (int) $client . '&tab=resources';
		} elseif ($one) {
			$list = '?display=oryk_provisioner&profile=' . $one . '&tab=resources';
		}

		// Every file listed is in scope, so the level is never filtered again.
		return $this->level($rows, null, $at, [
			'key' => 'resource',
			'title' => ['text' => _('Resources'), 'href' => $list],
			'mono' => true,
			'new' => _('New resource'),
			'prompt' => $prompt,
			'search' => _('Search resources'),
			'empty' => $empty,
			// No profile in scope is not zero files: there is nothing to count yet.
			'count' => $unscoped ? null : count($rows),
			'add' => $one ? ['text' => _('New resource'), 'href' => '?display=oryk_provisioner&profile=' . $one . '&resource='] : null,
		]);
	}

	/**
	 * The levels a ban scopes, from the clients it applies to.
	 *
	 * Users and Profiles add the user and profile the ban names, which may have
	 * no client. Logs are the applied clients' MACs where the ban names a
	 * client, user or profile; else its MAC; else any MAC, from its address.
	 *
	 * @param array<string, mixed>             $ban        Bans::banChoices() row.
	 * @param array<int, array<string, mixed>> $clientRows clientChoices().
	 *
	 * @return array<string, mixed> users, clients, profiles, files, logs.
	 */
	private function banScope(array $ban, array $clientRows)
	{
		$applied = [];

		foreach ($clientRows as $row) {
			if (Bans::applies($ban, $this->subjects($row))) {
				$applied[] = $row;
			}
		}

		$users = $ban['extension'] !== null ? [(string) $ban['extension']] : [];
		$profiles = $ban['profile_id'] !== null ? [(int) $ban['profile_id']] : [];
		$clients = [];

		foreach ($applied as $row) {
			$clients[] = (int) $row['id'];

			if ($this->owner($row) !== '') {
				$users[] = $this->owner($row);
			}

			if ((int) $row['profile_id']) {
				$profiles[] = (int) $row['profile_id'];
			}
		}

		$profiles = array_values(array_unique($profiles));
		$logs = null;

		if ($ban['client_id'] !== null || $ban['extension'] !== null || $ban['profile_id'] !== null) {
			$logs = $this->macs($applied);
		} elseif ($ban['mac'] !== null) {
			$logs = [(string) $ban['mac']];
		}

		return [
			'users' => array_values(array_unique($users)),
			'clients' => $clients,
			'profiles' => $profiles,
			'files' => $profiles,
			'logs' => $logs,
		];
	}

	/**
	 * The user a client is: the extension its device is on, whichever of that
	 * extension's devices it is; else the device it names.
	 *
	 * @param array<string, mixed> $row clientChoices() row.
	 *
	 * @return string Extension, or '' for a client with no device.
	 */
	private function owner(array $row)
	{
		$extension = (string) ($row['extension'] ?? '');

		return ($extension !== '' && $extension !== 'none') ? $extension : (string) ($row['device_id'] ?? '');
	}

	/**
	 * A client's requests as Bans::applies() reads them: the address is the
	 * public one it was last seen at.
	 *
	 * @param array<string, mixed> $row clientChoices() row.
	 *
	 * @return array<string, mixed> client, user, mac, profile, ip.
	 */
	private function subjects(array $row)
	{
		return [
			'client' => $row['id'],
			// The device and the extension it is on, as Endpoint asks with both.
			'user' => array_values(array_unique(array_filter([(string) $row['device_id'], $this->owner($row)], 'strlen'))),
			'mac' => $row['mac'],
			'profile' => $row['profile_id'],
			'ip' => $row['public_ip'] ?? '',
		];
	}

	/**
	 * The MACs of some clients, the ones without one left out.
	 *
	 * @param array<int, array<string, mixed>> $rows clientChoices() rows.
	 *
	 * @return array<int, string> MACs, as the log stores them.
	 */
	private function macs(array $rows)
	{
		$macs = [];

		foreach ($rows as $row) {
			if ((string) $row['mac'] !== '') {
				$macs[] = (string) $row['mac'];
			}
		}

		return array_values(array_unique($macs));
	}

	/**
	 * Log entries: the newest LOG_LIMIT in scope, each a page of its own.
	 *
	 * Already narrowed when read, so the level is never filtered again. On an
	 * entry's page that entry is listed even when it is older than the rest.
	 * Nothing writes an entry from here, so there is no add row.
	 *
	 * @param array<int, string>|null           $macs       MACs to keep, or null for any.
	 * @param string|null                       $ip         Address to keep, or null for any.
	 * @param string|null                       $at         Entry being viewed, or null.
	 * @param array<string, mixed>|null         $entry      That entry's logRow(), or null.
	 * @param string|null                       $client     Client being viewed, or null.
	 * @param string                            $from       scopeKey() of the viewed row, or ''.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function logLevel($macs, $ip, $at, $entry, $client, $from)
	{
		$found = $this->requestLog->logChoices($macs, $ip, self::LOG_LIMIT);
		$listed = array_map(function ($row) {
			return (string) $row['id'];
		}, $found);

		if ($entry && !in_array((string) $entry['id'], $listed, true)) {
			$found[] = $entry;
		}

		$rows = [];

		foreach ($found as $row) {
			$method = (string) $row['method'];
			$note = [(int) $row['status'] . ($method !== '' && $method !== 'GET' && $method !== 'HEAD' ? ' ' . $method : ''), (string) $row['created_at']];

			// One MAC in scope says it once, on the crumb above, not on every row.
			if ($macs === null || count($macs) > 1) {
				$note[] = (string) $row['mac'];
			}

			if ((string) $row['ip'] !== '') {
				$note[] = (string) $row['ip'];
			}

			$rows[] = [
				'id' => (int) $row['id'],
				'text' => (string) $row['filename'] !== '' ? (string) $row['filename'] : _('(main config)'),
				'note' => implode(' · ', $note),
				'href' => '?display=oryk_provisioner&log=' . (int) $row['id'],
			];
		}

		$list = self::listHref('logs', $from, $macs !== null || $ip !== null);

		// A client's Logs tab is drawn only for a client with a MAC.
		if ($this->written($client) && $macs) {
			$list = '?display=oryk_provisioner&client=' . (int) $client . '&tab=logs';
		}

		return $this->level($rows, null, $at, [
			'key' => 'log',
			'title' => ['text' => _('Logs'), 'href' => $list],
			'mono' => true,
			'new' => '',
			'prompt' => ($macs !== null && !$rows) ? _('None') : null,
			'search' => _('Search logs'),
			'empty' => $macs !== null ? _('No log entries here') : _('Nothing here yet'),
			'add' => null,
		]);
	}

	/**
	 * Bans: all of them, or the ones that apply to the viewed row's requests.
	 *
	 * Each is named by what it names, with its state under it. Viewing a user,
	 * client, profile or log entry, a new ban is written naming that.
	 *
	 * @param array<int, array<string, mixed>> $banRows The bans that apply, or the newest BAN_LIMIT.
	 * @param array<int, int>|null             $scope   Ids that apply, or null for all.
	 * @param string|null                      $at      Ban being viewed, 'new', or null.
	 * @param string|null                      $user    Extension being viewed, or null.
	 * @param string|null                      $client  Client being viewed, or null.
	 * @param string|null                      $profile Profile being viewed, or null.
	 * @param array<string, mixed>|null        $entry   Log entry being viewed, or null.
	 * @param string                           $from    scopeKey() of the viewed row, or ''.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function banLevel(array $banRows, $scope, $at, $user, $client, $profile, $entry, $from)
	{
		$states = ['banned' => _('Banned'), 'deny' => _('Deny'), 'allow' => _('Allow')];
		$rows = [];

		foreach ($banRows as $row) {
			$names = [];

			if ($row['client_id'] !== null) {
				$names[] = sprintf(_('client %s'), (string) $row['client_label']);
			}

			if ($row['extension'] !== null) {
				$names[] = sprintf(_('user %s'), (string) $row['extension']);
			}

			if ($row['mac'] !== null) {
				$names[] = (string) $row['mac'];
			}

			if ($row['profile_id'] !== null) {
				$names[] = sprintf(_('profile %s'), (string) ($row['profile_name'] ?: '#' . $row['profile_id']));
			}

			if ($row['ip'] !== null) {
				$names[] = (string) $row['ip'];
			}

			$note = [$states[(string) $row['state']] ?? (string) $row['state']];

			if (empty($row['active'])) {
				$note[] = _('expired');
			}

			if ((string) $row['note'] !== '') {
				$note[] = (string) $row['note'];
			}

			$rows[] = [
				'id' => (int) $row['id'],
				'text' => $names ? implode(', ', $names) : '#' . (int) $row['id'],
				'note' => implode(' · ', $note),
				'href' => '?display=oryk_provisioner&ban=' . (int) $row['id'],
			];
		}

		$add = '?display=oryk_provisioner&ban=';

		if ($this->written($client)) {
			$add .= '&ban_client=' . (int) $client;
		} elseif ($this->written($user)) {
			$add .= '&ban_user=' . rawurlencode($user);
		} elseif ($this->written($profile)) {
			$add .= '&ban_profile=' . (int) $profile;
		} elseif ($entry && (string) $entry['ip'] !== '') {
			$add .= '&ban_ip=' . rawurlencode((string) $entry['ip']);
		}

		return $this->level($rows, $scope, $at, [
			'key' => 'ban',
			'title' => ['text' => _('Bans'), 'href' => self::listHref('bans', $from, $scope !== null)],
			'mono' => false,
			'new' => _('New ban'),
			'search' => _('Search bans'),
			'none' => _('No bans here'),
			'add' => ['text' => _('New ban'), 'href' => $add],
		]);
	}

	/**
	 * One level, from every row of its kind and the scope it is narrowed to.
	 *
	 * The row being viewed is the active one. A scope of exactly one row makes
	 * that row active too: it is the only thing this level can be, here.
	 *
	 * @param array<int, array<string, mixed>> $rows  Each: id, text, note, href,
	 *                                                and page where the row's
	 *                                                own page is not its href.
	 * @param array<int, mixed>|null           $scope Ids to keep, or null for all.
	 * @param string|null                      $at    Id being viewed, 'new', or null.
	 * @param array<string, mixed>             $meta  key, title, mono, new,
	 *                                                search, add, prompt (what
	 *                                                the crumb says with nothing
	 *                                                chosen, null or absent for
	 *                                                "Select"), and
	 *                                                none (what the menu of a
	 *                                                scope with nothing in it
	 *                                                says) or
	 *                                                empty (said either way);
	 *                                                count to override the
	 *                                                options counted.
	 *
	 * @return array<string, mixed> One level, as views/partials/navigator.php reads it.
	 */
	private function level(array $rows, $scope, $at, array $meta)
	{
		$options = [];
		$text = '';
		$href = '';
		$keep = $scope === null ? null : array_map('strval', $scope);

		foreach ($rows as $row) {
			$id = (string) $row['id'];

			if ($keep !== null && !in_array($id, $keep, true)) {
				continue;
			}

			$active = $at === $id || ($keep !== null && count($keep) === 1);

			$options[] = [
				'text' => $row['text'],
				'note' => $row['note'],
				'href' => $row['href'],
				'active' => $active,
			];

			if ($active) {
				$text = $row['text'];
				$href = isset($row['page']) ? $row['page'] : $row['href'];
			}
		}

		$add = $meta['add'];

		if ($add !== null) {
			// On the page writing one, the add row is where you are.
			$add['active'] = $at === 'new';
		}

		return [
			'key' => $meta['key'],
			'title' => $meta['title'],
			'text' => $at === 'new' ? $meta['new'] : $text,
			// Where the chosen row is, for the crumb's own link; '' on a new one.
			'href' => $at === 'new' ? '' : $href,
			'mono' => $meta['mono'],
			// Every level asks the same way. A scope with nothing in it says None
			// on the crumb; the menu under it says what is missing.
			'prompt' => (!$options && $keep !== null) ? _('None') : (isset($meta['prompt']) ? $meta['prompt'] : _('Select')),
			'search' => $meta['search'],
			'options' => $options,
			'count' => array_key_exists('count', $meta) ? $meta['count'] : count($options),
			// What an empty menu says: nothing linked is not the same as nothing yet.
			'empty' => isset($meta['empty']) ? $meta['empty'] : ($keep !== null ? $meta['none'] : _('Nothing here yet')),
			'add' => $add,
		];
	}

	/**
	 * Whether an id names a row that has been written, rather than 'new' or nothing.
	 *
	 * @param string|null $id Id from `$at`.
	 *
	 * @return bool Written.
	 */
	private function written($id)
	{
		return $id !== null && $id !== '' && $id !== 'new';
	}
}
