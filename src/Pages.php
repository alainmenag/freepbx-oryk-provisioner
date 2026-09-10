<?php

// src/Pages.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Which URL is which page.
 *
 * Everything the module edits is a page, told apart by which key the URL
 * carries, and every page is a tab strip over a tab content with its tab
 * in the address. doConfigPageInit() is where an id that names no row is
 * sent back to the list -- before any markup, because a redirect out of
 * showPage() would be too late to set a header.
 */
class Pages extends Service
{
	/**
	 * @var Clients
	 */
	private $clients;

	/**
	 * @var Profiles
	 */
	private $profiles;

	/**
	 * @var Resources
	 */
	private $resources;

	/**
	 * @var Freepbx
	 */
	private $pbx;

	/**
	 * @var Template
	 */
	private $template;

	/**
	 * @var ProvisioningLog
	 */
	private $requestLog;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Clients $clients, Profiles $profiles, Resources $resources, Freepbx $pbx, Template $template, ProvisioningLog $requestLog)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->profiles = $profiles;
		$this->resources = $resources;
		$this->pbx = $pbx;
		$this->template = $template;
		$this->requestLog = $requestLog;
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
	 * A key present but empty is deliberate rather than a degenerate case: it
	 * is the same page doing the same thing, minus a row to replace.
	 *
	 * Everything the module edits is a page. A client was a dialog on
	 * the list until 1.0.6 -- three short fields do fit in one -- but a dialog
	 * has no address, so nothing could link to a client, and the profile
	 * editor's Clients tab had to send an id back to the list and have JS
	 * re-open the dialog on arrival. One way in, addressable, like the rest.
	 *
	 * @return string Rendered page output.
	 */
	public function showPage()
	{
		// Checked before ?profile= only because neither URL carries the
		// other's key: a client names its profile in a select, not in
		// the address.
		if (isset($_REQUEST['client'])) {
			return $this->showClient(trim((string) $_REQUEST['client']), (string) ($_REQUEST['tab'] ?? ''));
		}

		if (!isset($_REQUEST['profile'])) {
			return $this->showList();
		}

		$wanted = trim((string) $_REQUEST['profile']);
		$profile = ['id' => 0, 'name' => ''];

		if ($wanted !== '') {
			$found = $this->profiles->profileRow($wanted);

			// doConfigPageInit() has already sent an id that names nothing
			// back to the list, so this is only reachable if the profile went
			// between that check and here; the list is where it is not.
			if (!$found) {
				return $this->showList('profiles');
			}

			$profile = $found;
		}

		$tab = (string) ($_REQUEST['tab'] ?? '');

		// ?profile=<id>&resource=<id> is one of that profile's resources, and
		// ?profile=<id>&resource= is a new one -- the same shape ?profile= has
		// itself, one level down. A resource carries a block of configuration
		// text that wants room and a monospace column, so it gets a page
		// rather than a dialog on the profile's Resources tab. Since the
		// profile stopped carrying text of its own, this is the only page in
		// the module with a template box on it.
		if ($profile['id'] && isset($_REQUEST['resource'])) {
			$page = $this->showResource($profile, trim((string) $_REQUEST['resource']), $tab);

			if ($page !== null) {
				return $page;
			}

			// The resource went between doConfigPageInit()'s check and here.
			// The profile it would have belonged to is where it is not.
			$tab = 'resources';
		}

		// Resources and Clients both hang off a profile that has been
		// written, so a new one opens on Profile whichever tab is asked for.
		$tabs = ['resources', 'clients'];

		return load_view(dirname(__DIR__) . '/views/profile.php', [
			'profile' => $profile,
			'assigned' => $profile['id'] ? $this->profiles->profileClientCount((int) $profile['id']) : 0,
			'tab' => (in_array($tab, $tabs, true) && $profile['id']) ? $tab : 'profile',
			'saved' => (int) ($_REQUEST['saved'] ?? 0),
		]);
	}

	/**
	 * Render the client editor.
	 *
	 * What both selects offer is rendered with the page rather than fetched:
	 * it is a list of FreePBX devices and a list of profiles, and the page is
	 * already waiting on the module for the client itself.
	 *
	 * Resources is the other end of the resource editor's Clients tab: the
	 * files this client's profile serves, each with the filename *this* phone
	 * asks for. One client's provisioning, listed where the client
	 * is -- which is where somebody debugging a phone is already looking.
	 *
	 * @param string $wanted Client id, or '' for a new one.
	 * @param string $tab    Tab to open on: client|resources.
	 *
	 * @return string Rendered page output.
	 */
	private function showClient($wanted, $tab = '')
	{
		$client = ['id' => 0, 'mac' => '', 'device_id' => '', 'profile_id' => 0];

		if ($wanted !== '') {
			$found = $this->clients->clientRow($wanted);

			// doConfigPageInit() has already sent an id that names nothing
			// back to the list, so this is only reachable if the client
			// went between that check and here; the list is where it is not.
			if (!$found) {
				return $this->showList('clients');
			}

			$client = $found;
		}

		$profileId = (int) ($client['profile_id'] ?? 0);
		$mac = (string) ($client['mac'] ?? '');

		// What each tab needs before it has anything on it. Resources needs a
		// profile behind it -- nothing is served to a client without one. Logs
		// needs only a MAC to have been asked about, and deliberately does not
		// need the profile: a client with no profile is exactly the one whose
		// phone is being refused, and those refusals are what its log is made
		// of.
		$available = [
			'resources' => (bool) ($client['id'] && $profileId),
			'logs' => (bool) ($client['id'] && $mac !== ''),
		];

		return load_view(dirname(__DIR__) . '/views/client.php', [
			'client' => $client,
			'freepbxDevices' => $this->pbx->freepbxDevices(),
			'profiles' => $this->profiles->profileChoices(),
			'resources' => $profileId ? $this->profiles->profileResourceCount($profileId) : 0,
			'logs' => $mac !== '' ? $this->requestLog->macLogCount($mac) : 0,
			'available' => $available,
			'tab' => !empty($available[$tab]) ? $tab : 'client',
		]);
	}

	/**
	 * Render the resource editor.
	 *
	 * The same page as the profile editor in everything but which two columns
	 * it is bound to, which is why both are one view apiece over a shared
	 * partial rather than one view with a mode flag.
	 *
	 * Clients is the profile's own client list, narrowed no further and
	 * widened by one column: which filename each of them asks *this* resource
	 * for. A resource's name is a template, so that filename is a different
	 * string per client -- which is exactly why there was no per-resource
	 * preview until there was a per-client row to hang one on.
	 *
	 * @param array<string, mixed> $profile Profile the resource belongs to.
	 * @param string               $wanted  Resource id, or '' for a new one.
	 * @param string               $tab     Tab to open on: resource|clients.
	 *
	 * @return string|null Rendered page, or null when the id names nothing.
	 */
	private function showResource(array $profile, $wanted, $tab = '')
	{
		$resource = ['id' => 0, 'profile_id' => (int) $profile['id'], 'name' => '', 'template' => ''];

		if ($wanted !== '') {
			$found = $this->resources->resourceRow($wanted, (int) $profile['id']);

			if (!$found) {
				return null;
			}

			$resource = $found;
		}

		return load_view(dirname(__DIR__) . '/views/resource.php', [
			'resource' => $resource,
			'profile' => $profile,
			'placeholders' => $this->template->templatePlaceholders(),
			'assigned' => $this->profiles->profileClientCount((int) $profile['id']),
			// A resource that has never been written has no name to render
			// against a client, so Clients is there but does not open --
			// the same way Resources is on a new profile.
			'tab' => ($tab === 'clients' && $resource['id']) ? 'clients' : 'resource',
		]);
	}

	/**
	 * Render the list page.
	 *
	 * Both tables are filled over AJAX and neither row is edited here, so all
	 * the view is handed is which tab to open on and which row the editor it
	 * came back from has just written.
	 *
	 * One `saved` for both tables: each editor returns to its own tab, so the
	 * tab it arrives on says which table the id belongs to.
	 *
	 * @param string|null $tab Tab to open on, or null to take it from the request.
	 *
	 * @return string Rendered page output.
	 */
	private function showList($tab = null)
	{
		$tab = $tab === null ? (string) ($_REQUEST['tab'] ?? '') : $tab;

		return load_view(dirname(__DIR__) . '/views/admin.php', [
			'tab' => in_array($tab, ['profiles', 'logs'], true) ? $tab : 'clients',
			'saved' => (int) ($_REQUEST['saved'] ?? 0),
		]);
	}

	/**
	 * Buttons FreePBX draws in the page header.
	 *
	 * Only the editors have any: the list's two tabs each carry their own Add,
	 * and a single button in the header could not say which tab it meant.
	 *
	 * Deliberately not the usual submit/delete names -- those are wired by
	 * core to a `form.fpbx-submit`, and none of these pages has a form: a row
	 * is saved over AJAX, not posted. These are ours, and
	 * views/partials/editor.php binds them.
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
			// On a resource page it is the resource that Save and Delete act
			// on; the profile in the URL is only what it hangs off.
			$row = isset($_REQUEST['resource'])
				? trim((string) $_REQUEST['resource'])
				: trim((string) $_REQUEST['profile']);
		} else {
			return [];
		}

		$bar = [
			'oryksave' => [
				'name' => 'oryksave',
				'id' => 'oryksave',
				'value' => _('Save'),
			],
		];

		// Nothing to delete until there is a row: on a new profile, or a new
		// resource, the button would refer to something never written.
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
	 * editor on it would bind the fields to something that cannot be saved
	 * back. It is sent to the list instead, and it is done here because this
	 * runs before any of the page has been written -- a redirect out of
	 * showPage() would be too late to set a header.
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

		// Empty is the new-profile editor, not a lookup that failed. A
		// resource has nothing to hang off until the profile has been
		// written, so ?profile=&resource= is sent back to the profile.
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

		// Empty is the new-resource editor. Anything else has to name a
		// resource of this profile: an id belonging to some other profile is
		// as much a stale link as one belonging to nothing at all.
		if ($resource === '') {
			return;
		}

		if (!ctype_digit($resource) || !$this->resources->resourceRow($resource, (int) $wanted)) {
			header('Location: config.php?display=oryk_provisioner&profile=' . (int) $wanted . '&tab=resources');
			exit;
		}
	}
}
