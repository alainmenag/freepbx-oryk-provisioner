<?php

// Oryk_provisioner.class.php

namespace FreePBX\modules;

use BMO;
use FreePBX_Helpers;
use FreePBX\Modules\Oryk_Provisioner\BanEscalation;
use FreePBX\Modules\Oryk_Provisioner\BanSync;
use FreePBX\Modules\Oryk_Provisioner\Bans;
use FreePBX\Modules\Oryk_Provisioner\CdrHistory;
use FreePBX\Modules\Oryk_Provisioner\Clients;
use FreePBX\Modules\Oryk_Provisioner\DeviceStatus;
use FreePBX\Modules\Oryk_Provisioner\Endpoint;
use FreePBX\Modules\Oryk_Provisioner\EndpointSettings;
use FreePBX\Modules\Oryk_Provisioner\ExtensionManager;
use FreePBX\Modules\Oryk_Provisioner\ExtensionRenumberer;
use FreePBX\Modules\Oryk_Provisioner\Fail2ban;
use FreePBX\Modules\Oryk_Provisioner\FileRepo;
use FreePBX\Modules\Oryk_Provisioner\Freepbx;
use FreePBX\Modules\Oryk_Provisioner\Installer;
use FreePBX\Modules\Oryk_Provisioner\LobbyContext;
use FreePBX\Modules\Oryk_Provisioner\LogRepo;
use FreePBX\Modules\Oryk_Provisioner\Logs;
use FreePBX\Modules\Oryk_Provisioner\Matcher;
use FreePBX\Modules\Oryk_Provisioner\Navigator;
use FreePBX\Modules\Oryk_Provisioner\Notices;
use FreePBX\Modules\Oryk_Provisioner\NumberAllocator;
use FreePBX\Modules\Oryk_Provisioner\Overview;
use FreePBX\Modules\Oryk_Provisioner\Pages;
use FreePBX\Modules\Oryk_Provisioner\Previews;
use FreePBX\Modules\Oryk_Provisioner\Profiles;
use FreePBX\Modules\Oryk_Provisioner\ProvisioningLog;
use FreePBX\Modules\Oryk_Provisioner\RealtimeBridge;
use FreePBX\Modules\Oryk_Provisioner\Resources;
use FreePBX\Modules\Oryk_Provisioner\Schema;
use FreePBX\Modules\Oryk_Provisioner\Services;
use FreePBX\Modules\Oryk_Provisioner\Settings;
use FreePBX\Modules\Oryk_Provisioner\SignupSweep;
use FreePBX\Modules\Oryk_Provisioner\Template;
use FreePBX\Modules\Oryk_Provisioner\Tokens;
use FreePBX\Modules\Oryk_Provisioner\UcpAssignments;
use FreePBX\Modules\Oryk_Provisioner\UsermanManager;
use FreePBX\Modules\Oryk_Provisioner\Users;
use FreePBX\Modules\Oryk_Provisioner\VoicemailManager;

// The subsystems this module is made of live in src/ and are loaded as they
// are asked for: BMO autoloads the module class itself, by rawname, and
// nothing else.
if (!defined('ORYK_PROVISIONER_AUTOLOADER')) {
	define('ORYK_PROVISIONER_AUTOLOADER', true);

	spl_autoload_register(function ($class) {
		$prefix = 'FreePBX\\Modules\\Oryk_Provisioner\\';

		if (strpos($class, $prefix) !== 0) {
			return;
		}

		$file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

		if (is_file($file)) {
			require_once $file;
		}
	});
}

/**
 * The module, as FreePBX sees it.
 *
 * Everything below is the BMO contract and the two entry points that call
 * into it -- page.oryk_provisioner.php through showPage(), and
 * engine/provisioner.php through serve(). What each of them does lives in
 * src/, wired once in the constructor:
 *
 *   Freepbx          the only file that asks FreePBX about a device
 *   Mac              a MAC as written, and as found in a filename
 *   Template         a placeholder, and what it resolves to
 *   Tokens           hashing a client's token, and checking one
 *   Schema           the tables, as they are added to
 *   FileRepo         where an uploaded resource file is kept
 *   LogRepo          where a log a phone sent us is kept
 *   Clients          |
 *   Profiles         |  one per table
 *   Resources        |
 *   Services         |
 *   Matcher          a filename is a MAC and a name, read both ways
 *   Previews         which filename does this phone ask this file by
 *   ProvisioningLog  one row per request the endpoint answered
 *   Endpoint         answering a provisioning request, and ending it
 *   Pages            which URL is which page
 *   Settings         the module's PBX-wide settings, and the Settings tab
 *   Installer        installing and uninstalling
 *
 * and, for the Users tab -- see ARCHITECTURE.md, "Users":
 *
 *   Users            saving, deleting and listing an Extension/User
 *   NumberAllocator  which numbers are free, and the next one
 *   ExtensionRenumberer  moving a user to another number, in order
 *   ExtensionManager, UsermanManager, VoicemailManager, UcpAssignments,
 *   CdrHistory       one each of what a number is made of
 *   EndpointSettings the From Domain, and pjsip.endpoint_custom_post.conf
 *
 * and, for the Bans tab -- see ARCHITECTURE.md, "Bans":
 *
 *   Bans             who the endpoint refuses, or answers in spite of a ban
 *   BanEscalation    a repeat Banned ban made Deny (ORYK_BAN_DENY_AFTER)
 *
 * and, keeping IP bans in step with fail2ban -- see ARCHITECTURE.md, "Syncing
 * with fail2ban":
 *
 *   Fail2ban         the only file that asks fail2ban, through the sudo helper
 *   BanSync          the minute job, and a save carried to fail2ban at once
 *
 * and, for open provisioning's sign-ups -- see ARCHITECTURE.md, "Open
 * provisioning" and "The Realtime bridge":
 *
 *   RealtimeBridge   a sign-up live before Apply Config writes it
 *   SignupSweep      the minute job: out of the bridge once written; notices
 *   Notices          the module's dashboard notices
 *   LobbyContext     the lobby's dialplan, on Apply Config
 */
class Oryk_provisioner extends FreePBX_Helpers implements \BMO
{
	use Logs;

	/** @var object FreePBX application instance. */
	public $FreePBX;

	/** @var \PDO Asterisk database handle. */
	public $db;

	/** @var Bans */
	private $bans;

	/** @var BanSync */
	private $banSync;

	/** @var Fail2ban */
	private $fail2ban;

	/** @var Clients */
	private $clients;

	/** @var Endpoint */
	private $endpoint;

	/** @var EndpointSettings */
	private $endpointSettings;

	/** @var FileRepo */
	private $files;

	/** @var Installer */
	private $installer;

	/** @var LobbyContext */
	private $lobby;

	/** @var SignupSweep */
	private $sweep;

	/** @var LogRepo */
	private $logs;

	/** @var Matcher */
	private $matcher;

	/** @var Navigator */
	private $navigator;

	/** @var Overview */
	private $overview;

	/** @var Pages */
	private $pages;

	/** @var Freepbx */
	private $pbx;

	/** @var Previews */
	private $previews;

	/** @var Profiles */
	private $profiles;

	/** @var ProvisioningLog */
	private $provisioningLog;

	/** @var Resources */
	private $resources;

	/** @var Schema */
	private $schema;

	/** @var Services */
	private $services;

	/** @var Settings */
	private $settings;

	/** @var Template */
	private $template;

	/** @var Tokens */
	private $tokens;

	/** @var Users */
	private $users;

	/**
	 * Create an Oryk provisioner module instance.
	 *
	 * Built in dependency order, each subsystem once, collaborators handed in --
	 * so what depends on what is readable here rather than found by following
	 * calls.
	 *
	 * @param object|null $freepbx FreePBX application instance.
	 *
	 * @throws \Exception If no FreePBX instance is provided.
	 */
	public function __construct($freepbx = null)
	{
		if ($freepbx == null) {
			throw new \Exception('Not given a FreePBX Object');
		}

		$this->FreePBX = $freepbx;
		$this->db = $freepbx->Database;

		$this->pbx = new Freepbx($freepbx);
		$this->files = new FileRepo($freepbx);
		$this->logs = new LogRepo($freepbx);
		$this->schema = new Schema($freepbx);
		$this->tokens = new Tokens($freepbx);
		$this->provisioningLog = new ProvisioningLog($freepbx);
		$this->settings = new Settings($freepbx);
		$this->template = new Template($freepbx, $this->pbx, $this->settings);
		$this->matcher = new Matcher($freepbx, $this->template);
		$this->profiles = new Profiles($freepbx, $this->files);
		$this->clients = new Clients($freepbx, $this->pbx, $this->profiles, $this->tokens, $this->logs);
		$this->resources = new Resources($freepbx, $this->profiles, $this->files);

		$this->services = new Services($freepbx);

		$bridge = new RealtimeBridge($freepbx);
		$this->sweep = new SignupSweep($freepbx, $this->clients, $bridge, $this->settings, new Notices($freepbx));
		$this->lobby = new LobbyContext($freepbx, $this->settings);

		$this->endpointSettings = new EndpointSettings($freepbx);
		$voicemail = new VoicemailManager($freepbx);
		$cdr = new CdrHistory($freepbx, $voicemail);
		$userman = new UsermanManager($freepbx);
		$ucp = new UcpAssignments($freepbx);
		$extensions = new ExtensionManager($freepbx);

		$this->users = new Users(
			$freepbx,
			new NumberAllocator($freepbx, $userman),
			new ExtensionRenumberer($freepbx, $extensions, $voicemail, $userman, $ucp, $cdr, $this->endpointSettings, $this->clients, $this->services),
			$extensions,
			$userman,
			$voicemail,
			$ucp,
			$cdr,
			$this->endpointSettings,
			$this->clients,
			$bridge,
			$this->services
		);

		$this->fail2ban = new Fail2ban($freepbx, $this->settings);
		$escalation = new BanEscalation($freepbx, $this->settings);
		$this->banSync = new BanSync($freepbx, $this->fail2ban, $escalation);
		$this->bans = new Bans($freepbx, $this->banSync, $escalation);

		$this->navigator = new Navigator($freepbx, $this->clients, $this->profiles, $this->resources, $this->users, $this->provisioningLog, $this->bans, $this->services);
		$this->overview = new Overview($freepbx, $this->navigator, $this->users, $this->clients, $this->bans, $this->provisioningLog, $this->logs, new DeviceStatus($freepbx));
		$this->previews = new Previews($freepbx, $this->clients, $this->matcher, $this->template);
		$this->installer = new Installer($freepbx, $this->schema, $this->files, $this->logs, $this->settings, $this->fail2ban, $bridge, $this->services);

		$this->endpoint = new Endpoint(
			$freepbx,
			$this->clients,
			$this->matcher,
			$this->template,
			$this->files,
			$this->logs,
			$this->provisioningLog,
			$this->profiles,
			$this->users,
			$this->bans,
			$bridge,
			$this->sweep
		);

		$this->pages = new Pages(
			$freepbx,
			$this->clients,
			$this->profiles,
			$this->resources,
			$this->pbx,
			$this->template,
			$this->provisioningLog,
			$this->navigator,
			$this->logs,
			$this->users,
			$this->endpointSettings,
			$this->settings,
			$this->bans,
			$this->fail2ban,
			$this->overview,
			$this->services
		);
	}

	/**
	 * Render the requested module page.
	 *
	 * @return string Rendered page output.
	 */
	public function showPage()
	{
		return $this->pages->showPage();
	}

	/**
	 * Buttons for the page header.
	 *
	 * @param string $request Page being rendered.
	 *
	 * @return array<string, array<string, string>> Action bar buttons.
	 */
	public function getActionBar($request)
	{
		return $this->pages->getActionBar($request);
	}

	/**
	 * Run before any markup, so an id that names no row can be redirected.
	 *
	 * @param string $page Page being requested.
	 *
	 * @return void
	 */
	public function doConfigPageInit($page)
	{
		$this->pages->doConfigPageInit($page);
	}

	/**
	 * Create the tables, the repo directory and the web-root symlink.
	 *
	 * @return void
	 */
	public function install()
	{
		$this->installer->install();
	}

	/**
	 * Remove the web-root symlink. Nothing is dropped.
	 *
	 * @return void
	 */
	public function uninstall()
	{
		$this->installer->uninstall();
	}

	/**
	 * Backup hook.
	 *
	 * @return void
	 */
	public function backup()
	{
		$this->installer->backup();
	}

	/**
	 * Restore hook.
	 *
	 * @param array<string, mixed> $backup Backup data.
	 *
	 * @return void
	 */
	public function restore($backup)
	{
		$this->installer->restore($backup);
	}

	/**
	 * Answer a provisioning request and end it.
	 *
	 * Called by engine/provisioner.php, which is the only thing that reaches
	 * this module without an admin session.
	 *
	 * @param mixed       $mac       MAC address, written however it was written.
	 * @param string|null $requested Filename asked for.
	 * @param string|null $token     Token offered, when one was.
	 *
	 * @return void This ends the request.
	 */
	public function serve($mac, $requested = null, $token = null)
	{
		$this->endpoint->serve($mac, $requested, $token);
	}

	/**
	 * Take what a phone sent us and end the request.
	 *
	 * The other direction: a phone PUTs its boot and app logs back, and a
	 * resource of type Log is what says this profile takes one.
	 *
	 * @param mixed       $mac       MAC address, written however it was written.
	 * @param string|null $requested Filename PUT to.
	 * @param string|null $token     Token offered, when one was.
	 *
	 * @return void This ends the request.
	 */
	public function receive($mac, $requested = null, $token = null)
	{
		$this->endpoint->receive($mac, $requested, $token);
	}

	/**
	 * Work out what a provisioning request should be answered with, without
	 * answering it.
	 *
	 * @param mixed       $mac       MAC address, written however it was written.
	 * @param string|null $requested Filename asked for.
	 * @param string|null $token     Token offered, when one was.
	 * @param string      $method    Request method it would be asked with.
	 * @param string|null $vendor    Vendor a User-Agent names; see Vendor.
	 *
	 * @return array<string, mixed> The outcome, for a caller to act on.
	 */
	public function resolveRequest($mac, $requested = null, $token = null, $method = 'GET', $vendor = null)
	{
		return $this->endpoint->resolveRequest($mac, $requested, $token, $method, $vendor);
	}

	/**
	 * Answer a request for the all-zero MAC by its credentials, and end the
	 * request. See Endpoint::openProvision().
	 *
	 * @param string|null $username  Basic username offered.
	 * @param string|null $password  Basic password offered.
	 * @param string|null $requested Filename asked for.
	 * @param string      $method    GET, HEAD or PUT.
	 *
	 * @return void This ends the request.
	 */
	public function openProvision($username, $password, $requested = null, $method = 'GET')
	{
		$this->endpoint->openProvision($username, $password, $requested, $method);
	}

	/**
	 * One run of the fail2ban sync. Called every minute by bin/oryk-fail2ban-sync,
	 * which FreePBX's scheduler runs.
	 *
	 * @return array<string, mixed> ok, and what was done, or error.
	 */
	public function syncFail2ban()
	{
		return $this->banSync->run();
	}

	/**
	 * One run of the open-provisioning sweep. Called every minute by
	 * bin/oryk-signup-sweep, which FreePBX's scheduler runs.
	 *
	 * @return array<string, int> What was done; see SignupSweep::run().
	 */
	public function sweepSignups()
	{
		return $this->sweep->run();
	}

	/**
	 * When this module's dialplan is written, among every module's: after
	 * Core's, since the forward guard splices into ext-local.
	 *
	 * @return int Priority.
	 */
	public function myDialplanHooks()
	{
		return 900;
	}

	/**
	 * Write the lobby's dialplan. See LobbyContext.
	 *
	 * @param object $ext      FreePBX's extensions object.
	 * @param string $engine   Dialplan engine; only asterisk is written for.
	 * @param int    $priority The priority myDialplanHooks() asked for.
	 *
	 * @return void
	 */
	public function doDialplanHook(&$ext, $engine, $priority)
	{
		if ($engine === 'asterisk') {
			$this->lobby->generate($ext);
		}
	}

	/**
	 * Record one provisioning request.
	 *
	 * Public because the endpoint logs the requests that never reach serve()
	 * at all -- a PUT of a phone's boot log, or a path with no MAC in it.
	 *
	 * @param mixed       $mac       MAC address, written however it was written.
	 * @param string|null $requested Filename asked for.
	 * @param int         $status    HTTP status the request was answered with.
	 * @param string|null $message   Why, when it was not answered with a file.
	 *
	 * @return void
	 */
	public function logRequest($mac, $requested, $status, $message = null)
	{
		$this->provisioningLog->logRequest($mac, $requested, $status, $message);
	}

	/**
	 * Hash a token typed as user:password.
	 *
	 * @param string $token Token as typed.
	 *
	 * @return string The hash to store.
	 */
	public function hashToken($token)
	{
		return $this->tokens->hashToken($token);
	}

	/**
	 * Check a token against the one stored for a MAC.
	 *
	 * @param mixed  $mac   MAC address, written however it was written.
	 * @param string $token Token offered.
	 *
	 * @return bool True when it matches.
	 */
	public function verifyToken($mac, $token)
	{
		return $this->tokens->verifyToken($mac, $token);
	}

	/**
	 * Which AJAX commands this module answers.
	 *
	 * @param string $req     Command being requested.
	 * @param array  $setting Request settings, by reference.
	 *
	 * @return bool True when the command is ours.
	 */
	public function ajaxRequest($req, &$setting)
	{
		switch ($req) {
			case 'listClients':
			case 'listProfiles':
			case 'saveClient':
			case 'saveProfile':
			case 'deleteClient':
			case 'setClientEnabled':
			case 'setProfileEnabled':
			case 'deleteProfile':
			case 'listResources':
			case 'viewResource':
			case 'downloadResource':
			case 'saveResource':
			case 'deleteResource':
			case 'uploadResourceFile':
			case 'deleteResourceFile':
			case 'listLogs':
			case 'clearLogs':
			case 'deleteLog':
			case 'listUsers':
			case 'saveUser':
			case 'deleteUser':
			case 'deleteExpiredUsers':
			case 'listServices':
			case 'saveService':
			case 'deleteService':
			case 'setUserService':
			case 'saveSettings':
			case 'listBans':
			case 'saveBan':
			case 'setBanState':
			case 'deleteBan':
			case 'listOverviewUsers':
			case 'listOverviewDevices':
			case 'deleteOverviewDevice':
			case 'listOverviewClients':
			case 'listOverviewBans':
			case 'listOverviewCalls':
			case 'clearOverviewHistory':
			case 'listOverviewVoicemail':
			case 'clearOverviewVoicemail':
			case 'deleteOverviewVoicemail':
			case 'clearOverviewLogs':
			case 'clearOverviewStored':
			case 'purgeOverview':
				return true;
			default:
				return false;
		}
	}

	/**
	 * Process an AJAX request.
	 *
	 * A dispatch table over the subsystems, with one thing done here rather than
	 * in either of them: the two list commands are decorated with the filename
	 * each row's client-and-resource pairing produces, when they were asked from
	 * one of the preview tabs. That decoration needs both tables and so belongs to
	 * neither.
	 *
	 * @return array<string, mixed>|null AJAX response data.
	 */
	public function ajaxHandler()
	{
		$command = isset($_REQUEST['command']) ? (string) $_REQUEST['command'] : '';

		// A list opened from a navigator title is narrowed the way that title's
		// badge was counted: `&scope=<kind>:<id>` names the row, Navigator says
		// what it scopes. Read only by the list commands.
		$scope = in_array($command, ['listClients', 'listProfiles', 'listLogs', 'listUsers', 'listBans', 'listServices'], true)
			? $this->navigator->scope(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')))
			: null;

		switch ($command) {
			case 'listClients':
				$result = $this->clients->listClients($scope['clients']);

				// Only the page that was read, and only when a resource was asked about:
				// rendering a name costs this client's values.
				if (isset($_REQUEST['resource_id'])) {
					$result['rows'] = $this->previews->withResourceFilenames(
						$result['rows'],
						$_REQUEST['resource_id']
					);
				}

				return $result;

			case 'listProfiles':
				return $this->profiles->listProfiles($scope['profiles']);

			case 'saveClient':
				return $this->clients->saveClient($_REQUEST);

			case 'saveProfile':
				return $this->profiles->saveProfile($_REQUEST);

			// `device` is the answer "Client + Device": the device it used goes too.
			case 'deleteClient':
				return empty($_REQUEST['device'])
					? $this->clients->deleteClient($_REQUEST['id'] ?? null)
					: $this->overview->deleteClientWithDevice($_REQUEST['id'] ?? null);

			// One column, changed from the row it is shown on. Not folded into
			// saveClient: that writes every field the editor holds, and a list row does
			// not hold them. Same for a profile -- see src/Enabled.php.
			case 'setClientEnabled':
				return $this->clients->setClientEnabled($_REQUEST);

			case 'setProfileEnabled':
				return $this->profiles->setProfileEnabled($_REQUEST);

			case 'deleteProfile':
				return $this->profiles->deleteProfile($_REQUEST['id'] ?? null);

			case 'listResources':
				$result = $this->resources->listResources();

				// The client editor's Resources tab asks the same question of the same
				// table, from the other side: these files, for that one phone.
				if (isset($_REQUEST['client_id'])) {
					$result['rows'] = $this->previews->withClientFilenames(
						$result['rows'],
						$_REQUEST['client_id']
					);
				}

				return $result;

			// Never return: the body is the answer, not JSON.
			case 'viewResource':
				$this->endpoint->viewResource(
					$_REQUEST['client_id'] ?? null,
					$_REQUEST['resource_id'] ?? null
				);

			case 'downloadResource':
				$this->endpoint->downloadResource(
					$_REQUEST['client_id'] ?? null,
					$_REQUEST['resource_id'] ?? null
				);

			case 'saveResource':
				return $this->resources->saveResource($_REQUEST);

			case 'deleteResource':
				return $this->resources->deleteResource($_REQUEST['id'] ?? null);

			case 'uploadResourceFile':
				return $this->resources->uploadResourceFile($_REQUEST);

			case 'deleteResourceFile':
				return $this->resources->deleteResourceFile($_REQUEST);

			case 'listLogs':
				return $this->provisioningLog->listLogs($scope['logs'], $scope['ip']);

			case 'clearLogs':
				return $this->provisioningLog->clearLogs($_REQUEST);

			case 'deleteLog':
				return $this->provisioningLog->deleteLog($_REQUEST['id'] ?? null);

			case 'listUsers':
				return $this->users->listUsers($scope['users']);

			// A save or delete raises Apply Config; nothing here reloads.
			case 'saveUser':
				return $this->users->saveUser($_REQUEST);

			case 'deleteUser':
				return $this->users->deleteUser($_REQUEST['id'] ?? null);

			case 'deleteExpiredUsers':
				return $this->users->deleteExpired($_REQUEST['ids'] ?? []);

			case 'listServices':
				return $this->services->listServices($scope['services']);

			case 'saveService':
				return $this->services->saveService($_REQUEST);

			case 'deleteService':
				return $this->services->deleteService($_REQUEST['id'] ?? null);

			// One service, on or off one user, from that user's Services tab.
			case 'setUserService':
				return $this->services->setUserService($_REQUEST);

			case 'saveSettings':
				return $this->settings->saveSettings($_REQUEST);

			case 'listBans':
				return $this->bans->listBans($scope['bans']);

			case 'saveBan':
				return $this->bans->saveBan($_REQUEST);

			case 'setBanState':
				return $this->bans->setBanState($_REQUEST);

			case 'deleteBan':
				return $this->bans->deleteBan($_REQUEST['id'] ?? null);

			// Overview's own: each is given the `&scope=` and nothing else, and
			// works out what is related itself.
			case 'listOverviewUsers':
				return $this->overview->listUsers(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			case 'listOverviewDevices':
				return $this->overview->listDevices(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			case 'deleteOverviewDevice':
				return $this->overview->deleteDevice(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')), $_REQUEST['id'] ?? '', !empty($_REQUEST['clients']));

			case 'listOverviewClients':
				return $this->overview->listClients(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			case 'listOverviewBans':
				return $this->overview->listBans(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			case 'listOverviewCalls':
				return $this->overview->listCalls(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			case 'clearOverviewHistory':
				return $this->overview->clearHistory(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			case 'listOverviewVoicemail':
				return $this->overview->listVoicemail(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			case 'clearOverviewVoicemail':
				return $this->overview->clearVoicemail(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			case 'deleteOverviewVoicemail':
				return $this->overview->deleteVoicemail(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')), $_REQUEST['id'] ?? '');

			case 'clearOverviewLogs':
				return $this->overview->clearLogs(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			case 'clearOverviewStored':
				return $this->overview->clearStored(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			case 'purgeOverview':
				return $this->overview->purge(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

			default:
				return null;
		}
	}
}
