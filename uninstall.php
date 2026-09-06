<?php
/**
 * Oryk Provisioner - uninstaller.
 *
 * Drops every table the module owns. Devices, templates and provisioning
 * tokens are removed with it, so take a backup first if the data matters.
 */

if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

require_once __DIR__ . '/lib/autoload.php';

try {
	$oryk_provisioner = \Oryk\Provisioner\Provisioner::boot(\FreePBX::Database());

	// Take the friendly URL shim out of the web root as well.
	$oryk_friendly = new \Oryk\Provisioner\Admin\FriendlyUrlInstaller(
		$oryk_provisioner->settings(),
		$oryk_provisioner->freepbx()
	);
	$oryk_friendly->remove();

	$oryk_provisioner->uninstall();

	if (function_exists('out')) {
		out('Oryk Provisioner: tables removed.');
	}
} catch (\Exception $e) {
	if (function_exists('out')) {
		out('Oryk Provisioner: uninstall failed - ' . $e->getMessage());
	}

	throw $e;
}

unset($oryk_friendly);
