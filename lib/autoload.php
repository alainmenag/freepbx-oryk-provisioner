<?php
/**
 * Oryk Provisioner - PSR-4 autoloader.
 *
 * The module namespace `Oryk\Provisioner\` maps to this directory. Keeping our
 * own autoloader means the provisioning engine can be bootstrapped from the
 * FreePBX admin (BMO), from the standalone provisioning endpoint, from the
 * GraphQL API, or from a CLI test harness without depending on FreePBX's
 * class loader.
 *
 * @package   Oryk\Provisioner
 * @license   AGPLv3
 */

if (!defined('ORYK_PROVISIONER_LIB')) {
	define('ORYK_PROVISIONER_LIB', __DIR__);
	define('ORYK_PROVISIONER_ROOT', dirname(__DIR__));

	spl_autoload_register(function ($class) {
		$prefix = 'Oryk\\Provisioner\\';
		$length = strlen($prefix);

		if (strncmp($prefix, $class, $length) !== 0) {
			return;
		}

		$relative = substr($class, $length);
		$file = ORYK_PROVISIONER_LIB . '/' . str_replace('\\', '/', $relative) . '.php';

		if (is_readable($file)) {
			require_once $file;
		}
	});
}
