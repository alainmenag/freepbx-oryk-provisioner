<?php

namespace Oryk\Provisioner\Provisioning;

use Oryk\Provisioner\Exception\NotFoundException;
use Oryk\Provisioner\Exception\RenderException;
use Oryk\Provisioner\Freepbx\Facade;
use Oryk\Provisioner\Model\Device;
use Oryk\Provisioner\Model\Output;
use Oryk\Provisioner\Repository\DeviceRepository;
use Oryk\Provisioner\Repository\LogRepository;
use Oryk\Provisioner\Settings;
use Oryk\Provisioner\Support\Str;
use Oryk\Provisioner\Template\Renderer;

/**
 * The provisioning engine.
 *
 * This is the single implementation used by the admin preview, the GraphQL API,
 * the FreePBX provisioning URL and the friendly URL, so provisioning behaves
 * identically no matter how a configuration was requested.
 */
class Engine
{
	/** @var DeviceRepository */
	private $devices;

	/** @var ParameterResolver */
	private $resolver;

	/** @var Renderer */
	private $renderer;

	/** @var Settings */
	private $settings;

	/** @var LogRepository */
	private $logs;

	/** @var Facade */
	private $freepbx;

	public function __construct(
		DeviceRepository $devices,
		ParameterResolver $resolver,
		Renderer $renderer,
		Settings $settings,
		?LogRepository $logs = null,
		?Facade $freepbx = null
	) {
		$this->devices = $devices;
		$this->resolver = $resolver;
		$this->renderer = $renderer;
		$this->settings = $settings;
		$this->logs = $logs;
		$this->freepbx = $freepbx === null ? new Facade() : $freepbx;

		$this->renderer->setStrict((bool) $settings->get('strict_rendering'));
	}

	/**
	 * Resolve the parameter context for a device.
	 *
	 * @return Context
	 */
	public function contextFor(Device $device)
	{
		return $this->resolver->resolve($device);
	}

	/**
	 * Render every file the device's template produces.
	 *
	 * @param Device       $device
	 * @param Context|null $context Reused when the caller already resolved it.
	 * @return RenderedFile[]
	 * @throws NotFoundException
	 */
	public function renderAll(Device $device, ?Context $context = null)
	{
		$template = $device->template;

		if ($template === null) {
			throw new NotFoundException('No configuration available', 'device has no template');
		}

		$context = $context === null ? $this->contextFor($device) : $context;
		$files = array();

		foreach ($template->outputs as $output) {
			$files[] = $this->renderOutput($output, $context);
		}

		return $files;
	}

	/**
	 * Render a single output against an already resolved context.
	 *
	 * @throws RenderException
	 * @return RenderedFile
	 */
	public function renderOutput(Output $output, Context $context)
	{
		$values = $context->toArray();

		$file = new RenderedFile();
		$file->outputId = $output->id;
		$file->filenameTemplate = $output->filename;
		$file->contentType = $output->contentType;
		$file->filename = trim($this->renderer->renderRaw($output->filename, $values));
		$file->content = $this->renderer->render($output->body, $values, $output->contentType);
		$file->missing = $this->renderer->missing();

		if ($file->filename === '') {
			throw new RenderException(sprintf(
				'Output "%s" resolved to an empty filename.',
				$output->filename
			));
		}

		return $file;
	}

	/**
	 * Render the one file a device asked for.
	 *
	 * @param Device $device
	 * @param string $filename
	 * @return RenderedFile
	 * @throws NotFoundException|RenderException
	 */
	public function renderFilename(Device $device, $filename)
	{
		$template = $device->template;

		if ($template === null || empty($template->outputs)) {
			throw new NotFoundException('No configuration available', 'template missing or has no outputs');
		}

		$context = $this->contextFor($device);
		$values = $context->toArray();

		foreach ($template->outputs as $output) {
			$resolved = trim($this->renderer->renderRaw($output->filename, $values));

			// Match the resolved name, and also the literal template so a
			// template author can test with the unexpanded name.
			if (Str::equalsIgnoreCase($resolved, $filename)
				|| Str::equalsIgnoreCase($output->filename, $filename)) {
				return $this->renderOutput($output, $context);
			}
		}

		throw new NotFoundException('No configuration available', 'no output matched ' . $filename);
	}

	/**
	 * Serve a provisioning request.
	 *
	 * Validate token -> resolve device -> check enabled -> resolve template ->
	 * resolve parameters -> match requested output -> render.
	 *
	 * @param string $token
	 * @param string $filename
	 * @param array  $request  ip, userAgent, secure
	 * @return RenderedFile
	 * @throws NotFoundException|RenderException
	 */
	public function provision($token, $filename, array $request = array())
	{
		$ip = isset($request['ip']) ? $request['ip'] : '';
		$userAgent = isset($request['userAgent']) ? $request['userAgent'] : '';
		$filename = Str::sanitizeFilename($filename);

		if ($filename === '') {
			$this->logAttempt(null, '', LogRepository::STATUS_NOTFOUND, 404, 'invalid filename', $request);

			throw new NotFoundException('Not found', 'invalid filename');
		}

		if ($this->settings->get('require_https') && empty($request['secure'])) {
			$this->logAttempt(null, $filename, LogRepository::STATUS_DENIED, 404, 'https required', $request);

			throw new NotFoundException('Not found', 'https required');
		}

		if (!$this->networkAllowed($ip)) {
			$this->logAttempt(null, $filename, LogRepository::STATUS_DENIED, 404, 'network not allowed', $request);

			throw new NotFoundException('Not found', 'network not allowed');
		}

		$device = $this->devices->findByToken($token);

		if ($device === null) {
			$this->logAttempt(null, $filename, LogRepository::STATUS_NOTFOUND, 404, 'unknown token', $request);

			throw new NotFoundException('Not found', 'unknown token');
		}

		if (!$device->enabled) {
			$this->logAttempt($device, $filename, LogRepository::STATUS_DENIED, 404, 'device disabled', $request);

			throw new NotFoundException('Not found', 'device disabled');
		}

		try {
			$file = $this->renderFilename($device, $filename);
		} catch (NotFoundException $e) {
			$this->logAttempt($device, $filename, LogRepository::STATUS_NOTFOUND, 404, $e->getReason(), $request);

			throw $e;
		} catch (\Exception $e) {
			// Detail is logged internally, never returned to the endpoint.
			$this->freepbx->log(sprintf(
				'Rendering failed for device %s (%s): %s',
				$device->identifier,
				$filename,
				$e->getMessage()
			), 'error');
			$this->logAttempt($device, $filename, LogRepository::STATUS_ERROR, 500, 'render failed', $request);

			throw new RenderException('Configuration could not be generated.');
		}

		$this->devices->touchProvisioned($device->id, $ip);
		$this->logAttempt($device, $file->filename, LogRepository::STATUS_SUCCESS, 200, '', $request);

		return $file;
	}

	/**
	 * The filenames a device can request, with their URLs.
	 *
	 * @return array
	 */
	public function filenamesFor(Device $device, ?Context $context = null)
	{
		$template = $device->template;

		if ($template === null) {
			return array();
		}

		$values = ($context === null ? $this->contextFor($device) : $context)->toArray();
		$filenames = array();

		foreach ($template->outputs as $output) {
			$filenames[] = array(
				'outputId'    => $output->id,
				'template'    => $output->filename,
				'filename'    => trim($this->renderer->renderRaw($output->filename, $values)),
				'contentType' => $output->contentType,
			);
		}

		return $filenames;
	}

	/**
	 * Optional source network restriction (comma separated IPs or CIDRs).
	 */
	private function networkAllowed($ip)
	{
		$allowed = trim((string) $this->settings->get('allowed_networks'));

		if ($allowed === '' || $ip === '') {
			return true;
		}

		foreach (preg_split('/[\s,]+/', $allowed) as $network) {
			$network = trim($network);

			if ($network !== '' && $this->ipInRange($ip, $network)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * IPv4 CIDR / exact match. IPv6 is compared literally.
	 */
	private function ipInRange($ip, $range)
	{
		if (strpos($range, '/') === false) {
			return $ip === $range;
		}

		list($subnet, $bits) = explode('/', $range, 2);
		$ipLong = ip2long($ip);
		$subnetLong = ip2long($subnet);
		$bits = (int) $bits;

		if ($ipLong === false || $subnetLong === false || $bits < 0 || $bits > 32) {
			return false;
		}

		$mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

		return ($ipLong & $mask) === ($subnetLong & $mask);
	}

	/**
	 * Write a log row, when logging is enabled.
	 *
	 * @param Device|null $device
	 */
	private function logAttempt($device, $filename, $status, $httpCode, $message, array $request)
	{
		if ($this->logs === null || !$this->settings->get('log_enabled')) {
			return;
		}

		$this->logs->record(array(
			'deviceId'   => $device === null ? null : $device->id,
			'identifier' => $device === null ? '' : $device->identifier,
			'filename'   => $filename,
			'status'     => $status,
			'httpCode'   => $httpCode,
			'message'    => $message,
			'ip'         => isset($request['ip']) ? $request['ip'] : '',
			'userAgent'  => isset($request['userAgent']) ? $request['userAgent'] : '',
		));
	}
}
