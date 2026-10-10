<?php

// src/Navigator.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Where you are, and everywhere you can go from here.
 *
 * Two halves. sections() is the module's top level, drawn as the bar over
 * every page by views/partials/sections.php. levels() is the row of dropdowns
 * views/partials/navigator.php draws under it: Users, Devices, Clients,
 * Profiles, Resources, Services, Logs, Bans and Jobs, always all nine, always
 * in that order -- who, which line, which phone, what configuration, which
 * file, what they are assigned, what it asked, what refuses it, what was done
 * about their services.
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

	/**
	 * The most rows a menu holds: the ones last touched, the chosen row first.
	 * The rest are reached by typing, which asks options() for the matches.
	 */
	const MENU_LIMIT = 10;

	/** The most log entries the Logs level lists: the newest, in scope. */
	const LOG_LIMIT = 100;

	/** The most bans the Bans level lists when it is not scoped: the newest. */
	const BAN_LIMIT = 100;

	/** The most jobs the Jobs level lists: the newest, in scope. */
	const JOB_LIMIT = 100;

	/** @var Users */
	private $users;

	/** @var ProvisioningLog */
	private $requestLog;

	/** @var Bans */
	private $bans;

	/** @var Services */
	private $services;

	/** @var Jobs|null Null where there is no Jobs level to fill: the tests. */
	private $jobs;

	/** @var Devices|null Null where there is no Devices level to fill: the tests. */
	private $devices;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Clients $clients, Profiles $profiles, Resources $resources, Users $users, ProvisioningLog $requestLog, Bans $bans, Services $services, ?Jobs $jobs = null, ?Devices $devices = null)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->profiles = $profiles;
		$this->resources = $resources;
		$this->users = $users;
		$this->requestLog = $requestLog;
		$this->bans = $bans;
		$this->services = $services;
		$this->jobs = $jobs;
		$this->devices = $devices;
	}

	/**
	 * The nine dropdowns, scoped by the row a page is viewing.
	 *
	 * The viewed row is chosen on its own level, which is flagged `viewed`,
	 * and so are its parents (parents()) on theirs: a client's page has its
	 * user, device and profile chosen too, which is the trail the navigator
	 * draws. Nothing else is. A level with a row chosen lists all of its kind.
	 *
	 * `$at` names that row: `user` (an extension), `device`, `client`, `profile`,
	 * `profile` and `resource` together, `log`, `ban`, `service` (a slug) or `job`.
	 * Each is an id, or 'new' on a page writing one that does not exist yet.
	 * Empty on a page viewing no row.
	 *
	 * On Overview the Users and Clients options re-open Overview on the row
	 * chosen, since choosing one is how that page is pointed at something.
	 *
	 * @param array<string, mixed> $at      Row being viewed.
	 * @param string               $section Section the page is in, when its
	 *                                      options depend on it: 'overview'.
	 *
	 * @return array<int, array<string, mixed>> Users, devices, clients, profiles,
	 *                                           resources, services, logs, bans, jobs.
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
		$service = isset($at['service']) ? (string) $at['service'] : null;
		$job = isset($at['job']) ? (string) $at['job'] : null;
		$device = isset($at['device']) ? (string) $at['device'] : null;

		$clientRows = $this->clients->clientChoices();
		$profileNames = [];

		$profileTouched = [];

		foreach ($this->profiles->profileChoices() as $row) {
			$profileNames[(int) $row['id']] = (string) $row['name'];
			$profileTouched[(int) $row['id']] = $row['touched'] ?? null;
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

		// The viewed row's parents are chosen with it: they are the trail the
		// navigator draws back to the user. A parent's level is not narrowed
		// -- a crumb lists all of its kind, like the viewed row's own, so the
		// trail is where you switch user or profile. Only `$user` and the
		// rest stay as `$at` gave them, since the list and add links are the
		// viewed row's.
		$up = $this->parents($at, $clientRows, $scope['entry']);

		foreach ($up as $key => $parent) {
			if ($parent !== null) {
				$scope[$key . 's'] = null;
			}
		}

		// The one level whose row is the page's: a resource's over its profile,
		// else whichever key `$at` carries. Its parents are chosen, not viewed.
		$viewed = isset($at['resource']) ? 'resource' : (string) key(array_filter($at, function ($id) {
			return $id !== null && $id !== '';
		}));

		$levels = [
			$this->userLevel($scope['users'], $user ?? $up['user'], $from, $overview),
			$this->deviceLevel($scope['devices'], $device ?? $up['device'], $from),
			$this->clientLevel($clientRows, $scope['clients'], $client ?? $up['client'], $user, $profile, $from, $overview),
			$this->profileLevel($profileNames, $profileTouched, $scope['profiles'], $profile ?? $up['profile'], $from),
			$this->resourceLevel($profileNames, $scope['files'], $resource, $client),
			$this->serviceLevel($scope['services'], $service, $user, $from),
			$this->logLevel($scope['logs'], $scope['ip'], $log, $scope['entry'], $client, $from),
			$this->banLevel($banRows, $scope['bans'], $ban, $user, $client, $profile, $scope['entry'], $from),
			$this->jobLevel($scope['jobs'], $job, $from),
		];

		foreach ($levels as &$level) {
			$level['viewed'] = $level['key'] === $viewed;
			// What a search in the menu sends back to options().
			$level['scope'] = $from;
			$level['section'] = $section;
			$level = $this->trim($level, '');
		}

		return $levels;
	}

	/**
	 * One level's options matching a search, for the menu's filter.
	 *
	 * The same rows levels() would list, so the scope is the page's; `$query`
	 * is matched against an option's text and note, case blind, and the
	 * MENU_LIMIT last touched of the matches are returned.
	 *
	 * @param string               $key     Level: user, device, client, profile,
	 *                                      resource, service, log, ban, job.
	 * @param array<string, mixed> $at      Row being viewed, from scopeAt().
	 * @param string               $query   What was typed.
	 * @param string               $section As levels() takes it.
	 *
	 * @return array<string, mixed> status, options (as a level's), more (whether
	 *                              matches were left out), total.
	 */
	public function options($key, array $at, $query, $section = '')
	{
		foreach ($this->levels($at, $section) as $level) {
			if ($level['key'] === $key) {
				$level = $this->trim($level, $query);

				return ['status' => true, 'options' => $level['options'], 'more' => $level['more'], 'total' => $level['total']];
			}
		}

		return ['status' => false, 'message' => _('No such level.')];
	}

	/**
	 * A level's options cut to the menu: the matches of `$query`, the last
	 * touched first, the chosen row ahead of them all, MENU_LIMIT at most.
	 *
	 * Rows with no `touched` -- FreePBX's users and devices -- keep the order
	 * they came in. `count` is left as the scope's count; `total` is the
	 * matches, `more` whether any were cut.
	 *
	 * @param array<string, mixed> $level One level, with every option.
	 * @param string               $query What was typed, or ''.
	 *
	 * @return array<string, mixed> The level, trimmed.
	 */
	private function trim(array $level, $query)
	{
		$needle = mb_strtolower(trim((string) $query));
		$rows = [];

		foreach ($level['options'] as $i => $option) {
			if ($needle !== '' && mb_strpos(mb_strtolower($option['text'] . ' ' . $option['note']), $needle) === false) {
				continue;
			}

			$rows[] = $option + ['order' => $i];
		}

		// Stable on every PHP: the original order breaks ties.
		usort($rows, function ($a, $b) {
			if ($a['active'] !== $b['active']) {
				return $a['active'] ? -1 : 1;
			}

			$cmp = strcmp((string) $b['touched'], (string) $a['touched']);

			return $cmp !== 0 ? $cmp : $a['order'] - $b['order'];
		});

		$level['total'] = count($rows);
		$level['more'] = count($rows) > self::MENU_LIMIT;
		$level['options'] = array_map(function ($row) {
			unset($row['order'], $row['touched']);

			return $row;
		}, array_slice($rows, 0, self::MENU_LIMIT));

		return $level;
	}

	/**
	 * The parents of the row `$at` names: ids by level key, null where it has none.
	 *
	 * A parent is what a row belongs to by a column of its own -- a client's
	 * user, device and profile; a device's user; a job's user; a log entry's
	 * client and, through it, that client's -- and nothing else. The one row a
	 * scope happens to hold is not a parent, and is never chosen for it. A
	 * resource's profile is one, but `$at` already names it.
	 *
	 * @param array<string, mixed>             $at         Row being viewed, as levels() takes it.
	 * @param array<int, array<string, mixed>> $clientRows clientChoices().
	 * @param array<string, mixed>|null        $entry      The viewed log entry's row, or null.
	 *
	 * @return array<string, string|null> user, device, client, profile.
	 */
	private function parents(array $at, array $clientRows, $entry)
	{
		$up = ['user' => null, 'device' => null, 'client' => null, 'profile' => null];
		$client = isset($at['client']) ? (string) $at['client'] : null;

		if ($this->written(isset($at['log']) ? (string) $at['log'] : null) && $entry) {
			$client = (string) $entry['client_id'];
		}

		if ($this->written($client)) {
			foreach ($clientRows as $row) {
				if ((string) $row['id'] === $client) {
					$up['client'] = $client;
					$up['user'] = $this->owner($row) !== '' ? $this->owner($row) : null;
					$up['device'] = (string) $row['device_id'] !== '' ? (string) $row['device_id'] : null;
					$up['profile'] = (int) $row['profile_id'] ? (string) (int) $row['profile_id'] : null;
				}
			}
		} elseif ($this->written(isset($at['device']) ? (string) $at['device'] : null) && $this->devices) {
			$row = $this->devices->deviceRow((string) $at['device']);
			$on = $row ? (string) $row['user'] : '';
			$up['user'] = ($on !== '' && $on !== Devices::NOBODY) ? $on : null;
		} elseif ($this->written(isset($at['job']) ? (string) $at['job'] : null) && $this->jobs) {
			$row = $this->jobs->jobRow((string) $at['job']);
			$up['user'] = $row ? (string) $row['extension'] : null;
		}

		return $up;
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
	 * @return array<string, mixed> users, devices, clients, profiles, files,
	 *                              bans: ids or null for unscoped; services: slugs or
	 *                              null; jobs: extensions or services to narrow
	 *                              the jobs by (Jobs::listJobs()), or null;
	 *                              logs: MACs or null;
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
		foreach (['client', 'user', 'device', 'profile', 'log', 'ban', 'service', 'job'] as $kind) {
			if (isset($at[$kind]) && $this->written((string) $at[$kind])) {
				return ($kind === 'service' && !empty($at['held']) ? 'holders' : $kind) . ':' . (string) $at[$kind];
			}
		}

		return '';
	}

	/**
	 * The row a `&scope=` value names, as levels() and scope() take it.
	 *
	 * Anything that is not `<kind>:<id>` with a kind listed here names nothing.
	 * `holders:<slug>` is a service with `held` set: scoped() then takes its
	 * users to be everyone who has it, through a pack included.
	 *
	 * @param string $key `&scope=` from the request.
	 *
	 * @return array<string, string> One kind and its id, or empty.
	 */
	public static function scopeAt($key)
	{
		$parts = explode(':', (string) $key, 2);

		if (count($parts) !== 2 || !in_array($parts[0], ['user', 'device', 'client', 'profile', 'log', 'ban', 'service', 'holders', 'job'], true)) {
			return [];
		}

		$id = trim($parts[1]);

		if ($id === '' || $id === 'new') {
			return [];
		}

		// A service again, scoped to everyone who has it and not only those assigned it.
		return $parts[0] === 'holders' ? ['service' => $id, 'held' => '1'] : [$parts[0] => $id];
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
	 * @param string               $section users|devices|clients|profiles|services|logs|bans|jobs.
	 * @param array<string, mixed> $scope   scope().
	 *
	 * @return bool Narrowed.
	 */
	public static function narrows($section, array $scope)
	{
		if ($section === 'logs') {
			return $scope['logs'] !== null || $scope['ip'] !== null;
		}

		if ($section === 'jobs') {
			return ($scope['jobs'] ?? null) !== null;
		}

		return in_array($section, ['users', 'devices', 'clients', 'profiles', 'services', 'bans'], true) && ($scope[$section] ?? null) !== null;
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
		$service = isset($at['service']) ? (string) $at['service'] : null;
		$job = isset($at['job']) ? (string) $at['job'] : null;
		$device = isset($at['device']) ? (string) $at['device'] : null;

		// A job is scoped like its user, whose page it would be on.
		$ofJob = false;

		if ($this->written($job) && $this->jobs) {
			$row = $this->jobs->jobRow($job);
			$user = $row ? (string) $row['extension'] : null;
			$ofJob = (bool) $row;
		}

		// null is "not scoped": the level lists everything. An array is the ids
		// linked to the row being viewed, and may be empty. Logs are scoped by
		// MAC, and by address in `ip`.
		$scope = ['users' => null, 'devices' => null, 'clients' => null, 'profiles' => null, 'files' => null, 'services' => null, 'logs' => null, 'bans' => null, 'jobs' => null];
		$ip = null;
		$entry = null;

		// Services follow Users: whose assignments the Services level lists.
		// A viewed service lists all of its kind, like any row's own level.
		$holders = null;
		$own = false;

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
			$holders = [$user];

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
		} elseif ($this->written($device)) {
			// Like a user, one line of it: the extension it is on, and the
			// clients on this device alone.
			$row = $this->devices ? $this->devices->deviceRow($device) : null;
			$on = $row ? (string) $row['user'] : '';
			$scope['users'] = ($on !== '' && $on !== Devices::NOBODY) ? [$on] : [];
			$scope['clients'] = [];
			$scope['profiles'] = [];
			$linked = [];
			$requests = [['user' => array_values(array_unique(array_merge([$device], $scope['users'])))]];

			foreach ($clientRows as $row) {
				if ((string) $row['device_id'] === $device) {
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
		} elseif ($this->written($service)) {
			// The other way round, like a ban: the users assigned it themselves
			// -- never through a pack, unless `holders:` asked for those too --
			// and what their phones are linked to.
			$own = true;
			$scope['users'] = !empty($at['held']) ? $this->services->holdersOf($service) : $this->services->usersOf($service);
			$scope['clients'] = [];
			$scope['profiles'] = [];
			$linked = [];
			$requests = [];

			foreach ($scope['users'] as $extension) {
				$requests[] = ['user' => $extension];
			}

			foreach ($clientRows as $row) {
				if (in_array($this->owner($row), $scope['users'], true)) {
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
		}

		if (!$own && $holders === null) {
			$holders = $scope['users'];
		}

		if ($holders !== null) {
			$scope['services'] = $this->services->assignedTo($holders);
		}

		// Jobs follow Users too; a service's are the ones changes to it made,
		// and a viewed job's own level lists all of its kind.
		if ($ofJob) {
			$scope['users'] = [$user];
		} elseif ($this->written($service)) {
			$scope['jobs'] = ['services' => [$service]];
		} elseif ($holders !== null) {
			$scope['jobs'] = ['extensions' => $holders];
		}

		// Devices: a user's, or a service's users', are the ones on those
		// extensions; anything else scopes the devices its clients are on. A
		// viewed device's own level lists all of its kind.
		if ($this->written($device)) {
			$scope['devices'] = null;
		} elseif ($this->written($user)) {
			$scope['devices'] = $this->devices ? $this->devices->idsOn([$user]) : [];
		} elseif ($this->written($service)) {
			$scope['devices'] = $this->devices ? $this->devices->idsOn((array) $scope['users']) : [];
		} elseif ($this->written($client) || $scope['clients'] !== null) {
			$of = $this->written($client) ? [$client] : array_map('strval', $scope['clients']);
			$scope['devices'] = [];

			foreach ($clientRows as $row) {
				if (in_array((string) $row['id'], $of, true) && (string) $row['device_id'] !== '') {
					$scope['devices'][] = (string) $row['device_id'];
				}
			}

			$scope['devices'] = array_values(array_unique($scope['devices']));
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
	 * @param string               $section clients|profiles|services|devices|users|logs|bans|jobs|overview|settings.
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
	 * A group's sections are drawn in this order, and its first is what the
	 * bar shows when none of them is open.
	 *
	 * @return array<string, string> Names, by key.
	 */
	private function sectionNames()
	{
		return [
			'users' => _('Users'),
			'devices' => _('Devices'),
			'clients' => _('Clients'),
			'profiles' => _('Profiles'),
			'services' => _('Services'),
			'logs' => _('Logs'),
			'jobs' => _('Jobs'),
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
			['users', 'devices', 'clients', 'profiles', 'services'],
			['logs', 'jobs', 'bans', 'overview'],
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
			'one' => _('User'),
			'switch' => _('Switch user'),
			'title' => ['text' => _('Users'), 'href' => self::listHref('users', $from, $scope !== null)],
			'mono' => true,
			'new' => _('New user'),
			'search' => _('Search users'),
			'none' => _('No user here'),
			'add' => ['text' => _('New user'), 'href' => '?display=oryk_provisioner&user='],
		]);
	}

	/**
	 * Devices: FreePBX's, all of them, or the ones the viewed row is linked to.
	 *
	 * None is written here, so there is no add row.
	 *
	 * @param array<int, string>|null $scope Device ids linked, or null for all.
	 * @param string|null             $at    Device being viewed, or null.
	 * @param string                  $from  scopeKey() of the viewed row, or ''.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function deviceLevel($scope, $at, $from)
	{
		$rows = [];

		foreach ($this->devices ? $this->devices->deviceChoices() : [] as $row) {
			$id = (string) $row['id'];

			$rows[] = [
				'id' => $id,
				'text' => $id,
				'note' => (string) (isset($row['description']) ? $row['description'] : ''),
				'href' => '?display=oryk_provisioner&device=' . rawurlencode($id),
			];
		}

		return $this->level($rows, $scope, $at, [
			'key' => 'device',
			'one' => _('Device'),
			'switch' => _('Switch device'),
			'title' => ['text' => _('Devices'), 'href' => self::listHref('devices', $from, $scope !== null)],
			'mono' => true,
			'new' => '',
			'search' => _('Search devices'),
			'none' => _('No devices here'),
			'add' => null,
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
				'touched' => $row['touched'] ?? null,
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
			'one' => _('Client'),
			'switch' => _('Switch client'),
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
	 * @param array<int, string>      $profileNames   Every profile's name, by id.
	 * @param array<int, string|null> $profileTouched When each was last written, by id.
	 * @param array<int, int>|null    $scope          Ids linked, or null for all.
	 * @param string|null          $at           Profile being viewed, 'new', or null.
	 * @param string               $from         scopeKey() of the viewed row, or ''.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function profileLevel(array $profileNames, array $profileTouched, $scope, $at, $from)
	{
		$rows = [];

		foreach ($profileNames as $id => $name) {
			$rows[] = [
				'id' => $id,
				'text' => $name,
				'note' => '',
				'href' => '?display=oryk_provisioner&profile=' . $id,
				'touched' => $profileTouched[$id] ?? null,
			];
		}

		return $this->level($rows, $scope, $at, [
			'key' => 'profile',
			'one' => _('Profile'),
			'switch' => _('Switch profile'),
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
	 * @param array<int, int>|null $profiles     Profiles whose files to list, or null for every profile's.
	 * @param string|null          $at           Resource being viewed, 'new', or null.
	 * @param string|null          $client       Client being viewed, or null.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function resourceLevel(array $profileNames, $profiles, $at, $client)
	{
		$rows = [];
		$empty = _('No profile here');
		// Unscoped is every profile's files, each noted with its profile, so
		// the level counts on a list page like every other.
		$profiles = $profiles === null ? array_keys($profileNames) : $profiles;

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
					'touched' => $row['touched'] ?? null,
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
			'one' => _('Resource'),
			'switch' => _('Switch resource'),
			'title' => ['text' => _('Resources'), 'href' => $list],
			'mono' => true,
			'new' => _('New resource'),
			'search' => _('Search resources'),
			'empty' => $empty,
			'add' => $one ? ['text' => _('New resource'), 'href' => '?display=oryk_provisioner&profile=' . $one . '&resource='] : null,
		]);
	}

	/**
	 * Services: all of them, or the ones the users in scope are assigned
	 * themselves. One a user has only through a pack is not listed. A service
	 * pack is badged "Pack".
	 *
	 * @param array<int, string>|null $scope Slugs assigned, or null for all.
	 * @param string|null             $at    Slug being viewed, 'new', or null.
	 * @param string|null             $user  Extension being viewed, or null.
	 * @param string                  $from  scopeKey() of the viewed row, or ''.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function serviceLevel($scope, $at, $user, $from)
	{
		$rows = [];
		$packs = $this->services->packs();

		foreach ($this->services->serviceChoices() as $row) {
			$slug = (string) $row['slug'];

			$rows[] = [
				'id' => $slug,
				'text' => (string) $row['name'],
				'note' => '',
				'badge' => in_array($slug, $packs, true) ? _('Pack') : '',
				'href' => '?display=oryk_provisioner&service=' . rawurlencode($slug),
				'touched' => $row['touched'] ?? null,
			];
		}

		// The list this level is drawn from: the viewed user's own Services tab.
		$list = $this->written($user)
			? '?display=oryk_provisioner&user=' . rawurlencode($user) . '&tab=services'
			: self::listHref('services', $from, $scope !== null);

		return $this->level($rows, $scope, $at, [
			'key' => 'service',
			'one' => _('Service'),
			'switch' => _('Switch service'),
			'title' => ['text' => _('Services'), 'href' => $list],
			'mono' => false,
			'new' => _('New service'),
			'search' => _('Search services'),
			'none' => _('No services here'),
			'add' => ['text' => _('New service'), 'href' => '?display=oryk_provisioner&service='],
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
				'touched' => (string) $row['created_at'],
			];
		}

		$list = self::listHref('logs', $from, $macs !== null || $ip !== null);

		// A client's Logs tab is drawn only for a client with a MAC.
		if ($this->written($client) && $macs) {
			$list = '?display=oryk_provisioner&client=' . (int) $client . '&tab=logs';
		}

		return $this->level($rows, null, $at, [
			'key' => 'log',
			'one' => _('Log entry'),
			'switch' => _('Switch log entry'),
			'title' => ['text' => _('Logs'), 'href' => $list],
			'mono' => true,
			'new' => '',
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
				// Last hit, last start or creation, whichever is latest.
				'touched' => max((string) ($row['last_hit_at'] ?? ''), (string) ($row['started_at'] ?? ''), (string) ($row['created_at'] ?? '')),
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
			'one' => _('Ban'),
			'switch' => _('Switch ban'),
			'title' => ['text' => _('Bans'), 'href' => self::listHref('bans', $from, $scope !== null)],
			'mono' => false,
			'new' => _('New ban'),
			'search' => _('Search bans'),
			'none' => _('No bans here'),
			'add' => ['text' => _('New ban'), 'href' => $add],
		]);
	}

	/**
	 * Jobs: the newest JOB_LIMIT in scope, each a page of its own.
	 *
	 * Already narrowed when read, so the level is never filtered again. On a
	 * job's page that job is listed even when it is older than the rest.
	 * Nothing writes a job from here, so there is no add row.
	 *
	 * @param array<string, array<int, string>>|null $narrow scope()'s `jobs`.
	 * @param string|null                            $at     Job being viewed, or null.
	 * @param string                                 $from   scopeKey() of the viewed row, or ''.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function jobLevel($narrow, $at, $from)
	{
		$found = $this->jobs ? $this->jobs->jobChoices($narrow, self::JOB_LIMIT) : [];
		$listed = array_map('strval', array_column($found, 'id'));

		if ($this->jobs && $this->written($at) && !in_array((string) $at, $listed, true)) {
			$viewed = $this->jobs->jobRow($at);

			if ($viewed) {
				$found[] = $viewed;
			}
		}

		$labels = Jobs::labels();
		$rows = [];

		foreach ($found as $row) {
			$rows[] = [
				'id' => (int) $row['id'],
				'text' => '#' . (int) $row['id'] . ' ' . Jobs::title($row),
				'note' => implode(' · ', [
					sprintf(_('user %s'), (string) $row['extension']),
					$labels['reason'][(string) $row['reason']] ?? (string) $row['reason'],
					$labels['state'][(string) $row['state']] ?? (string) $row['state'],
					(string) $row['created_at'],
				]),
				'href' => '?display=oryk_provisioner&job=' . (int) $row['id'],
				'touched' => (string) $row['created_at'],
			];
		}

		return $this->level($rows, null, $at, [
			'key' => 'job',
			'one' => _('Job'),
			'switch' => _('Switch job'),
			'title' => ['text' => _('Jobs'), 'href' => self::listHref('jobs', $from, $narrow !== null)],
			'mono' => false,
			'new' => '',
			'search' => _('Search jobs'),
			'empty' => $narrow !== null ? _('No jobs here') : _('Nothing here yet'),
			'add' => null,
		]);
	}

	/**
	 * One level, from every row of its kind and the scope it is narrowed to.
	 *
	 * Only the row `$at` names is the active one -- the row being viewed, or a
	 * parent of it (parents()). A scope of exactly one row is still a list of
	 * one, and is not chosen for you.
	 *
	 * @param array<int, array<string, mixed>> $rows  Each: id, text, note, href,
	 *                                                page where the row's
	 *                                                own page is not its href,
	 *                                                and badge where the option
	 *                                                carries one.
	 * @param array<int, mixed>|null           $scope Ids to keep, or null for all.
	 * @param string|null                      $at    Id chosen -- viewed, or a
	 *                                                parent of what is -- 'new', or null.
	 * @param array<string, mixed>             $meta  key, one (what a row of
	 *                                                the level is called, over
	 *                                                its crumb), switch (its
	 *                                                filter's prompt on a
	 *                                                crumb), title, mono,
	 *                                                new, search, add, and
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

			$active = $at === $id;

			$options[] = [
				'text' => $row['text'],
				'note' => $row['note'],
				'badge' => isset($row['badge']) ? (string) $row['badge'] : '',
				'href' => $row['href'],
				'active' => $active,
				// When the row was last written or seen, for trim(); null
				// where nothing records that.
				'touched' => isset($row['touched']) ? (string) $row['touched'] : null,
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
			'one' => $meta['one'],
			'title' => $meta['title'],
			'text' => $at === 'new' ? $meta['new'] : $text,
			// Where the chosen row is: what closing the crumb after it lands on; '' on a new one.
			'href' => $at === 'new' ? '' : $href,
			'mono' => $meta['mono'],
			'search' => $meta['search'],
			// What a crumb's filter asks instead: its menu is where you change
			// row, not where you look one up.
			'switch' => $meta['switch'],
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
