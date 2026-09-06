<?php

namespace Oryk\Provisioner\Provisioning;

use Oryk\Provisioner\Freepbx\Facade;
use Oryk\Provisioner\Settings;

/**
 * Builds the provisioning URLs a device can be pointed at.
 *
 * All three URLs reach the same provisioning engine:
 *   /admin/config.php?display=oryk_provisioner&token={token}&filename={file}
 *   /admin/modules/oryk_provisioner/provision.php?token={token}&filename={file}
 *   /provisioner/{token}/{file}                       (requires URL rewriting)
 */
class UrlBuilder
{
	const MODULE_PATH = '/admin/modules/oryk_provisioner/provision.php';
	const CONFIG_PATH = '/admin/config.php';
	const FRIENDLY_PREFIX = '/provisioner';

	/** @var Settings */
	private $settings;

	/** @var Facade */
	private $freepbx;

	public function __construct(Settings $settings, ?Facade $freepbx = null)
	{
		$this->settings = $settings;
		$this->freepbx = $freepbx === null ? new Facade() : $freepbx;
	}

	/**
	 * Scheme + host + optional port, without a trailing slash.
	 *
	 * @return string
	 */
	public function baseUrl()
	{
		$host = trim((string) $this->settings->get('server_address'));

		if ($host === '') {
			$host = $this->requestHost();
		}

		if ($host === '') {
			$host = $this->freepbx->serverAddress();
		}

		if ($host === '') {
			return '';
		}

		// An address that already carries a scheme is used verbatim.
		if (preg_match('#^https?://#i', $host)) {
			return rtrim($host, '/');
		}

		$protocol = strtolower((string) $this->settings->get('server_protocol'));

		if ($protocol !== 'http' && $protocol !== 'https') {
			$protocol = $this->requestIsSecure() ? 'https' : 'http';
		}

		$port = trim((string) $this->settings->get('server_port'));
		$url = $protocol . '://' . $host;

		if ($port !== '' && !$this->isDefaultPort($protocol, $port)) {
			$url .= ':' . $port;
		}

		return $url;
	}

	/**
	 * Canonical FreePBX provisioning URL.
	 */
	public function configUrl($token, $filename)
	{
		return $this->baseUrl() . self::CONFIG_PATH
			. '?display=oryk_provisioner&token=' . rawurlencode($token)
			. '&filename=' . rawurlencode($filename);
	}

	/**
	 * Direct module endpoint - always available, never behind admin auth.
	 */
	public function directUrl($token, $filename)
	{
		return $this->baseUrl() . self::MODULE_PATH
			. '?token=' . rawurlencode($token)
			. '&filename=' . rawurlencode($filename);
	}

	/**
	 * Friendly URL. Only resolvable when the rewrite has been installed.
	 */
	public function friendlyUrl($token, $filename)
	{
		return $this->baseUrl() . self::FRIENDLY_PREFIX . '/' . rawurlencode($token) . '/' . rawurlencode($filename);
	}

	/**
	 * Every URL flavour for one file.
	 *
	 * @return array
	 */
	public function allFor($token, $filename)
	{
		$urls = array(
			'direct' => $this->directUrl($token, $filename),
			'config' => $this->configUrl($token, $filename),
		);

		if ($this->settings->get('friendly_url_enabled')) {
			$urls['friendly'] = $this->friendlyUrl($token, $filename);
		}

		return $urls;
	}

	/**
	 * The URL a device should be given as its provisioning root. Devices that
	 * append their own filename (most desk phones) use this.
	 */
	public function rootUrl($token)
	{
		if ($this->settings->get('friendly_url_enabled')) {
			return $this->baseUrl() . self::FRIENDLY_PREFIX . '/' . rawurlencode($token);
		}

		return $this->baseUrl() . self::MODULE_PATH . '/' . rawurlencode($token);
	}

	private function requestHost()
	{
		if (!empty($_SERVER['HTTP_HOST'])) {
			$host = (string) $_SERVER['HTTP_HOST'];

			// Strip a port; it is added back from settings/scheme below.
			if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
				$host = substr($host, 0, strpos($host, ':'));
			}

			return $host;
		}

		if (!empty($_SERVER['SERVER_NAME'])) {
			return (string) $_SERVER['SERVER_NAME'];
		}

		return '';
	}

	private function requestIsSecure()
	{
		if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
			return true;
		}

		if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
			&& strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
			return true;
		}

		return !empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
	}

	private function isDefaultPort($protocol, $port)
	{
		return ($protocol === 'http' && (int) $port === 80)
			|| ($protocol === 'https' && (int) $port === 443);
	}
}
