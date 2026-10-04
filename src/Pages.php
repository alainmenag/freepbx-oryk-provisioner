<?php

// src/Pages.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Which URL is which page.
 *
 * Everything the module edits is a page, told apart by which key the URL
 * carries. Every page is topped by the section bar and the navigator under it;
 * a row's page adds a tab strip with its tab in the address. A tab is a link,
 * so a tab is a page too: only the pane asked for is rendered, and the strip
 * above it is links to the rest -- see views/partials/tabs.php.
 *
 * doConfigPageInit() sends an id that names no row back to the list, before
 * any markup: a redirect out of showPage() would be too late to set a header.
 */
class Pages extends Service
{
	/** @var Clients */
	private $clients;

	/** @var Profiles */
	private $profiles;

	/** @var Resources */
	private $resources;

	/** @var Freepbx */
	private $pbx;

	/** @var Template */
	private $template;

	/** @var ProvisioningLog */
	private $requestLog;

	/** @var Navigator */
	private $navigator;

	/** @var LogRepo */
	private $logs;

	/** @var Users */
	private $users;

	/** @var EndpointSettings */
	private $endpoints;

	/** @var Settings */
	private $settings;

	/** @var Bans */
	private $bans;

	/** @var Fail2ban */
	private $fail2ban;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Clients $clients, Profiles $profiles, Resources $resources, Freepbx $pbx, Template $template, ProvisioningLog $requestLog, Navigator $navigator, LogRepo $logs, Users $users, EndpointSettings $endpoints, Settings $settings, Bans $bans, Fail2ban $fail2ban)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->profiles = $profiles;
		$this->resources = $resources;
		$this->pbx = $pbx;
		$this->template = $template;
		$this->requestLog = $requestLog;
		$this->navigator = $navigator;
		$this->logs = $logs;
		$this->users = $users;
		$this->endpoints = $endpoints;
		$this->settings = $settings;
		$this->bans = $bans;
		$this->fail2ban = $fail2ban;
	}

	/**
	 * Render the requested module page.
	 *
	 * A list, five editors and a log entry, told apart by which key the URL carries.
	 *
	 *   ?display=oryk_provisioner                            the list
	 *   ?display=oryk_provisioner&tab=<section>&scope=<kind>:<id>
	 *                                                        the list, narrowed
	 *                                                        to what that row scopes
	 *   ?display=oryk_provisioner&client=<id>                one client
	 *   ?display=oryk_provisioner&client=                    a new one
	 *   ?display=oryk_provisioner&profile=<id>               one profile
	 *   ?display=oryk_provisioner&profile=                   a new one
	 *   ?display=oryk_provisioner&profile=<id>&resource=<id> one of its files
	 *   ?display=oryk_provisioner&profile=<id>&resource=     a new one
	 *   ?display=oryk_provisioner&user=<extension>           one user
	 *   ?display=oryk_provisioner&user=                      a new one
	 *   ?display=oryk_provisioner&ban=<id>                   one ban
	 *   ?display=oryk_provisioner&ban=                       a new one
	 *   ?display=oryk_provisioner&log=<id>                   one log entry
	 *
	 * A key present but empty is the same page doing the same thing, minus a row
	 * to replace.
	 *
	 * @return string Rendered page output.
	 */
	public function showPage()
	{
		if (isset($_REQUEST['log'])) {
			return $this->showLog(trim((string) $_REQUEST['log']));
		}

		if (isset($_REQUEST['ban'])) {
			return $this->showBan(trim((string) $_REQUEST['ban']));
		}

		// Checked before ?profile= only because neither URL carries the other's
		// key: a client names its profile in a select, not in the address.
		if (isset($_REQUEST['user'])) {
			return $this->showUser(trim((string) $_REQUEST['user']), (string) ($_REQUEST['tab'] ?? ''));
		}

		if (isset($_REQUEST['client'])) {
			return $this->showClient(trim((string) $_REQUEST['client']), (string) ($_REQUEST['tab'] ?? ''));
		}

		if (!isset($_REQUEST['profile'])) {
			return $this->showList();
		}

		$wanted = trim((string) $_REQUEST['profile']);
		$profile = ['id' => 0, 'name' => '', 'enabled' => 1];

		if ($wanted !== '') {
			$found = $this->profiles->profileRow($wanted);

			// doConfigPageInit() has already sent an id that names nothing back to the
			// list, so this is reachable only if the profile went in between.
			if (!$found) {
				return $this->showList('profiles');
			}

			$profile = $found;
		}

		$tab = (string) ($_REQUEST['tab'] ?? '');

		// The same shape ?profile= has, one level down. A resource carries a block
		// of configuration text that wants room and a monospace column, so it gets
		// a page rather than a dialog on the profile's Resources tab.
		if ($profile['id'] && isset($_REQUEST['resource'])) {
			$page = $this->showResource($profile, trim((string) $_REQUEST['resource']), $tab);

			if ($page !== null) {
				return $page;
			}

			// The resource went between doConfigPageInit()'s check and here.
			$tab = 'resources';
		}

		// Resources and Clients both hang off a profile that has been written, so
		// a new one opens on Profile whichever tab is asked for.
		$tabs = ['resources', 'clients'];

		return $this->view('profile', [
			'profile' => $profile,
			'sections' => $this->navigator->sections('profiles'),
			// A profile that has not been written is 'new' rather than an id: it has
			// no links yet, so nothing else is scoped by it.
			'navigator' => $this->navigator->levels([
				'profile' => $profile['id'] ? (int) $profile['id'] : 'new',
			]),
			'tab' => (in_array($tab, $tabs, true) && $profile['id']) ? $tab : 'profile',
		]);
	}

	/**
	 * Render the client editor.
	 *
	 * What both selects offer is rendered with the page rather than fetched: the
	 * page is already waiting on the module for the client itself.
	 *
	 * Resources is the other end of the resource editor's Clients tab -- the files
	 * this client's profile serves, each with the filename *this* phone asks for,
	 * listed where somebody debugging a phone is already looking.
	 *
	 * @param string $wanted Client id, or '' for a new one.
	 * @param string $tab    Tab to open on: client|resources.
	 *
	 * @return string Rendered page output.
	 */
	private function showClient($wanted, $tab = '')
	{
		// A new client can arrive with its device already chosen -- the user
		// editor's Clients tab links here with it.
		$client = ['id' => 0, 'mac' => '', 'device_id' => trim((string) ($_REQUEST['device_id'] ?? '')), 'profile_id' => 0, 'enabled' => 1];

		if ($wanted !== '') {
			$found = $this->clients->clientRow($wanted);

			// doConfigPageInit() has already sent an id that names nothing back to the
			// list, so this is reachable only if the client went in between.
			if (!$found) {
				return $this->showList('clients');
			}

			$client = $found;
		}

		$available = $this->clientTabs($client);

		return $this->view('client', [
			'client' => $client,
			'sections' => $this->navigator->sections('clients'),
			'navigator' => $this->navigator->levels([
				'client' => $client['id'] ? (int) $client['id'] : 'new',
			]),
			'freepbxDevices' => $this->pbx->freepbxDevices(),
			'profiles' => $this->profiles->profileChoices(),
			'available' => $available,
			'tab' => !empty($available[$tab]) ? $tab : 'client',
		]);
	}

	/**
	 * Render the user editor.
	 *
	 * Keyed by extension rather than by an id of this module's: a user is a
	 * Core device, and a renumbering save moves it to a new address.
	 *
	 * @param string $wanted Extension, or '' for a new one.
	 * @param string $tab    Tab to open on: user|clients.
	 *
	 * @return string Rendered page output.
	 */
	private function showUser($wanted, $tab = '')
	{
		$user = ['extension' => '', 'name' => '', 'email' => '', 'from_domain' => '', 'secure' => 1, 'clients' => 0];

		if ($wanted !== '') {
			$found = $this->users->userRow($wanted);

			// doConfigPageInit() has already bounced a number that is no user.
			if (!$found) {
				return $this->showList('users');
			}

			$user = $found;
		}

		$extension = (string) $user['extension'];
		$available = $this->userTabs($user);

		return $this->view('user', [
			'user' => $user,
			'sections' => $this->navigator->sections('users'),
			'navigator' => $this->navigator->levels([
				'user' => $extension !== '' ? $extension : 'new',
			]),
			// Blank on the form is not nothing: it is this.
			'pbxDomain' => $this->endpoints->fromDomain(null),
			'available' => $available,
			'tab' => !empty($available[$tab]) ? $tab : 'user',
			// What the context reads as, and whether Promote is offered.
			'lobbyContext' => $this->users->lobbyContext(),
		]);
	}

	/**
	 * Render the ban editor.
	 *
	 * A new one may arrive with its subjects already filled in, as `&ban_ip=`,
	 * `&ban_mac=`, `&ban_user=`, `&ban_client=` and `&ban_profile=`.
	 *
	 * @param string $wanted Ban id, or '' for a new one.
	 *
	 * @return string Rendered page output.
	 */
	private function showBan($wanted)
	{
		$ban = ['id' => 0, 'state' => 'banned', 'note' => '', 'expires_in' => null];

		foreach (Bans::SUBJECTS as $subject => $column) {
			$ban[$column] = Bans::value($subject, $_REQUEST['ban_' . $subject] ?? '');
		}

		if ($wanted !== '') {
			// doConfigPageInit() has already bounced one that has gone.
			$found = $this->bans->banRow($wanted);

			if (!$found) {
				return $this->showList('bans');
			}

			$ban = $found;
		}

		return $this->view('ban', [
			'ban' => $ban,
			'users' => $this->users->userChoices(),
			'clients' => $this->clients->clientChoices(),
			'profiles' => $this->profiles->profileChoices(),
			'addresses' => $this->bans->clientAddresses(),
			'remote' => (string) Bans::canonical($_SERVER['REMOTE_ADDR'] ?? ''),
			'sections' => $this->navigator->sections('bans'),
			// An unwritten ban names nothing yet, so it scopes nothing.
			'navigator' => $this->navigator->levels([
				'ban' => $ban['id'] ? (int) $ban['id'] : 'new',
			]),
		]);
	}

	/**
	 * Render one provisioning log entry. There is no new one: the endpoint is
	 * the only thing that writes them.
	 *
	 * @param string $wanted Entry id.
	 *
	 * @return string Rendered page output.
	 */
	private function showLog($wanted)
	{
		// doConfigPageInit() has already bounced one that has gone.
		$entry = $this->requestLog->logRow($wanted);

		if (!$entry) {
			return $this->showList('logs');
		}

		return $this->view('log', [
			'entry' => $entry,
			'sections' => $this->navigator->sections('logs'),
			'navigator' => $this->navigator->levels(['log' => (int) $entry['id']]),
		]);
	}

	/**
	 * Which of a user's tabs have anything behind them. Shared with
	 * getActionBar(), which has to agree with the pane rendered.
	 *
	 * @param array<string, mixed> $user The user row.
	 *
	 * @return array<string, bool> Keyed by tab.
	 */
	private function userTabs(array $user)
	{
		return [
			'clients' => (string) ($user['extension'] ?? '') !== '',
		];
	}

	/**
	 * Which of a client's tabs have anything behind them.
	 *
	 * Resources needs a profile -- nothing is served to a client without one. Logs
	 * needs only a MAC to have been asked about, and deliberately not the profile:
	 * a client with no profile is exactly the one whose phone is being refused,
	 * and those refusals are what its log is made of.
	 *
	 * Here rather than in showClient() because getActionBar() asks the same
	 * question, and the buttons have to agree with the pane that was rendered.
	 *
	 * @param array<string, mixed> $client The client row.
	 *
	 * @return array<string, bool> Keyed by tab.
	 */
	private function clientTabs(array $client)
	{
		return [
			'resources' => (bool) ($client['id'] && (int) ($client['profile_id'] ?? 0)),
			'logs' => (bool) ($client['id'] && (string) ($client['mac'] ?? '') !== ''),
		];
	}

	/**
	 * Which tab the request actually opens on.
	 *
	 * `?tab=` is what was asked for; this is what the page will render. Every
	 * editor falls back to its first tab when the one asked for has nothing behind
	 * it yet. An empty string is that first tab, which is the bare URL of the row
	 * and the only tab with fields on it.
	 *
	 * @return string Tab the page opens on, or '' for the editor's own.
	 */
	private function openTab()
	{
		$tab = trim((string) ($_REQUEST['tab'] ?? ''));

		if ($tab === '') {
			return '';
		}

		// A ban has one tab.
		if (isset($_REQUEST['ban'])) {
			return '';
		}

		if (isset($_REQUEST['user'])) {
			$wanted = trim((string) $_REQUEST['user']);
			$user = $wanted === '' ? null : $this->users->userRow($wanted);
			$available = $user ? $this->userTabs($user) : [];

			return !empty($available[$tab]) ? $tab : '';
		}

		if (isset($_REQUEST['client'])) {
			$wanted = trim((string) $_REQUEST['client']);
			$client = $wanted === '' ? null : $this->clients->clientRow($wanted);
			$available = $client ? $this->clientTabs($client) : [];

			return !empty($available[$tab]) ? $tab : '';
		}

		if (!isset($_REQUEST['profile'])) {
			return $tab;
		}

		// Both of a profile's other tabs, and a resource's one, hang off a row
		// that has been written; on a new one the editor opens on itself.
		if (trim((string) $_REQUEST['profile']) === '') {
			return '';
		}

		if (isset($_REQUEST['resource'])) {
			return ($tab === 'clients' && trim((string) $_REQUEST['resource']) !== '') ? $tab : '';
		}

		return in_array($tab, ['resources', 'clients'], true) ? $tab : '';
	}

	/**
	 * Render the resource editor.
	 *
	 * The same page as the profile editor in everything but which two columns it
	 * is bound to, which is why both are one view apiece over a shared partial
	 * rather than one view with a mode flag.
	 *
	 * Clients is the profile's own client list widened by one column: which
	 * filename each of them asks *this* resource for. A resource's name is a
	 * template, so that filename is a different string per client.
	 *
	 * @param array<string, mixed> $profile Profile the resource belongs to.
	 * @param string               $wanted  Resource id, or '' for a new one.
	 * @param string               $tab     Tab to open on: resource|clients.
	 *
	 * @return string|null Rendered page, or null when the id names nothing.
	 */
	private function showResource(array $profile, $wanted, $tab = '')
	{
		$resource = [
			'id' => 0,
			'profile_id' => (int) $profile['id'],
			'name' => '',
			'type' => 'template',
			'template' => '',
		];

		if ($wanted !== '') {
			$found = $this->resources->resourceRow($wanted, (int) $profile['id']);

			if (!$found) {
				return null;
			}

			$resource = $found;
		}

		return $this->view('resource', [
			'resource' => $resource,
			'sections' => $this->navigator->sections('profiles'),
			// Both, because a resource is viewed within its profile: that profile
			// scopes the rest, and both are selected.
			'navigator' => $this->navigator->levels([
				'profile' => (int) $profile['id'],
				'resource' => $resource['id'] ? (int) $resource['id'] : 'new',
			]),
			'profile' => $profile,
			'placeholders' => $this->template->templatePlaceholders(),
			// Printed rather than described: where a log lands is the whole of what an
			// operator needs from that type, and it is read off this server's own
			// configuration.
			'logPath' => $this->logs->logPath(),
			// A resource that has never been written has no name to render against a
			// client, so Clients is there but does not open.
			'tab' => ($tab === 'clients' && $resource['id']) ? 'clients' : 'resource',
		]);
	}

	/**
	 * Render the list page.
	 *
	 * Every table is filled over AJAX and no row is edited here, so the view is
	 * handed which section to open on -- and, on Settings, the module's
	 * settings, which are fields rather than a table.
	 * Nothing arrives from a save: an editor's Save stays on the row it wrote, and
	 * only Close and a finished Delete come back here.
	 *
	 * @param string|null $tab Tab to open on, or null to take it from the request.
	 *
	 * @return string Rendered page output.
	 */
	private function showList($tab = null)
	{
		$tab = $this->navigator->section($tab === null ? (string) ($_REQUEST['tab'] ?? '') : $tab);
		$at = Navigator::scopeAt((string) ($_REQUEST['scope'] ?? ''));
		$navigator = $this->navigator->levels($at);
		$scope = $this->scopeBanner($tab, $at, $navigator);

		// A scope naming no row, or not narrowing this list, is dropped, and the
		// page is the whole list: nothing on it says otherwise.
		if (!$scope && $at) {
			$navigator = $this->navigator->levels();
		}

		return $this->view('admin', [
			'tab' => $tab,
			'settings' => $tab === 'settings'
				? $this->settings->fields([
					Settings::FROM_DOMAIN => $this->endpoints->hostname(),
					Settings::BAN_DENY_AFTER => _('off'),
					Settings::OPEN_EMERGENCY_CID => _('the extension'),
				])
				: [],
			// Whether the Users table offers Expired: Lobby Expiry is on.
			'expireDays' => $tab === 'users' ? (int) $this->settings->get(Settings::OPEN_EXPIRE_DAYS) : 0,
			'lobbyContext' => $this->users->lobbyContext(),
			// The fail2ban sync's one line under the Bans table.
			'sync' => $tab === 'bans' ? $this->fail2ban->status() + ['command' => $this->fail2ban->setupCommand()] : [],
			// What the State column warns with before refusing your own address.
			'remote' => (string) Bans::canonical($_SERVER['REMOTE_ADDR'] ?? ''),
			'sections' => $this->navigator->sections($tab),
			// Opened from a navigator title (`&scope=`), the dropdowns are scoped
			// as they were on the row it names, so the context comes along. Else
			// nothing is viewed and every dropdown lists all of its kind.
			'navigator' => $navigator,
			'scope' => $scope,
		]);
	}

	/**
	 * What a list narrowed by `&scope=` says it is narrowed to, or null when it is not.
	 *
	 * @param string                           $tab       Section shown.
	 * @param array<string, string>            $at        Navigator::scopeAt().
	 * @param array<int, array<string, mixed>> $navigator levels($at).
	 *
	 * @return array<string, string>|null key (the `&scope=` value), text (the
	 *                                    row, named), href (its page), all
	 *                                    (the list unnarrowed).
	 */
	private function scopeBanner($tab, array $at, array $navigator)
	{
		$key = $this->navigator->scopeKey($at);

		if ($key === '' || !Navigator::narrows($tab, $this->navigator->scope($at))) {
			return null;
		}

		$kind = (string) key($at);
		$names = [
			'user' => _('user %s'),
			'client' => _('client %s'),
			'profile' => _('profile %s'),
			'log' => _('log entry %s'),
			'ban' => _('ban %s'),
		];

		foreach ($navigator as $level) {
			// The row's own level has it chosen; one with nothing chosen names no row.
			if ($level['key'] === $kind && (string) $level['text'] !== '') {
				return [
					'key' => $key,
					'text' => sprintf($names[$kind], (string) $level['text']),
					'href' => (string) $level['href'],
					'all' => Navigator::listHref($tab, ''),
				];
			}
		}

		return null;
	}

	/**
	 * Render one view, with what every page's section bar needs added.
	 *
	 * @param string               $name View under views/, without `.php`.
	 * @param array<string, mixed> $vars What that view is handed.
	 *
	 * @return string Rendered page output.
	 */
	private function view($name, array $vars)
	{
		return $this->stylesheet() . load_view(dirname(__DIR__) . '/views/' . $name . '.php', $vars + ['version' => $this->version()]);
	}

	/**
	 * The module's stylesheet, assets/oryk_provisioner.css, for the top of a page.
	 *
	 * Linked through the admin/assets/oryk_provisioner symlink `fwconsole reload`
	 * makes, versioned by the file's mtime so an edit is never served from cache.
	 * Before that symlink exists it is inlined instead, so a module copied up
	 * and not yet reloaded is still styled.
	 *
	 * @return string A <link>, a <style>, or '' when the file is missing.
	 */
	private function stylesheet()
	{
		$file = dirname(__DIR__) . '/assets/oryk_provisioner.css';

		if (!is_file($file)) {
			return '';
		}

		// admin/modules/oryk_provisioner/assets -> admin/assets/oryk_provisioner.
		if (is_dir(dirname(__DIR__, 3) . '/assets/oryk_provisioner')) {
			return '<link rel="stylesheet" type="text/css" href="assets/oryk_provisioner/oryk_provisioner.css?v=' . (int) filemtime($file) . '">';
		}

		return '<style>' . file_get_contents($file) . '</style>';
	}

	/**
	 * The module's version, read off module.xml.
	 *
	 * The code on disk rather than what FreePBX last installed: after files are
	 * copied up and before an upgrade is run, this is the one actually serving.
	 *
	 * @return string Version, or '' when module.xml cannot be read.
	 */
	private function version()
	{
		$xml = @simplexml_load_file(dirname(__DIR__) . '/module.xml');

		return $xml && isset($xml->version) ? trim((string) $xml->version) : '';
	}

	/**
	 * Buttons FreePBX draws in the page header.
	 *
	 * Only the editors, a log entry and the Settings tab have any: the list's other tabs
	 * carry their own controls, and a single button in the header could not say
	 * which tab it meant.
	 *
	 * Deliberately not the usual submit/delete names -- core wires those to a
	 * `form.fpbx-submit`, and none of these pages has a form. These are ours, and
	 * views/partials/editor.php binds them.
	 *
	 * Save is drawn only on the editor's own tab: a tab is a link, so on any other
	 * tab the fields Save posts are not on the page, and a Save that posted a form
	 * that is not there would write empty strings over the row. Delete and Close
	 * act on the row rather than the fields, and the row's id is printed into
	 * every one of its tabs.
	 *
	 * @param string $request Current page request.
	 *
	 * @return array<string, array<string, string>> Action bar buttons.
	 */
	public function getActionBar($request)
	{
		// A log entry is read, not edited: Delete and Close, never Save.
		if (isset($_REQUEST['log'])) {
			return [
				'orykdelete' => [
					'name' => 'orykdelete',
					'id' => 'orykdelete',
					'value' => _('Delete'),
				],
				'orykclose' => [
					'name' => 'orykclose',
					'id' => 'orykclose',
					'value' => _('Close'),
				],
			];
		}

		// Which editor is open, and which of the URL's keys names the row its
		// buttons act on.
		if (isset($_REQUEST['ban'])) {
			$row = trim((string) $_REQUEST['ban']);
		} elseif (isset($_REQUEST['user'])) {
			$row = trim((string) $_REQUEST['user']);
		} elseif (isset($_REQUEST['client'])) {
			$row = trim((string) $_REQUEST['client']);
		} elseif (isset($_REQUEST['profile'])) {
			// On a resource page it is the resource Save and Delete act on; the
			// profile in the URL is only what it hangs off.
			$row = isset($_REQUEST['resource'])
				? trim((string) $_REQUEST['resource'])
				: trim((string) $_REQUEST['profile']);
		} elseif (($_REQUEST['tab'] ?? '') === 'settings') {
			// Fields, but no row: nothing to delete, and nowhere to close to.
			return [
				'oryksave' => [
					'name' => 'oryksave',
					'id' => 'oryksave',
					'value' => _('Save'),
				],
			];
		} else {
			return [];
		}

		$bar = [];

		if ($this->openTab() === '') {
			$bar['oryksave'] = [
				'name' => 'oryksave',
				'id' => 'oryksave',
				'value' => _('Save'),
			];
		}

		// Nothing to delete until there is a row.
		if ($row !== '') {
			$bar['orykdelete'] = [
				'name' => 'orykdelete',
				'id' => 'orykdelete',
				'value' => _('Delete'),
			];
		}

		$bar['orykclose'] = [
			'name' => 'orykclose',
			'id' => 'orykclose',
			'value' => _('Close'),
		];

		return $bar;
	}

	/**
	 * Initialise the module configuration page.
	 *
	 * An id that names no row is a stale link or a hand-edited URL: opening an
	 * editor on it would bind the fields to something that cannot be saved back.
	 * It is sent to the list instead, here rather than in showPage() because this
	 * runs before any of the page has been written.
	 *
	 * @param string $page Current configuration page.
	 *
	 * @return void
	 */
	public function doConfigPageInit($page)
	{
		// There is no new entry, so empty is as stale as an id that has gone.
		if (isset($_REQUEST['log'])) {
			if (!$this->requestLog->logRow(trim((string) $_REQUEST['log']))) {
				header('Location: config.php?display=oryk_provisioner&tab=logs');
				exit;
			}

			return;
		}

		// Empty is the new-ban editor. An expired ban is still a row and opens.
		if (isset($_REQUEST['ban'])) {
			$ban = trim((string) $_REQUEST['ban']);

			if ($ban !== '' && !$this->bans->banRow($ban)) {
				header('Location: config.php?display=oryk_provisioner&tab=bans');
				exit;
			}

			return;
		}

		// Empty is the new-user editor. userRow() refuses anything but digits.
		if (isset($_REQUEST['user'])) {
			$user = trim((string) $_REQUEST['user']);

			if ($user !== '' && !$this->users->userRow($user)) {
				header('Location: config.php?display=oryk_provisioner&tab=users');
				exit;
			}

			return;
		}

		// Empty is the new-client editor, not a lookup that failed.
		if (isset($_REQUEST['client'])) {
			$client = trim((string) $_REQUEST['client']);

			if ($client !== '' && (!ctype_digit($client) || !$this->clients->clientRow($client))) {
				header('Location: config.php?display=oryk_provisioner&tab=clients');
				exit;
			}

			return;
		}

		if (!isset($_REQUEST['profile'])) {
			return;
		}

		$wanted = trim((string) $_REQUEST['profile']);

		// Empty is the new-profile editor. A resource has nothing to hang off
		// until the profile has been written, so ?profile=&resource= is sent back
		// to the profile.
		if ($wanted === '') {
			if (isset($_REQUEST['resource'])) {
				header('Location: config.php?display=oryk_provisioner&profile=');
				exit;
			}

			return;
		}

		if (!ctype_digit($wanted) || !$this->profiles->profileExists((int) $wanted)) {
			header('Location: config.php?display=oryk_provisioner&tab=profiles');
			exit;
		}

		if (!isset($_REQUEST['resource'])) {
			return;
		}

		$resource = trim((string) $_REQUEST['resource']);

		// Empty is the new-resource editor. Anything else has to name a resource
		// of *this* profile: an id belonging to another is as much a stale link as
		// one belonging to nothing.
		if ($resource === '') {
			return;
		}

		if (!ctype_digit($resource) || !$this->resources->resourceRow($resource, (int) $wanted)) {
			header('Location: config.php?display=oryk_provisioner&profile=' . (int) $wanted . '&tab=resources');
			exit;
		}
	}
}
