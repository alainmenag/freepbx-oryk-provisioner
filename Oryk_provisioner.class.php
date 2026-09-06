<?php
// vim: set ai ts=4 sw=4 ft=php:
/**
 * Oryk Provisioner - FreePBX module class.
 *
 * This class is deliberately thin: it adapts FreePBX (BMO lifecycle, page
 * rendering, AJAX) to the provisioning library under lib/. All provisioning
 * behaviour lives in Oryk\Provisioner\* so it can be reused by the admin
 * interface, the GraphQL API and both provisioning endpoints.
 *
 * @package   oryk_provisioner
 * @license   AGPLv3
 */

namespace FreePBX\modules;

use BMO;
use FreePBX_Helpers;
use Oryk\Provisioner\Admin\AjaxController;
use Oryk\Provisioner\Admin\View;
use Oryk\Provisioner\Provisioner;

require_once __DIR__ . '/lib/autoload.php';

class Oryk_provisioner extends FreePBX_Helpers implements BMO
{
	const RAWNAME = 'oryk_provisioner';

	/** @var Provisioner|null */
	private $provisioner = null;

	/** @var View|null */
	private $view = null;

	/** @var AjaxController|null */
	private $ajax = null;

	public function __construct($freepbx = null)
	{
		parent::__construct($freepbx);
	}

	// ---------------------------------------------------------------- lifecycle

	/**
	 * Called by FreePBX on install and upgrade. install.php does the same work
	 * for direct installs; both paths are idempotent.
	 */
	public function install()
	{
		$this->provisioner()->install();
	}

	public function uninstall()
	{
		$this->provisioner()->uninstall();
	}

	public function backup()
	{
	}

	public function restore($backup)
	{
	}

	// --------------------------------------------------------------- page hooks

	/**
	 * Runs before any page output.
	 *
	 * Two jobs:
	 *  1. A request carrying a provisioning token and filename is answered with
	 *     the raw configuration file and nothing else.
	 *  2. Anything else is the admin interface and requires a FreePBX session.
	 *
	 * @param string $page
	 */
	public function doConfigPageInit($page)
	{
		if ($page !== self::RAWNAME) {
			return;
		}

		if ($this->isProvisioningRequest()) {
			$this->serveProvisioningRequest();
			// serveProvisioningRequest() never returns.
		}

		$this->requireAdminSession();
		$this->pruneLogs();
	}

	/**
	 * The page has its own toolbar inside the views, so FreePBX should not draw
	 * submit/reset buttons for it.
	 *
	 * @return array
	 */
	public function getActionBar($request)
	{
		return array();
	}

	/**
	 * Render the admin interface.
	 *
	 * @return string
	 */
	public function showPage()
	{
		$provisioner = $this->provisioner();
		$settings = $provisioner->settings();

		return $this->view()->render('main', array(
			'settings'   => $settings->all(),
			'templates'  => $provisioner->templates()->all(),
			'vendors'    => $provisioner->templates()->vendors(),
			'friendly'   => $this->friendlyStatus(),
			'baseUrl'    => $provisioner->urls()->baseUrl(),
			'moduleName' => self::RAWNAME,
		));
	}

	// --------------------------------------------------------------------- ajax

	/**
	 * Authorise an AJAX command. Every command requires an authenticated
	 * FreePBX session and is refused from remote origins.
	 *
	 * @param string $req
	 * @param array  $setting
	 * @return bool
	 */
	public function ajaxRequest($req, &$setting)
	{
		$setting['authenticate'] = true;
		$setting['allowremote'] = false;

		return in_array($req, AjaxController::commands(), true);
	}

	/**
	 * @return array
	 */
	public function ajaxHandler()
	{
		$command = isset($_REQUEST['command']) ? (string) $_REQUEST['command'] : '';

		return $this->ajaxController()->handle($command);
	}

	/**
	 * File downloads (a rendered configuration, a template export) write their
	 * own response. Returning true tells FreePBX the request is finished.
	 *
	 * @return bool
	 */
	public function ajaxCustomHandler()
	{
		$command = isset($_REQUEST['command']) ? (string) $_REQUEST['command'] : '';

		if (!in_array($command, AjaxController::rawCommands(), true)) {
			return false;
		}

		return $this->ajaxController()->handleRaw($command);
	}

	// ------------------------------------------------------------------- public

	/**
	 * The service container, exposed so other modules and the GraphQL API can
	 * reuse the engine:  FreePBX::Oryk_provisioner()->provisioner()
	 *
	 * @return Provisioner
	 */
	public function provisioner()
	{
		if ($this->provisioner === null) {
			$this->provisioner = Provisioner::boot($this->getDatabase());
		}

		return $this->provisioner;
	}

	// ------------------------------------------------------------------ private

	/**
	 * Is this request a device asking for its configuration?
	 */
	private function isProvisioningRequest()
	{
		if (!$this->provisioner()->settings()->get('allow_config_route')) {
			return false;
		}

		return !empty($_REQUEST['token']) && !empty($_REQUEST['filename']);
	}

	/**
	 * Answer a provisioning request and stop. Nothing of the admin interface is
	 * rendered, so the device receives only the configuration file.
	 */
	private function serveProvisioningRequest()
	{
		$this->provisioner()->endpoint()->handle(
			(string) $_REQUEST['token'],
			(string) $_REQUEST['filename']
		);

		exit;
	}

	/**
	 * The menu item is reachable without FreePBX authentication so that devices
	 * can use the documented provisioning URL. Everything that is not a
	 * provisioning request therefore has to prove it has an admin session.
	 */
	private function requireAdminSession()
	{
		if ($this->provisioner()->freepbx()->hasAdminSession()) {
			return;
		}

		while (ob_get_level() > 0) {
			@ob_end_clean();
		}

		if (!headers_sent()) {
			header('HTTP/1.1 302 Found');
			header('Location: config.php');
		}

		exit;
	}

	/**
	 * Trim the provisioning log, at most once an hour.
	 */
	private function pruneLogs()
	{
		$days = (int) $this->provisioner()->settings()->get('log_retention_days');

		if ($days <= 0) {
			return;
		}

		$last = (int) $this->getConfig('log_pruned_at');

		if ($last > (time() - 3600)) {
			return;
		}

		$this->setConfig('log_pruned_at', time());
		$this->provisioner()->logs()->prune($days);
	}

	/**
	 * @return array
	 */
	private function friendlyStatus()
	{
		$installer = new \Oryk\Provisioner\Admin\FriendlyUrlInstaller(
			$this->provisioner()->settings(),
			$this->provisioner()->freepbx()
		);

		return $installer->status();
	}

	/**
	 * @return View
	 */
	private function view()
	{
		if ($this->view === null) {
			$this->view = new View(__DIR__ . '/views');
		}

		return $this->view;
	}

	/**
	 * @return AjaxController
	 */
	private function ajaxController()
	{
		if ($this->ajax === null) {
			$this->ajax = new AjaxController($this->provisioner(), $this->view());
		}

		return $this->ajax;
	}

	/**
	 * FreePBX's PDO handle, however this class was constructed.
	 *
	 * @return \PDO
	 */
	private function getDatabase()
	{
		if (isset($this->FreePBX) && is_object($this->FreePBX) && isset($this->FreePBX->Database)) {
			return $this->FreePBX->Database;
		}

		return \FreePBX::Database();
	}
}
