<?php
/**
 * Test bootstrap for the Oryk Provisioner engine.
 *
 * The provisioning library has no hard dependency on FreePBX, so it can be
 * exercised from the CLI against an in-memory SQLite database:
 *
 *   php tests/run.php
 */

require_once dirname(__DIR__) . '/lib/autoload.php';

use Oryk\Provisioner\Provisioner;

/** @var int */
$GLOBALS['oryk_passed'] = 0;
/** @var int */
$GLOBALS['oryk_failed'] = 0;

/**
 * Assert a condition.
 *
 * @param string $label
 * @param bool   $condition
 * @param string $detail Printed when the check fails.
 */
function oryk_check($label, $condition, $detail = '')
{
	if ($condition) {
		$GLOBALS['oryk_passed']++;
		echo "  ok   $label\n";

		return;
	}

	$GLOBALS['oryk_failed']++;
	echo "  FAIL $label" . ($detail === '' ? '' : " :: $detail") . "\n";
}

/**
 * A container backed by a throwaway SQLite database.
 *
 * @return Provisioner
 */
function oryk_test_container()
{
	$pdo = new PDO('sqlite::memory:');
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$tables = array(
		'CREATE TABLE oryk_provisioner_templates (
			id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT, name TEXT, vendor TEXT, family TEXT,
			description TEXT, defaults TEXT, parameters TEXT, enabled INTEGER DEFAULT 1,
			builtin INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)',
		'CREATE TABLE oryk_provisioner_template_outputs (
			id INTEGER PRIMARY KEY AUTOINCREMENT, template_id INTEGER, filename TEXT,
			content_type TEXT, body TEXT, sort_order INTEGER DEFAULT 0)',
		'CREATE TABLE oryk_provisioner_devices (
			id INTEGER PRIMARY KEY AUTOINCREMENT, identifier TEXT, name TEXT, template_id INTEGER,
			extension TEXT, mac TEXT, vendor TEXT, model TEXT, enabled INTEGER DEFAULT 1, token TEXT,
			parameters TEXT, notes TEXT, last_provisioned_at TEXT, last_provisioned_ip TEXT,
			created_at TEXT, updated_at TEXT)',
		'CREATE TABLE oryk_provisioner_logs (
			id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT, device_id INTEGER, identifier TEXT,
			filename TEXT, status TEXT, http_code INTEGER, message TEXT, ip TEXT, user_agent TEXT)',
		'CREATE TABLE oryk_provisioner_settings (skey TEXT PRIMARY KEY, svalue TEXT)',
	);

	foreach ($tables as $sql) {
		$pdo->exec($sql);
	}

	Provisioner::reset();

	return Provisioner::boot($pdo);
}

/**
 * Exit code for the suite.
 *
 * @return int
 */
function oryk_report()
{
	echo "\n" . $GLOBALS['oryk_passed'] . ' passed, ' . $GLOBALS['oryk_failed'] . " failed\n";

	return $GLOBALS['oryk_failed'] === 0 ? 0 : 1;
}
