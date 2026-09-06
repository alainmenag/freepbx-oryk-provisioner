<?php
/**
 * Oryk Provisioner - device provisioning endpoint.
 *
 * This is the endpoint devices talk to. It deliberately does not require a
 * FreePBX session: authorisation comes from the device's provisioning token.
 *
 *   /admin/modules/oryk_provisioner/provision.php?token={token}&filename={file}
 *   /admin/modules/oryk_provisioner/provision.php/{token}/{file}
 *   /provisioner/{token}/{file}                    (via the friendly URL shim)
 *
 * The FreePBX URL documented in the README reaches the same engine:
 *   /admin/config.php?display=oryk_provisioner&token={token}&filename={file}
 *
 * Responses: 200 with the template's content type, 404 for an invalid token, a
 * disabled device or an unknown filename, 500 when rendering fails.
 */

// Bootstrap FreePBX without authentication and without Asterisk Manager.
$bootstrap_settings = array(
	'freepbx_auth'    => false,
	'skip_astman'     => true,
	'skip_globals'    => true,
	'whoops_handler'  => 'PlainTextHandler',
);

$restrict_mods = true;

if (!@include_once('/etc/freepbx.conf')) {
	header('HTTP/1.1 500 Internal Server Error');
	header('Content-Type: text/plain');
	echo "Internal Server Error\n";
	exit;
}

require_once __DIR__ . '/lib/autoload.php';

try {
	$provisioner = \Oryk\Provisioner\Provisioner::boot(\FreePBX::Database());
	$provisioner->endpoint()->handle();
} catch (\Exception $e) {
	error_log('[oryk_provisioner] provisioning endpoint failed: ' . $e->getMessage());

	while (ob_get_level() > 0) {
		@ob_end_clean();
	}

	if (!headers_sent()) {
		header('HTTP/1.1 500 Internal Server Error');
		header('Content-Type: text/plain');
	}

	echo "Internal Server Error\n";
}
