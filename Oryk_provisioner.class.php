<?php

// Oryk_provisioner.class.php

namespace FreePBX\modules;

use BMO;
use FreePBX_Helpers;
use FreePBX\Modules\Oryk_Provisioner\Clients;
use FreePBX\Modules\Oryk_Provisioner\Counts;
use FreePBX\Modules\Oryk_Provisioner\Endpoint;
use FreePBX\Modules\Oryk_Provisioner\FileRepo;
use FreePBX\Modules\Oryk_Provisioner\Freepbx;
use FreePBX\Modules\Oryk_Provisioner\Installer;
use FreePBX\Modules\Oryk_Provisioner\LogRepo;
use FreePBX\Modules\Oryk_Provisioner\Logs;
use FreePBX\Modules\Oryk_Provisioner\Matcher;
use FreePBX\Modules\Oryk_Provisioner\Navigator;
use FreePBX\Modules\Oryk_Provisioner\Pages;
use FreePBX\Modules\Oryk_Provisioner\Previews;
use FreePBX\Modules\Oryk_Provisioner\Profiles;
use FreePBX\Modules\Oryk_Provisioner\ProvisioningLog;
use FreePBX\Modules\Oryk_Provisioner\Resources;
use FreePBX\Modules\Oryk_Provisioner\Schema;
use FreePBX\Modules\Oryk_Provisioner\Template;
use FreePBX\Modules\Oryk_Provisioner\Tokens;

// The subsystems this module is made of live in src/ and are loaded as they
// are asked for. BMO autoloads the module class itself, by rawname, and
// nothing else, so anything standing alongside it has to say where it lives.
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
 *   Matcher          a filename is a MAC and a name, read both ways
 *   Previews         which filename does this phone ask this file by
 *   ProvisioningLog  one row per request the endpoint answered
 *   Counts           how many rows a tab is labelled with
 *   Endpoint         answering a provisioning request, and ending it
 *   Pages            which URL is which page
 *   Installer        installing and uninstalling
 */
class Oryk_provisioner extends FreePBX_Helpers implements \BMO
{
	use Logs;

	/**
	 * FreePBX application instance.
	 *
	 * @var object
	 */
	public $FreePBX;

	/**
	 * Asterisk database handle.
	 *
	 * @var \PDO
	 */
	public $db;

	/** @var Clients */
	private $clients;

	/** @var Counts */
	private $counts;

	/** @var Endpoint */
	private $endpoint;

	/** @var FileRepo */
	private $files;

	/** @var Installer */
	private $installer;

	/** @var LogRepo */
	private $logs;

	/** @var Matcher */
	private $matcher;

	/** @var Navigator */
	private $navigator;

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

	/** @var Template */
	private $template;

	/** @var Tokens */
	private $tokens;

	/**
	 * Create an Oryk provisioner module instance.
	 *
	 * Built in dependency order, each subsystem once, collaborators handed
	 * in -- so what depends on what is readable here rather than discovered
	 * by following calls.
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
		$this->template = new Template($freepbx, $this->pbx);
		$this->matcher = new Matcher($freepbx, $this->template);
		$this->profiles = new Profiles($freepbx, $this->files);
		$this->clients = new Clients($freepbx, $this->pbx, $this->profiles, $this->tokens);
		$this->resources = new Resources($freepbx, $this->profiles, $this->files);
		$this->navigator = new Navigator($freepbx, $this->clients, $this->profiles, $this->resources);
		$this->previews = new Previews($freepbx, $this->clients, $this->matcher, $this->template);
		$this->installer = new Installer($freepbx, $this->schema, $this->files, $this->logs);

		$this->counts = new Counts($freepbx);

		$this->endpoint = new Endpoint(
			$freepbx,
			$this->clients,
			$this->matcher,
			$this->template,
			$this->files,
			$this->logs,
			$this->provisioningLog
		);

		$this->pages = new Pages(
			$freepbx,
			$this->clients,
			$this->profiles,
			$this->resources,
			$this->pbx,
			$this->template,
			$this->provisioningLog,
			$this->counts,
			$this->navigator,
			$this->logs
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
	 *
	 * @return array<string, mixed> The outcome, for a caller to act on.
	 */
	public function resolveRequest($mac, $requested = null, $token = null, $method = 'GET')
	{
		return $this->endpoint->resolveRequest($mac, $requested, $token, $method);
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
			case 'deleteProfile':
			case 'listResources':
			case 'saveResource':
			case 'deleteResource':
			case 'uploadResourceFile':
			case 'deleteResourceFile':
			case 'listLogs':
			case 'clearLogs':
			case 'counts':
				return true;
			default:
				return false;
		}
	}

	/**
	 * Process an AJAX request.
	 *
	 * A dispatch table over the subsystems, with one thing done here rather
	 * than in either of them: the two list commands are decorated with the
	 * filename each row's client-and-resource pairing produces, when they
	 * were asked from one of the preview tabs. That decoration needs both
	 * tables and so belongs to neither -- it is Previews, applied to the page
	 * of rows that was read.
	 *
	 * @return array<string, mixed>|null AJAX response data.
	 */
	public function ajaxHandler()
	{
		$command = isset($_REQUEST['command']) ? (string) $_REQUEST['command'] : '';

		switch ($command) {
			case 'listClients':
				$result = $this->clients->listClients();

				// Only the page that was read, and only when a resource was
				// asked about: rendering a name costs this client's values,
				// and a client that is not on screen is not worth them.
				if (isset($_REQUEST['resource_id'])) {
					$result['rows'] = $this->previews->withResourceFilenames(
						$result['rows'],
						$_REQUEST['resource_id']
					);
				}

				return $result;

			case 'listProfiles':
				return $this->profiles->listProfiles();

			case 'saveClient':
				return $this->clients->saveClient($_REQUEST);

			case 'saveProfile':
				return $this->profiles->saveProfile($_REQUEST);

			case 'deleteClient':
				return $this->clients->deleteClient($_REQUEST['id'] ?? null);

			case 'deleteProfile':
				return $this->profiles->deleteProfile($_REQUEST['id'] ?? null);

			case 'listResources':
				$result = $this->resources->listResources();

				// The client editor's Resources tab asks the same question of
				// the same table, from the other side: these files, for that
				// one phone.
				if (isset($_REQUEST['client_id'])) {
					$result['rows'] = $this->previews->withClientFilenames(
						$result['rows'],
						$_REQUEST['client_id']
					);
				}

				return $result;

			case 'saveResource':
				return $this->resources->saveResource($_REQUEST);

			case 'deleteResource':
				return $this->resources->deleteResource($_REQUEST['id'] ?? null);

			case 'uploadResourceFile':
				return $this->resources->uploadResourceFile($_REQUEST);

			case 'deleteResourceFile':
				return $this->resources->deleteResourceFile($_REQUEST);

			case 'listLogs':
				return $this->provisioningLog->listLogs();

			case 'clearLogs':
				return $this->provisioningLog->clearLogs($_REQUEST);

			// Every count a page has a badge for, in one answer: what the
			// tabs are labelled with after something on the page has changed
			// one. One command rather than four because a page that has just
			// deleted a client has changed what two of its tabs say, and
			// asking table by table is how two of them end up disagreeing.
			case 'counts':
				return $this->counts->countsRequest();

			default:
				return null;
		}
	}
}
