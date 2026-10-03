<?php

// src/Navigator.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Where you are, and everywhere you can go from here.
 *
 * Two halves. sections() is the module's top level, drawn as the bar over
 * every page by views/partials/sections.php. levels() is the row of dropdowns
 * views/partials/navigator.php draws under it: Users, Clients, Profiles and
 * Resources, always all four, always in that order -- who, which phone, what
 * configuration, which file.
 *
 * The four are linked: a user has clients, a client has one profile, a profile
 * has clients and files. The page being viewed sets the scope, and three rules
 * decide what each dropdown lists:
 *
 *   - a level lists only what is linked to the row being viewed;
 *   - the level *of* that row lists all of its kind, the row selected, so
 *     moving sideways is still one pick;
 *   - a linked level with exactly one row in it shows that row selected.
 *
 * Nothing viewed -- a list page, Logs, Settings, Bans -- scopes nothing, and a
 * new row has no links yet, so it scopes nothing either. Choosing an option is
 * a page load to that row, and the row of dropdowns re-scopes around it.
 *
 * Two keys are places rather than values, and are built here because only the
 * level knows what they cost:
 *
 *   - 'title': what this level is, plural, and the list it is drawn from -- the
 *     scoped one where there is one (a profile's Clients tab), else the module's.
 *     Its badge is 'count', the options the level lists, so it always agrees
 *     with the menu under it.
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

	/** @var Users */
	private $users;

	/** @var Bans */
	private $bans;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Clients $clients, Profiles $profiles, Resources $resources, Users $users, Bans $bans)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->profiles = $profiles;
		$this->resources = $resources;
		$this->users = $users;
		$this->bans = $bans;
	}

	/**
	 * The four dropdowns, scoped by the row a page is viewing.
	 *
	 * `$at` names that row: `user` (an extension), `client`, `profile`, or
	 * `profile` and `resource` together. Each is an id, or 'new' on a page
	 * writing one that does not exist yet. Empty on a page viewing no row.
	 *
	 * @param array<string, mixed> $at Row being viewed.
	 *
	 * @return array<int, array<string, mixed>> Users, clients, profiles, resources.
	 */
	public function levels(array $at = [])
	{
		$user = isset($at['user']) ? (string) $at['user'] : null;
		$client = isset($at['client']) ? (string) $at['client'] : null;
		$profile = isset($at['profile']) ? (string) $at['profile'] : null;
		$resource = isset($at['resource']) ? (string) $at['resource'] : null;

		$clientRows = $this->clients->clientChoices();
		$profileNames = [];

		foreach ($this->profiles->profileChoices() as $row) {
			$profileNames[(int) $row['id']] = (string) $row['name'];
		}

		// null is "not scoped": the level lists everything. An array is the ids
		// linked to the row being viewed, and may be empty.
		$scope = ['users' => null, 'clients' => null, 'profiles' => null, 'files' => null];

		if ($this->written($client)) {
			foreach ($clientRows as $row) {
				if ((string) $row['id'] === $client) {
					$scope['users'] = (string) $row['device_id'] !== '' ? [(string) $row['device_id']] : [];
					$scope['profiles'] = (int) $row['profile_id'] ? [(int) $row['profile_id']] : [];
					$scope['files'] = $scope['profiles'];
				}
			}
		} elseif ($this->written($user)) {
			$scope['clients'] = [];
			$scope['profiles'] = [];

			foreach ($clientRows as $row) {
				if ((string) $row['device_id'] === $user) {
					$scope['clients'][] = (int) $row['id'];

					if ((int) $row['profile_id']) {
						$scope['profiles'][] = (int) $row['profile_id'];
					}
				}
			}

			$scope['profiles'] = array_values(array_unique($scope['profiles']));
			$scope['files'] = $scope['profiles'];
		} elseif ($this->written($profile)) {
			$scope['clients'] = [];
			$scope['users'] = [];

			foreach ($clientRows as $row) {
				if ((int) $row['profile_id'] === (int) $profile) {
					$scope['clients'][] = (int) $row['id'];

					if ((string) $row['device_id'] !== '') {
						$scope['users'][] = (string) $row['device_id'];
					}
				}
			}

			$scope['users'] = array_values(array_unique($scope['users']));
			$scope['files'] = [(int) $profile];
		}

		return [
			$this->userLevel($scope['users'], $user),
			$this->clientLevel($clientRows, $scope['clients'], $client, $user, $profile),
			$this->profileLevel($profileNames, $scope['profiles'], $profile),
			$this->resourceLevel($profileNames, $scope['files'], $resource, $client),
		];
	}

	/**
	 * The module's own sections, for the bar over every page.
	 *
	 * Exactly one is active on every page: the branch the page is in, not the
	 * URL's `tab` -- a resource page is in Profiles.
	 *
	 * @param string $section clients|profiles|users|logs|bans|settings.
	 *
	 * @return array<int, array<string, mixed>> Each: key, text, href, active.
	 */
	public function sections($section)
	{
		// Users, Clients, Profiles in the order of the dropdowns under the bar.
		$sections = [
			'users' => _('Users'),
			'clients' => _('Clients'),
			'profiles' => _('Profiles'),
			'logs' => _('Logs'),
			'bans' => _('Bans'),
			'settings' => _('Settings'),
		];

		// Switched off on the Settings tab, Bans is not a section.
		if (!$this->bans->enabled()) {
			unset($sections['bans']);
		}

		$section = isset($sections[$section]) ? $section : 'users';
		$bar = [];

		foreach ($sections as $key => $text) {
			$bar[] = [
				'key' => $key,
				'text' => $text,
				'href' => '?display=oryk_provisioner&tab=' . $key,
				'active' => $key === $section,
			];
		}

		return $bar;
	}

	/**
	 * Users: all of them, or the ones whose phones the viewed row is linked to.
	 *
	 * @param array<int, string>|null $scope Extensions linked, or null for all.
	 * @param string|null             $at    Extension being viewed, 'new', or null.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function userLevel($scope, $at)
	{
		$rows = [];

		foreach ($this->users->userChoices() as $row) {
			$extension = (string) $row['extension'];

			$rows[] = [
				'id' => $extension,
				'text' => $extension,
				'note' => (string) (isset($row['name']) ? $row['name'] : ''),
				'href' => '?display=oryk_provisioner&user=' . rawurlencode($extension),
			];
		}

		return $this->level($rows, $scope, $at, [
			'key' => 'user',
			'title' => ['text' => _('Users'), 'href' => '?display=oryk_provisioner&tab=users'],
			'mono' => true,
			'new' => _('New user'),
			'prompt' => _('Select a user'),
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
	 *
	 * @return array<string, mixed> One level.
	 */
	private function clientLevel(array $clientRows, $scope, $at, $user, $profile)
	{
		$rows = [];

		foreach ($clientRows as $row) {
			$mac = (string) $row['mac'];

			$rows[] = [
				'id' => (int) $row['id'],
				'text' => $mac === '' ? '-' : $mac,
				'note' => (string) (isset($row['description']) ? $row['description'] : ''),
				'href' => '?display=oryk_provisioner&client=' . (int) $row['id'],
			];
		}

		// The list this level is drawn from: the viewed row's own Clients tab.
		$list = '?display=oryk_provisioner&tab=clients';
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
			'prompt' => _('Select a client'),
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
	 *
	 * @return array<string, mixed> One level.
	 */
	private function profileLevel(array $profileNames, $scope, $at)
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
			'title' => ['text' => _('Profiles'), 'href' => '?display=oryk_provisioner&tab=profiles'],
			'mono' => false,
			'new' => _('New profile'),
			'prompt' => _('Select a profile'),
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
		$prompt = $profiles === null ? _('Select a profile first') : _('No profile here');
		$profiles = (array) $profiles;

		if ($profiles) {
			$empty = _('Nothing here yet');
			$prompt = _('Select a resource');
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
	 * One level, from every row of its kind and the scope it is narrowed to.
	 *
	 * The row being viewed is the active one. A scope of exactly one row makes
	 * that row active too: it is the only thing this level can be, here.
	 *
	 * @param array<int, array<string, mixed>> $rows  Each: id, text, note, href.
	 * @param array<int, mixed>|null           $scope Ids to keep, or null for all.
	 * @param string|null                      $at    Id being viewed, 'new', or null.
	 * @param array<string, mixed>             $meta  key, title, mono, new,
	 *                                                prompt, search, add, and
	 *                                                none (what a scope with
	 *                                                nothing in it says) or
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
			'mono' => $meta['mono'],
			// A scope with nothing in it says so on the crumb, not only in its menu.
			'prompt' => (!$options && $keep !== null) ? $meta['none'] : $meta['prompt'],
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
