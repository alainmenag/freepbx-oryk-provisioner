<?php

namespace Oryk\Provisioner\Admin;

use Oryk\Provisioner\Exception\ProvisionerException;
use Oryk\Provisioner\Exception\ValidationException;
use Oryk\Provisioner\Model\Device;
use Oryk\Provisioner\Model\Output;
use Oryk\Provisioner\Model\Template;
use Oryk\Provisioner\Provisioner;
use Oryk\Provisioner\Provisioning\Context;
use Oryk\Provisioner\Repository\LogRepository;
use Oryk\Provisioner\Settings;
use Oryk\Provisioner\Support\Json;
use Oryk\Provisioner\Support\Str;

/**
 * Every admin AJAX command lives here, keeping the BMO module class a thin
 * adapter. Handlers return arrays; the FreePBX Ajax layer encodes them.
 *
 * Responses are uniform:
 *   { "status": true,  "message": "...", "data": { ... } }
 *   { "status": false, "message": "...", "errors": { "field": "..." } }
 */
class AjaxController
{
	/** @var Provisioner */
	private $provisioner;

	/** @var View */
	private $view;

	/** @var array */
	private $request;

	/** Commands the module answers, grouped for readability. */
	private static $commands = array(
		// Devices
		'getDevices', 'getDevice', 'deviceForm', 'saveDevice', 'deleteDevice',
		'toggleDevice', 'regenerateToken', 'previewDevice', 'deviceUrls', 'downloadFile',
		// Templates
		'getTemplates', 'getTemplate', 'templateForm', 'saveTemplate', 'deleteTemplate',
		'cloneTemplate', 'exportTemplate', 'importTemplate', 'seedTemplates', 'templateSchema',
		// Logs
		'getLogs', 'clearLogs',
		// Settings and helpers
		'getSettings', 'saveSettings', 'installFriendlyUrl', 'removeFriendlyUrl',
		'getExtensions', 'getSummary',
	);

	public function __construct(?Provisioner $provisioner = null, ?View $view = null)
	{
		$this->provisioner = $provisioner === null ? Provisioner::boot() : $provisioner;
		$this->view = $view === null ? new View() : $view;
		$this->request = $_REQUEST;
	}

	/**
	 * Commands accepted by ajaxRequest().
	 *
	 * @return array
	 */
	public static function commands()
	{
		return self::$commands;
	}

	/**
	 * Commands that write their own raw response (file downloads).
	 *
	 * @return array
	 */
	public static function rawCommands()
	{
		return array('downloadFile', 'exportTemplate');
	}

	/**
	 * Override the request data (tests).
	 *
	 * @return $this
	 */
	public function setRequest(array $request)
	{
		$this->request = $request;

		return $this;
	}

	/**
	 * Dispatch a command.
	 *
	 * @param string $command
	 * @return array
	 */
	public function handle($command)
	{
		if (!in_array($command, self::$commands, true)) {
			return $this->failure('Unknown command.');
		}

		try {
			return $this->$command();
		} catch (ValidationException $e) {
			return $this->failure($e->getMessage(), $e->getErrors());
		} catch (ProvisionerException $e) {
			return $this->failure($e->getMessage());
		} catch (\Exception $e) {
			$this->provisioner->freepbx()->log('Admin request failed: ' . $e->getMessage(), 'error');

			return $this->failure($e->getMessage());
		}
	}

	/**
	 * Raw responses (downloads). Returns true when it handled the request.
	 *
	 * @param string $command
	 * @return bool
	 */
	public function handleRaw($command)
	{
		if (!in_array($command, self::rawCommands(), true)) {
			return false;
		}

		try {
			if ($command === 'downloadFile') {
				return $this->downloadFileRaw();
			}

			return $this->exportTemplateRaw();
		} catch (\Exception $e) {
			if (!headers_sent()) {
				header('HTTP/1.1 500 Internal Server Error');
				header('Content-Type: text/plain');
			}

			echo $e->getMessage();

			return true;
		}
	}

	// ------------------------------------------------------------------ devices

	private function getDevices()
	{
		$devices = $this->provisioner->devices()->all(array(
			'search'     => $this->input('search', ''),
			'templateId' => $this->input('templateId', ''),
		));

		$rows = array();

		foreach ($devices as $device) {
			$rows[] = array(
				'id'                => $device->id,
				'name'              => $device->name,
				'identifier'        => $device->identifier,
				'extension'         => $device->extension,
				'mac'               => $device->mac,
				'macDisplay'        => Str::formatMac($device->mac, ':'),
				'vendor'            => $device->vendor,
				'model'             => $device->model,
				'templateId'        => $device->templateId,
				'templateName'      => $device->template === null ? '' : $device->template->name,
				'enabled'           => $device->enabled,
				'lastProvisionedAt' => $device->lastProvisionedAt,
				'lastProvisionedIp' => $device->lastProvisionedIp,
				'rootUrl'           => $this->provisioner->urls()->rootUrl($device->token),
			);
		}

		return $this->success('', array('rows' => $rows));
	}

	private function getDevice()
	{
		$device = $this->requireDevice();

		return $this->success('', array('device' => $device->toArray()));
	}

	/**
	 * The add/edit form, rendered server side so the markup lives in a view.
	 */
	private function deviceForm()
	{
		$id = (int) $this->input('id', 0);
		$device = $id > 0 ? $this->provisioner->devices()->find($id) : null;

		if ($id > 0 && $device === null) {
			return $this->failure('That device no longer exists.');
		}

		if ($device === null) {
			$device = new Device();
		}

		$templates = $this->provisioner->templates()->all(array('enabled' => 1));
		$selected = $device->templateId > 0 ? $device->template : null;

		if ($selected === null && $device->id === 0 && !empty($templates)) {
			$selected = $templates[0];
		}

		$html = $this->view->render('devices/form', array(
			'device'     => $device,
			'templates'  => $templates,
			'selected'   => $selected,
			'extensions' => $this->provisioner->freepbx()->extensions(),
			'schema'     => $selected === null ? array() : $selected->schema->toList(),
			'urls'       => $device->id > 0 ? $this->urlsForDevice($device) : array(),
		));

		return $this->success('', array('html' => $html));
	}

	private function saveDevice()
	{
		$payload = $this->payload();
		$device = Device::fromArray($payload);
		$saved = $this->provisioner->devices()->save($device);

		return $this->success(
			$device->id > 0 ? 'Device updated.' : 'Device created.',
			array('device' => $saved->toArray())
		);
	}

	private function deleteDevice()
	{
		$device = $this->requireDevice(false);
		$this->provisioner->devices()->delete($device->id);

		return $this->success('Device deleted.');
	}

	private function toggleDevice()
	{
		$device = $this->requireDevice(false);
		$enabled = $this->input('enabled', null);
		$enabled = $enabled === null ? !$device->enabled : (bool) (int) $enabled;
		$updated = $this->provisioner->devices()->setEnabled($device->id, $enabled);

		return $this->success(
			$enabled ? 'Device enabled.' : 'Device disabled - provisioning stops immediately.',
			array('device' => $updated->toArray())
		);
	}

	private function regenerateToken()
	{
		$device = $this->requireDevice(false);
		$updated = $this->provisioner->devices()->regenerateToken($device->id);
		$updated->template = $this->provisioner->templates()->find($updated->templateId);

		return $this->success(
			'A new token was issued. The previous provisioning URL no longer works.',
			array(
				'device' => $updated->toArray(),
				'urls'   => $this->urlsForDevice($updated),
			)
		);
	}

	/**
	 * Resolved parameters plus every rendered file.
	 */
	private function previewDevice()
	{
		$device = $this->requireDevice();
		$reveal = (bool) (int) $this->input('reveal', 0);

		$engine = $this->provisioner->engine();
		$context = $engine->contextFor($device);
		$files = array();
		$errors = array();

		try {
			$files = $engine->renderAll($device, $context);
		} catch (\Exception $e) {
			$errors[] = $e->getMessage();
		}

		$validation = $device->template === null
			? array()
			: $device->template->schema->validate($context->toArray());

		$html = $this->view->render('devices/preview', array(
			'device'     => $device,
			'rows'       => $context->toRows($reveal),
			'files'      => $files,
			'urls'       => $this->urlsForDevice($device, $context),
			'errors'     => $errors,
			'validation' => $validation,
			'reveal'     => $reveal,
		));

		return $this->success('', array('html' => $html));
	}

	private function deviceUrls()
	{
		$device = $this->requireDevice();

		return $this->success('', array('urls' => $this->urlsForDevice($device)));
	}

	/**
	 * Placeholder so the command list stays honest - the real work happens in
	 * handleRaw()/downloadFileRaw().
	 */
	private function downloadFile()
	{
		return $this->failure('This command returns a file and must be requested directly.');
	}

	private function downloadFileRaw()
	{
		$device = $this->provisioner->devices()->find((int) $this->input('id', 0));

		if ($device === null) {
			return false;
		}

		$file = $this->provisioner->engine()->renderFilename($device, $this->input('filename', ''));

		if (!headers_sent()) {
			header('Content-Type: ' . $file->contentType);
			header('Content-Disposition: attachment; filename="' . $file->filename . '"');
			header('Content-Length: ' . $file->size());
		}

		echo $file->content;

		return true;
	}

	// ---------------------------------------------------------------- templates

	private function getTemplates()
	{
		$templates = $this->provisioner->templates()->all(array(
			'search' => $this->input('search', ''),
			'vendor' => $this->input('vendor', ''),
		));

		$rows = array();

		foreach ($templates as $template) {
			$rows[] = $template->toSummaryArray();
		}

		return $this->success('', array('rows' => $rows, 'vendors' => $this->provisioner->templates()->vendors()));
	}

	private function getTemplate()
	{
		$template = $this->requireTemplate();

		return $this->success('', array('template' => $template->toArray()));
	}

	private function templateForm()
	{
		$id = (int) $this->input('id', 0);
		$template = $id > 0 ? $this->provisioner->templates()->find($id) : null;

		if ($id > 0 && $template === null) {
			return $this->failure('That template no longer exists.');
		}

		if ($template === null) {
			$template = new Template();
			$output = new Output();
			$output->filename = 'config.json';
			$output->contentType = 'application/json';
			$template->outputs = array($output);
		}

		$html = $this->view->render('templates/form', array(
			'template'     => $template,
			'contentTypes' => Output::CONTENT_TYPES,
			'schemaRows'   => $template->schema->toList(),
		));

		return $this->success('', array('html' => $html));
	}

	private function saveTemplate()
	{
		$payload = $this->payload();
		$template = Template::fromArray($payload);
		$saved = $this->provisioner->templates()->save($template);

		return $this->success(
			$template->id > 0 ? 'Template updated.' : 'Template created.',
			array('template' => $saved->toArray())
		);
	}

	private function deleteTemplate()
	{
		$template = $this->requireTemplate();
		$this->provisioner->templates()->delete($template->id, (bool) (int) $this->input('force', 0));

		return $this->success('Template deleted.');
	}

	private function cloneTemplate()
	{
		$template = $this->requireTemplate();
		$copy = $this->provisioner->templates()->duplicate($template->id);

		return $this->success('Template copied.', array('template' => $copy->toArray()));
	}

	private function exportTemplate()
	{
		$template = $this->requireTemplate();

		return $this->success('', array('export' => $template->toExportArray()));
	}

	private function exportTemplateRaw()
	{
		$template = $this->provisioner->templates()->find((int) $this->input('id', 0));

		if ($template === null) {
			return false;
		}

		if (!headers_sent()) {
			header('Content-Type: application/json');
			header('Content-Disposition: attachment; filename="' . $template->slug . '.json"');
		}

		echo Json::pretty($template->toExportArray());

		return true;
	}

	private function importTemplate()
	{
		$raw = $this->input('json', '');
		$definition = Json::decode($raw);

		if (empty($definition)) {
			return $this->failure('That does not look like a template export.');
		}

		if (empty($definition['outputs'])) {
			return $this->failure('The import needs at least one output.');
		}

		$template = Template::fromArray($definition);
		$template->id = 0;
		$template->builtin = false;
		$saved = $this->provisioner->templates()->save($template);

		return $this->success('Template imported.', array('template' => $saved->toArray()));
	}

	private function seedTemplates()
	{
		$result = $this->provisioner->seeder()->seed((bool) (int) $this->input('force', 0));
		$message = sprintf(
			'%d created, %d replaced, %d left alone.',
			count($result['created']),
			count($result['updated']),
			count($result['skipped'])
		);

		return $this->success($message, array('result' => $result));
	}

	/**
	 * Parameter schema for a template, used to build the device form.
	 */
	private function templateSchema()
	{
		$template = $this->requireTemplate();

		$html = $this->view->render('devices/parameters', array(
			'schema'     => $template->schema->toList(),
			'parameters' => array(),
			'template'   => $template,
		));

		return $this->success('', array(
			'schema'   => $template->schema->toList(),
			'defaults' => $template->resolvedDefaults(),
			'html'     => $html,
		));
	}

	// --------------------------------------------------------------------- logs

	private function getLogs()
	{
		$rows = $this->provisioner->logs()->recent(array(
			'limit'  => (int) $this->input('limit', 200),
			'status' => $this->input('status', ''),
			'search' => $this->input('search', ''),
		));

		return $this->success('', array(
			'rows'  => $rows,
			'stats' => $this->provisioner->logs()->stats(),
		));
	}

	private function clearLogs()
	{
		$this->provisioner->logs()->clear();

		return $this->success('Provisioning log cleared.');
	}

	// ----------------------------------------------------------------- settings

	private function getSettings()
	{
		return $this->success('', array(
			'settings' => $this->provisioner->settings()->refresh(),
			'friendly' => $this->friendlyInstaller()->status(),
		));
	}

	private function saveSettings()
	{
		$payload = $this->payload();
		$allowed = array_keys(Settings::definitions());
		$values = array();

		foreach ($allowed as $key) {
			if (array_key_exists($key, $payload)) {
				$values[$key] = $payload[$key];
				continue;
			}

			// Unchecked checkboxes are simply absent from the payload.
			$definitions = Settings::definitions();

			if ($definitions[$key]['type'] === 'bool') {
				$values[$key] = 0;
			}
		}

		$this->provisioner->settings()->setMany($values);

		return $this->success('Settings saved.', array(
			'settings' => $this->provisioner->settings()->refresh(),
		));
	}

	private function installFriendlyUrl()
	{
		return $this->success('Friendly URL installed.', array('friendly' => $this->friendlyInstaller()->install()));
	}

	private function removeFriendlyUrl()
	{
		return $this->success('Friendly URL removed.', array('friendly' => $this->friendlyInstaller()->remove()));
	}

	private function getExtensions()
	{
		return $this->success('', array('extensions' => $this->provisioner->freepbx()->extensions()));
	}

	/**
	 * Counters for the page header.
	 */
	private function getSummary()
	{
		$devices = $this->provisioner->devices()->all();
		$enabled = 0;

		foreach ($devices as $device) {
			if ($device->enabled) {
				$enabled++;
			}
		}

		return $this->success('', array(
			'devices'   => count($devices),
			'enabled'   => $enabled,
			'templates' => count($this->provisioner->templates()->all()),
			'log'       => $this->provisioner->logs()->stats(),
		));
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * @return \Oryk\Provisioner\Model\Device
	 * @throws ValidationException
	 */
	private function requireDevice($withTemplate = true)
	{
		$device = $this->provisioner->devices()->find((int) $this->input('id', 0), $withTemplate);

		if ($device === null) {
			throw new ValidationException('That device no longer exists.');
		}

		return $device;
	}

	/**
	 * @return \Oryk\Provisioner\Model\Template
	 * @throws ValidationException
	 */
	private function requireTemplate()
	{
		$id = (int) $this->input('id', 0);
		$template = $id > 0
			? $this->provisioner->templates()->find($id)
			: $this->provisioner->templates()->findBySlug($this->input('slug', ''));

		if ($template === null) {
			throw new ValidationException('That template no longer exists.');
		}

		return $template;
	}

	/**
	 * Every provisioning URL for a device, one entry per output file.
	 *
	 * @return array
	 */
	private function urlsForDevice($device, ?Context $context = null)
	{
		$engine = $this->provisioner->engine();
		$builder = $this->provisioner->urls();
		$urls = array();

		foreach ($engine->filenamesFor($device, $context) as $entry) {
			$urls[] = array(
				'filename'    => $entry['filename'],
				'template'    => $entry['template'],
				'contentType' => $entry['contentType'],
				'urls'        => $builder->allFor($device->token, $entry['filename']),
			);
		}

		return $urls;
	}

	/**
	 * @return FriendlyUrlInstaller
	 */
	private function friendlyInstaller()
	{
		return new FriendlyUrlInstaller($this->provisioner->settings(), $this->provisioner->freepbx());
	}

	/**
	 * Decode the JSON body sent by the admin forms.
	 *
	 * @return array
	 */
	private function payload()
	{
		$raw = $this->input('payload', '');
		$payload = Json::decode($raw);

		return empty($payload) ? array() : $payload;
	}

	/**
	 * @return mixed
	 */
	private function input($key, $default = null)
	{
		return isset($this->request[$key]) ? $this->request[$key] : $default;
	}

	private function success($message = '', array $data = array())
	{
		return array_merge(array('status' => true, 'message' => $message), $data);
	}

	private function failure($message, array $errors = array())
	{
		return array('status' => false, 'message' => $message, 'errors' => $errors);
	}
}
