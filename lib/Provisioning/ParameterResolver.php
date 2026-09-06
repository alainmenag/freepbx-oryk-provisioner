<?php

namespace Oryk\Provisioner\Provisioning;

use Oryk\Provisioner\Freepbx\Facade;
use Oryk\Provisioner\Model\Device;
use Oryk\Provisioner\Settings;
use Oryk\Provisioner\Support\Str;

/**
 * Builds the resolved parameter context for a device.
 *
 * Resolution order (lowest priority first), exactly as documented:
 *
 *   module settings  ->  schema defaults  ->  template defaults
 *      ->  FreePBX / extension values  ->  device values  ->  device parameters
 *
 * Device level parameters therefore always win.
 */
class ParameterResolver
{
	/** @var Settings */
	private $settings;

	/** @var Facade */
	private $freepbx;

	/** @var UrlBuilder */
	private $urls;

	public function __construct(Settings $settings, ?Facade $freepbx = null, ?UrlBuilder $urls = null)
	{
		$this->settings = $settings;
		$this->freepbx = $freepbx === null ? new Facade() : $freepbx;
		$this->urls = $urls === null ? new UrlBuilder($settings, $this->freepbx) : $urls;
	}

	/**
	 * @param Device $device Must carry a hydrated template.
	 * @return Context
	 */
	public function resolve(Device $device)
	{
		$context = new Context();
		$template = $device->template;

		// 1. Module wide defaults.
		$context->merge($this->systemValues(), Context::SOURCE_SYSTEM);

		if ($template !== null) {
			// 2. Defaults declared by the parameter schema.
			$context->merge($template->schema->defaults(), Context::SOURCE_SCHEMA);

			// 3. Explicit template defaults.
			$context->merge($template->defaults, Context::SOURCE_TEMPLATE);

			$context->merge(array(
				'template.name'   => $template->name,
				'template.slug'   => $template->slug,
				'template.vendor' => $template->vendor,
				'template.family' => $template->family,
			), Context::SOURCE_TEMPLATE);

			$context->markSecret($template->schema->secrets());
		}

		// 4. FreePBX extension and SIP configuration.
		$context->merge($this->freepbxValues($device, $context), Context::SOURCE_FREEPBX);

		// 5. The device record itself.
		$context->merge($device->contextValues(), Context::SOURCE_DEVICE);

		// 6. Device level overrides - highest priority.
		$context->merge($device->parameters, Context::SOURCE_OVERRIDE);

		// Anything that can only be computed once everything else is known.
		$this->applyDerived($device, $context);

		// Passwords are always treated as secret, whether or not the template
		// bothered to declare them.
		$context->markSecret(array('sip.password', 'sip.secret', 'user.password'));

		return $context;
	}

	/**
	 * Module settings and clock values.
	 *
	 * @return array
	 */
	private function systemValues()
	{
		$transport = strtolower((string) $this->settings->get('sip_transport'));
		$transport = in_array($transport, array('udp', 'tcp', 'tls'), true) ? $transport : 'udp';

		$values = array(
			'sip.transport'    => $transport,
			'sip.port'         => (string) $this->settings->get('sip_port'),
			'sip.domain'       => (string) $this->settings->get('sip_domain'),
			'system.timestamp' => time(),
			'system.date'      => date('Y-m-d'),
			'system.time'      => date('H:i:s'),
			'system.timezone'  => date_default_timezone_get(),
		);

		if ($values['sip.port'] === '') {
			$values['sip.port'] = '5060';
		}

		return $values;
	}

	/**
	 * FreePBX supplied values for the device's extension, plus server details.
	 *
	 * @return array
	 */
	private function freepbxValues(Device $device, Context $context)
	{
		$values = $this->freepbx->extensionParameters($device->extension);

		$address = trim((string) $this->settings->get('server_address'));

		if ($address === '') {
			$address = $this->freepbx->serverAddress();
		}

		if ($address !== '') {
			$values['server.address'] = $address;
		}

		$transport = $context->get('sip.transport', 'udp');
		$values['server.port'] = $this->freepbx->sipPort($transport);

		if (!isset($values['sip.port']) && $values['server.port'] !== '') {
			$values['sip.port'] = $values['server.port'];
		}

		if ($this->settings->get('directory_enabled')) {
			$values['directory'] = $this->directory($device);
		}

		return $values;
	}

	/**
	 * Values computed from the fully resolved context.
	 */
	private function applyDerived(Device $device, Context $context)
	{
		$address = (string) $context->get('server.address', '');

		if ($address === '') {
			$address = $this->urls->baseUrl();
			$address = preg_replace('#^https?://#i', '', $address);
			$context->set('server.address', $address, Context::SOURCE_DERIVED);
		}

		// A SIP domain always has a value: fall back to the server address.
		if ((string) $context->get('sip.domain', '') === '') {
			$context->set('sip.domain', $address, Context::SOURCE_DERIVED);
		}

		$context->fill('sip.username', $device->extension, Context::SOURCE_DERIVED);
		$context->fill('sip.auth_username', $context->get('sip.username', ''), Context::SOURCE_DERIVED);
		$context->fill('user.extension', $device->extension, Context::SOURCE_DERIVED);
		$context->fill('user.display_name', $device->name, Context::SOURCE_DERIVED);

		$transport = strtolower((string) $context->get('sip.transport', 'udp'));
		$context->set('sip.transport_upper', strtoupper($transport), Context::SOURCE_DERIVED);
		$context->set('sip.secure', $transport === 'tls', Context::SOURCE_DERIVED);

		$context->set('server.protocol', $transport === 'tls' ? 'sips' : 'sip', Context::SOURCE_DERIVED);
		$context->fill('server.port', $context->get('sip.port', '5060'), Context::SOURCE_DERIVED);

		$context->set('device.mac_lower', strtolower((string) $context->get('device.mac', '')), Context::SOURCE_DERIVED);
		$context->set(
			'device.mac_colon',
			Str::formatMac((string) $context->get('device.mac', ''), ':'),
			Context::SOURCE_DERIVED
		);

		// Provisioning URLs, useful for phones that need to know their own
		// configuration server, and for the admin interface.
		$context->set('provisioning.base_url', $this->urls->baseUrl(), Context::SOURCE_DERIVED);
		$context->set('provisioning.root_url', $this->urls->rootUrl($device->token), Context::SOURCE_DERIVED);
		$context->set('provisioning.token', $device->token, Context::SOURCE_DERIVED);
	}

	/**
	 * Extension directory, available to templates as {{#each directory}}.
	 *
	 * @return array
	 */
	private function directory(Device $device)
	{
		$limit = (int) $this->settings->get('directory_limit');
		$limit = $limit > 0 ? $limit : 200;
		$entries = array();

		foreach ($this->freepbx->extensions() as $entry) {
			if (count($entries) >= $limit) {
				break;
			}

			// A phone does not need itself in its own directory.
			if ($device->extension !== '' && $entry['extension'] === $device->extension) {
				continue;
			}

			$entries[] = array(
				'extension' => $entry['extension'],
				'name'      => $entry['name'] !== '' ? $entry['name'] : $entry['extension'],
			);
		}

		return $entries;
	}
}
