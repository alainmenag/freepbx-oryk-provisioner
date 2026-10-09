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
use FreePBX\Modules\Oryk_Provisioner\DashboardNotices;
use FreePBX\Modules\Oryk_Provisioner\DeviceStatus;
use FreePBX\Modules\Oryk_Provisioner\Endpoint;
use FreePBX\Modules\Oryk_Provisioner\EndpointSettings;
use FreePBX\Modules\Oryk_Provisioner\ExtensionManager;
use FreePBX\Modules\Oryk_Provisioner\ExtensionRenumberer;
use FreePBX\Modules\Oryk_Provisioner\Fail2ban;
use FreePBX\Modules\Oryk_Provisioner\FileRepo;
use FreePBX\Modules\Oryk_Provisioner\Freepbx;
use FreePBX\Modules\Oryk_Provisioner\Installer;
use FreePBX\Modules\Oryk_Provisioner\Jobs;
use FreePBX\Modules\Oryk_Provisioner\Library;
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
use FreePBX\Modules\Oryk_Provisioner\Reactions;
use FreePBX\Modules\Oryk_Provisioner\RealtimeBridge;
use FreePBX\Modules\Oryk_Provisioner\Resources;
use FreePBX\Modules\Oryk_Provisioner\Schema;
use FreePBX\Modules\Oryk_Provisioner\ServiceEngine;
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
 *   Library          the profiles the module ships, and copying one
 *   Matcher          a filename is a MAC and a name, read both ways
 *   Previews         which filename does this phone ask this file by
 *   ProvisioningLog  one row per request the endpoint answered
 *   Endpoint         answering a provisioning request, and ending it
 *   Pages            which URL is which page
 *   Settings         the module's PBX-wide settings, and the Settings tab
 *   Notices          the notices over every module page
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
 *   DashboardNotices the module's dashboard notices
 *   LobbyContext     the lobby's dialplan, on Apply Config
 *
 * and, reacting to what a user's services become -- see ARCHITECTURE.md, "Jobs":
 *
 *   Jobs             the jobs and their steps
 *   ServiceEngine    what a change means for a user, and the worker that runs it
 *   Reactions        the module's own jobs, one class each in src/Jobs/
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

	/** @var Notices */
	private $notices;

	/** @var Jobs */
	private $jobs;

	/** @var ServiceEngine */
	private $engine;

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

	/** @var Library */
	private $library;

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
		$this->jobs = new Jobs($freepbx);
		$this->services = new Services($freepbx, $this->jobs);
		$this->template = new Template($freepbx, $this->pbx, $this->settings, $this->services);
		$this->matcher = new Matcher($freepbx, $this->template);
		$this->profiles = new Profiles($freepbx, $this->files);
		$this->clients = new Clients($freepbx, $this->pbx, $this->profiles, $this->tokens, $this->logs);
		$this->resources = new Resources($freepbx, $this->profiles, $this->files);
		$this->library = new Library($freepbx, $this->profiles, $this->resources);
		// This class is the key-value store: FreePBX_Helpers' getConfig() and setConfig().
		$this->notices = new Notices($freepbx, $this);

		$bridge = new RealtimeBridge($freepbx);
		$this->sweep = new SignupSweep($freepbx, $this->clients, $bridge, $this->settings, new DashboardNotices($freepbx));
		$this->lobby = new LobbyContext($freepbx, $this->settings);

		$this->endpointSettings = new EndpointSettings($freepbx);
		$voicemail = new VoicemailManager($freepbx);
		$cdr = new CdrHistory($freepbx, $voicemail);
		$userman = new UsermanManager($freepbx);
		$ucp = new UcpAssignments($freepbx);
		$extensions = new ExtensionManager($freepbx);
		$this->engine = new ServiceEngine($freepbx, $this->jobs, $this->services, new Reactions($freepbx, $extensions));

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

		$this->navigator = new Navigator($freepbx, $this->clients, $this->profiles, $this->resources, $this->users, $this->provisioningLog, $this->bans, $this->services, $this->jobs);
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
			$this->services,
			$this->jobs,
			$this->library,
			$this->notices
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
	 * Create the tables, the repo directory and the web-root symlink, and drop
	 * the notice dismissals this version no longer reads.
	 *
	 * @return void
	 */
	public function install()
	{
		$this->installer->install();
		$this->notices->prune();
	}

	/**
	 * Remove the web-root symlink and the notice dismissals. No table is dropped.
	 *
	 * @return void
	 */
	public function uninstall()
	{
		$this->installer->uninstall();
		$this->notices->reset();
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
	 * One run of the service job worker. Called by bin/oryk-jobs: in the
	 * background for one user when a change is saved, and every minute for all.
	 *
	 * @param string|null $extension One user's queue, or null for every user with one.
	 *
	 * @return array<string, int> users, done, failed, purged.
	 */
	public function runJobs($extension = null)
	{
		return $this->engine->run($extension);
	}

	/**
	 * Every service a user holds, packs followed: for other modules. See docs/hooks.md.
	 *
	 * @param string $extension The user.
	 *
	 * @return array<int, string> Slugs, each once, sorted.
	 */
	public function userServiceSlugs($extension)
	{
		return $this->services->userSlugs((string) $extension);
	}

	/**
	 * Whether a user holds a service, assigned it or through a pack: for other modules.
	 *
	 * @param string $extension The user.
	 * @param string $slug      The service.
	 *
	 * @return bool True when it does.
	 */
	public function hasService($extension, $slug)
	{
		return $this->services->hasService((string) $extension, (string) $slug);
	}

	/**
	 * A user was granted a service: the hook point other modules declare in
	 * their module.xml (`callingMethod="serviceGranted"`).
	 *
	 * The job worker calls the same handlers, one at a time, and records each;
	 * called directly, this runs every hooked module -- this one's own jobs
	 * included -- for one event, outside any job. See docs/hooks.md.
	 *
	 * @param array<string, mixed> $event extension and service at least; see docs/hooks.md.
	 *
	 * @return void
	 *
	 * @throws \FreePBX\Modules\Oryk_Provisioner\HandlerFailed The first handler that threw.
	 */
	public function serviceGranted(array $event)
	{
		$this->engine->dispatch('granted', ['event' => 'granted'] + $event);
	}

	/**
	 * A user lost a service: serviceGranted()'s other half.
	 *
	 * @param array<string, mixed> $event extension and service at least; see docs/hooks.md.
	 *
	 * @return void
	 *
	 * @throws \FreePBX\Modules\Oryk_Provisioner\HandlerFailed The first handler that threw.
	 */
	public function serviceRevoked(array $event)
	{
		$this->engine->dispatch('revoked', ['event' => 'revoked'] + $event);
	}

	/**
	 * This module's own hook on serviceGranted (module.xml): runs the job in
	 * src/Jobs/ for the service, if there is one. Called by the job worker,
	 * like every hooked module.
	 *
	 * @param array<string, mixed> $event See docs/hooks.md.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the job cannot be done.
	 */
	public function runOwnJobGranted(array $event)
	{
		$this->engine->ownJob('granted', $event);
	}

	/**
	 * This module's own hook on serviceRevoked: runOwnJobGranted()'s other half.
	 *
	 * @param array<string, mixed> $event See docs/hooks.md.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the job cannot be done.
	 */
	public function runOwnJobRevoked(array $event)
	{
		$this->engine->ownJob('revoked', $event);
	}

	/**
	 * Core deleted a user, from anywhere in FreePBX: its services and jobs go.
	 * Hooked in module.xml.
	 *
	 * Edit mode is a save, which Core makes by deleting and adding the user
	 * again, and is passed over.
	 *
	 * @param string $extension The extension deleted.
	 * @param bool   $editmode  True on a save.
	 *
	 * @return void
	 */
	public function coreDelUser($extension, $editmode = false)
	{
		if (!$editmode) {
			$this->services->forgetUser((string) $extension);
		}
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
	 * The AJAX commands that only read. **Every other command changes
	 * something and is answered only to a POST** (ajaxHandler()), which a
	 * link, an image or a redirect cannot make. A new command that reads is
	 * named here as well as in ajaxRequest() and ajaxHandler(); one left out
	 * is taken to write.
	 */
	const READS = [
		'listClients', 'listProfiles', 'listResources', 'viewResource', 'downloadResource',
		'listLogs', 'listUsers', 'listServices', 'listJobs', 'listBans',
		'userServicesImpact', 'serviceImpact', 'userServiceJobs',
		'listOverviewUsers', 'listOverviewDevices', 'listOverviewClients',
		'listOverviewBans', 'listOverviewCalls', 'listOverviewVoicemail',
	];

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
			case 'setUserServices':
			case 'userServicesImpact':
			case 'serviceImpact':
			case 'userServiceJobs':
			case 'listJobs':
			case 'retryJob':
			case 'deleteJob':
			case 'saveSettings':
			case 'dismissNotice':
			case 'resetNotices':
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
			case 'clearOverviewJobs':
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

		if (!in_array($command, self::READS, true) && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
			return ['status' => false, 'message' => _('This can only be done with a POST.')];
		}

		// A list opened from a navigator title is narrowed the way that title's
		// badge was counted: `&scope=<kind>:<id>` names the row, Navigator says
		// what it scopes. Read only by the list commands.
		$scope = in_array($command, ['listClients', 'listProfiles', 'listLogs', 'listUsers', 'listBans', 'listServices', 'listJobs'], true)
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

			// A Device of "Auto Create" has the device made first; that is Users'.
			case 'saveClient':
				return (string) ($_REQUEST['device_id'] ?? '') === Clients::AUTO_DEVICE
					? $this->users->saveClientWithNewDevice($_REQUEST)
					: $this->clients->saveClient($_REQUEST);

			case 'saveProfile':
				return $this->library->saveProfile($_REQUEST);

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
				return $this->services->deleteService($_REQUEST['slug'] ?? null);

			// What a user's Services tab has staged, saved as one change.
			case 'setUserServices':
				return $this->services->setUserServices($_REQUEST);

			// What that save would change, asked first.
			case 'userServicesImpact':
				return $this->services->userServicesImpact($_REQUEST);

			// What a save or delete on a service page would change, asked first.
			case 'serviceImpact':
				return $this->services->serviceImpact($_REQUEST);

			// Where a user's services stand, for the Services tab's Status while jobs run.
			case 'userServiceJobs':
				return ['status' => true, 'jobs' => $this->jobs->statusFor((string) ($_REQUEST['extension'] ?? ''))];

			case 'listJobs':
				return $this->jobs->listJobs($scope['jobs']);

			case 'retryJob':
				return $this->jobs->retryJob($_REQUEST['id'] ?? null);

			case 'deleteJob':
				return $this->jobs->deleteJob($_REQUEST['id'] ?? null);

			case 'saveSettings':
				return $this->settings->saveSettings($_REQUEST);

			// Answers with the notice that takes its place, drawn: see Pages.
			case 'dismissNotice':
				return $this->pages->dismissNotice($_REQUEST);

			case 'resetNotices':
				return $this->notices->reset();

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

			// A user's jobs, all of them; a client has none of its own.
			case 'clearOverviewJobs':
				$at = Overview::target(Navigator::scopeAt((string) ($_REQUEST['scope'] ?? '')));

				if (!isset($at['user'])) {
					return ['status' => false, 'message' => _('Only a user has jobs to clear.')];
				}

				$this->jobs->forgetUser($at['user']);

				return ['status' => true];

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
