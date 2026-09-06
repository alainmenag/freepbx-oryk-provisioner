<?php

die('test');

/**
 * provision.php -- the unauthenticated device provisioning endpoint.
 *
 * A phone has no admin login, and FreePBX's config.php forces every
 * session-less request to the login page before a module's doConfigPageInit()
 * ever runs -- a menu item's requires_auth flag governs menu visibility and
 * permissions, not anonymous access to a display page. So the device endpoint
 * does not go through config.php at all. This file is reached directly:
 *
 *   http(s)://<pbx>/admin/modules/oryk_provisioner/provision.php?mac=00908F3BBCBA
 *
 * and bootstraps FreePBX itself. It calls the same renderConfig() the admin
 * preview (config.php?...&config=) calls -- one engine, two ways in, and only
 * this one is reachable without a session.
 *
 * GET or POST; the MAC is read from either. The reply is text/plain, and every
 * failure is a uniform 404 so a MAC cannot be probed for whether it is known.
 */

// Bootstrap FreePBX for an anonymous request: skip the admin session and the
// Asterisk manager connection (this endpoint only reads the database), and
// load this module alone rather than every module's functions. These are read
// by bootstrap.php, which /etc/freepbx.conf pulls in.

/*
$bootstrap_settings['freepbx_auth'] = false;
$bootstrap_settings['skip_astman']  = true;
$restrict_mods = ['oryk_provisioner' => true];

$conf = getenv('FREEPBX_CONF') ?: '/etc/freepbx.conf';

if (!@include_once($conf) || !class_exists('FreePBX')) {
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	echo "Provisioning is unavailable.\n";
	exit;
}

$mac = isset($_REQUEST['mac']) ? (string) $_REQUEST['mac'] : '';

try {
	$result = FreePBX::Oryk_provisioner()->renderConfig($mac);
} catch (\Throwable $e) {
	// An anonymous caller is never shown an exception; a phone gets a bare 500,
	// and the detail is left to the PHP error log.
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	header('Cache-Control: no-store');
	echo "Provisioning error.\n";
	exit;
}

header('Content-Type: text/plain; charset=utf-8');
// A phone that re-reads its config expects what is stored now, not a cached
// copy from the last time it asked.
header('Cache-Control: no-store');

if (empty($result['status'])) {
	// Unknown MAC, no profile assigned, malformed MAC: all one uniform 404, so
	// the endpoint gives nothing away to a caller probing MAC addresses.
	http_response_code(404);
	echo "Not found\n";
	exit;
}

http_response_code(200);
echo $result['config'];

*/