<?php

namespace Oryk\Provisioner\Admin;

use Oryk\Provisioner\Exception\ValidationException;
use Oryk\Provisioner\Freepbx\Facade;
use Oryk\Provisioner\Settings;

/**
 * Optional friendly provisioning URL: /provisioner/{token}/{filename}
 *
 * It is a thin shim in the web root that hands the request to the same
 * provisioning endpoint - not a second implementation. Installing it needs a
 * writable web root and, for the rewrite, AllowOverride to be enabled.
 */
class FriendlyUrlInstaller
{
	const DIRECTORY = 'provisioner';

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
	 * Where the shim lives.
	 */
	public function path()
	{
		return $this->freepbx->webRoot() . '/' . self::DIRECTORY;
	}

	/**
	 * Current state, for the settings page.
	 *
	 * @return array
	 */
	public function status()
	{
		$path = $this->path();

		return array(
			'path'      => $path,
			'installed' => is_file($path . '/index.php'),
			'writable'  => is_writable(dirname($path)),
			'enabled'   => (bool) $this->settings->get('friendly_url_enabled'),
		);
	}

	/**
	 * Create the shim and enable the setting.
	 *
	 * @throws ValidationException
	 * @return array status
	 */
	public function install()
	{
		$path = $this->path();

		if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
			throw new ValidationException(sprintf(
				'Could not create %s. Create it manually, or leave the friendly URL disabled.',
				$path
			));
		}

		if (@file_put_contents($path . '/index.php', $this->indexContents()) === false) {
			throw new ValidationException(sprintf('Could not write %s/index.php.', $path));
		}

		if (@file_put_contents($path . '/.htaccess', $this->htaccessContents()) === false) {
			throw new ValidationException(sprintf('Could not write %s/.htaccess.', $path));
		}

		$this->settings->set('friendly_url_enabled', 1);

		return $this->status();
	}

	/**
	 * Remove the shim and disable the setting.
	 *
	 * @return array status
	 */
	public function remove()
	{
		$path = $this->path();

		foreach (array('index.php', '.htaccess') as $file) {
			if (is_file($path . '/' . $file)) {
				@unlink($path . '/' . $file);
			}
		}

		if (is_dir($path)) {
			@rmdir($path);
		}

		$this->settings->set('friendly_url_enabled', 0);

		return $this->status();
	}

	/**
	 * The shim: hand everything to the module endpoint.
	 */
	private function indexContents()
	{
		$endpoint = $this->freepbx->webRoot() . '/admin/modules/oryk_provisioner/provision.php';

		return "<?php\n"
			. "/**\n"
			. " * Oryk Provisioner friendly URL shim - generated file, safe to delete.\n"
			. " *\n"
			. " * /provisioner/{token}/{filename} is handed to the module provisioning\n"
			. " * endpoint, which is the same engine used everywhere else.\n"
			. " */\n\n"
			. "require_once " . var_export($endpoint, true) . ";\n";
	}

	/**
	 * Route every path below /provisioner/ to the shim.
	 */
	private function htaccessContents()
	{
		return "# Oryk Provisioner friendly URL - generated file, safe to delete.\n"
			. "<IfModule mod_rewrite.c>\n"
			. "\tRewriteEngine On\n"
			. "\tRewriteBase /" . self::DIRECTORY . "/\n"
			. "\tRewriteCond %{REQUEST_FILENAME} !-f\n"
			. "\tRewriteRule ^(.*)$ index.php [QSA,L]\n"
			. "</IfModule>\n";
	}
}
