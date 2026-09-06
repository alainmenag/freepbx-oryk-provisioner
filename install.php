<?php
/**
 * Oryk Provisioner - installer.
 *
 * Creates (or upgrades) the module tables and loads the bundled templates.
 * Safe to run repeatedly: the schema is created with IF NOT EXISTS, migrations
 * are guarded, and seeding never overwrites an edited template.
 */

if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

require_once __DIR__ . '/lib/autoload.php';

$oryk_install_out = function ($message) {
	if (function_exists('out')) {
		out($message);

		return;
	}

	echo $message . "\n";
};

try {
	$oryk_provisioner = \Oryk\Provisioner\Provisioner::boot(\FreePBX::Database());
	$oryk_seeded = $oryk_provisioner->install();

	$oryk_install_out('Oryk Provisioner: database schema is up to date.');

	if (!empty($oryk_seeded['created'])) {
		$oryk_install_out('Oryk Provisioner: added templates - ' . implode(', ', $oryk_seeded['created']));
	}

	if (!empty($oryk_seeded['skipped'])) {
		$oryk_install_out('Oryk Provisioner: kept existing templates - ' . implode(', ', $oryk_seeded['skipped']));
	}
} catch (\Exception $e) {
	$oryk_install_out('Oryk Provisioner: install failed - ' . $e->getMessage());

	throw $e;
}

unset($oryk_install_out, $oryk_seeded);
