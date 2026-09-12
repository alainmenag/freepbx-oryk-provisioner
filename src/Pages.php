<?php

// src/Pages.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Which URL is which page.
 *
 * Everything the module edits is a page, told apart by which key the URL
 * carries, and every page is a tab strip over a tab content with its tab in
 * the address. A tab is a link, so a tab is a page too: only the pane asked
 * for is rendered, and the strip above it is links to the rest -- see
 * views/partials/tabs.php.
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

	/** @var Counts */
	private $counts;

	/** @var Navigator */
	private $navigator;

	/** @var LogRepo */
	private $logs;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Clients $clients, Profiles $profiles, Resources $resources, Freepbx $pbx, Template $template, ProvisioningLog $requestLog, Counts $counts, Navigator $navigator, LogRepo $logs)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->profiles = $profiles;
		$this->resources = $resources;
		$this->pbx = $pbx;
		$this->template = $template;
		$this->requestLog = $requestLog;
		$this->counts = $counts;
		$this->navigator = $navigator;
		$this->logs = $logs;
	}

	/**
	 * Render the requested module page.
	 *
	 * A list and three editors, told apart by which key the URL carries.
	 *
	 *   ?display=oryk_provisioner                            the list
	 *   ?display=oryk_provisioner&client=<id>                one client
	 *   ?display=oryk_provisioner&client=                    a new one
	 *   ?display=oryk_provisioner&profile=<id>               one profile
	 *   ?display=oryk_provisioner&profile=                   a new one
	 *   ?display=oryk_provisioner&profile=<id>&resource=<id> one of its files
	 *   ?display=oryk_provisioner&profile=<id>&resource=     a new one
	 *
	 * A key present but empty is the same page doing the same thing, minus a row
	 * to replace.
	 *
	 * @return string Rendered page output.
	 */
	public function showPage()
	{
		// Checked before ?profile= only because neither URL carries the other's
		// key: a client names its profile in a select, not in the address.
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

		return load_view(dirname(__DIR__) . '/views/profile.php', [
			'profile' => $profile,
			// A profile that has not been written is 'new' rather than an id: the
			// crumb has something to say about it and no row to point at.
			'navigator' => $this->navigator->levels('profiles', [
				'profile' => $profile['id'] ? (int) $profile['id'] : 'new',
			]),
			// Narrowed to this profile, new one included: profile_id=0 counts nothing
			// rather than being read as no narrowing at all.
			'counts' => $this->counts->pageCounts(['profile_id' => (int) $profile['id']]),
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
		$client = ['id' => 0, 'mac' => '', 'device_id' => '', 'profile_id' => 0, 'enabled' => 1];

		if ($wanted !== '') {
			$found = $this->clients->clientRow($wanted);

			// doConfigPageInit() has already sent an id that names nothing back to the
			// list, so this is reachable only if the client went in between.
			if (!$found) {
				return $this->showList('clients');
			}

			$client = $found;
		}

		$profileId = (int) ($client['profile_id'] ?? 0);
		$mac = (string) ($client['mac'] ?? '');
		$available = $this->clientTabs($client);

		return load_view(dirname(__DIR__) . '/views/client.php', [
			'client' => $client,
			'navigator' => $this->navigator->levels('clients', [
				'client' => $client['id'] ? (int) $client['id'] : 'new',
			]),
			'freepbxDevices' => $this->pbx->freepbxDevices(),
			'profiles' => $this->profiles->profileChoices(),
			// Two scopes on one page: Resources is the profile's, Logs is this MAC's.
			'counts' => $this->counts->pageCounts(['profile_id' => $profileId, 'mac' => $mac]),
			'available' => $available,
			'tab' => !empty($available[$tab]) ? $tab : 'client',
		]);
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

		return load_view(dirname(__DIR__) . '/views/resource.php', [
			'resource' => $resource,
			// Both levels, because a resource is reached through its profile and is
			// nothing without it.
			'navigator' => $this->navigator->levels('profiles', [
				'profile' => (int) $profile['id'],
				'resource' => $resource['id'] ? (int) $resource['id'] : 'new',
			]),
			'profile' => $profile,
			'placeholders' => $this->template->templatePlaceholders(),
			// Printed rather than described: where a log lands is the whole of what an
			// operator needs from that type, and it is read off this server's own
			// configuration.
			'logPath' => $this->logs->logPath(),
			'counts' => $this->counts->pageCounts(['profile_id' => (int) $profile['id']]),
			// A resource that has never been written has no name to render against a
			// client, so Clients is there but does not open.
			'tab' => ($tab === 'clients' && $resource['id']) ? 'clients' : 'resource',
		]);
	}

	/**
	 * Render the list page.
	 *
	 * All three tables are filled over AJAX and no row is edited here, so the view
	 * is handed which tab to open on and the counts its tabs are labelled with.
	 * Nothing arrives from a save: an editor's Save stays on the row it wrote, and
	 * only Close and a finished Delete come back here.
	 *
	 * @param string|null $tab Tab to open on, or null to take it from the request.
	 *
	 * @return string Rendered page output.
	 */
	private function showList($tab = null)
	{
		$tab = $tab === null ? (string) ($_REQUEST['tab'] ?? '') : $tab;
		$tab = in_array($tab, ['profiles', 'logs'], true) ? $tab : 'clients';

		return load_view(dirname(__DIR__) . '/views/admin.php', [
			'tab' => $tab,
			// Nothing above this page to narrow them by.
			'counts' => $this->counts->pageCounts(),
			// The section is the tab this page opens on, and nothing under it is open:
			// those levels are prompts, which is how somebody gets from here to a row
			// without reading the table first.
			'navigator' => $this->navigator->levels($tab),
		]);
	}

	/**
	 * Buttons FreePBX draws in the page header.
	 *
	 * Only the editors have any: the list's tabs carry their own controls, and a
	 * single button in the header could not say which tab it meant.
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
		// Which editor is open, and which of the URL's keys names the row its
		// buttons act on.
		if (isset($_REQUEST['client'])) {
			$row = trim((string) $_REQUEST['client']);
		} elseif (isset($_REQUEST['profile'])) {
			// On a resource page it is the resource Save and Delete act on; the
			// profile in the URL is only what it hangs off.
			$row = isset($_REQUEST['resource'])
				? trim((string) $_REQUEST['resource'])
				: trim((string) $_REQUEST['profile']);
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
