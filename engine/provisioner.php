<?php

/**
 * /engine/provisioner.php -- the unauthenticated device provisioning endpoint.
 *
 * A phone has no admin login, and FreePBX's config.php forces every
 * session-less request to the login page before a module's doConfigPageInit()
 * ever runs -- a menu item's requires_auth flag governs menu visibility and
 * permissions, not anonymous access to a display page. So the device endpoint
 * does not go through config.php at all. This file is reached directly:
 *
 *   http(s)://<pbx>/provisioner/?mac=00908F3BBCBA
 *   http(s)://<pbx>/provisioner/00908F3BBCBA.cfg
 *   http(s)://<pbx>/provisioner/00908F3BBCBA
 *
 * and bootstraps FreePBX itself. It calls the same serveConfig() the admin
 * preview (config.php?...&config=) calls -- one engine, two ways in, and only
 * this one is reachable without a session.
 *
 * GET or POST; the MAC is read from either. The reply is text/plain, and every
 * failure is a uniform 404 so a MAC cannot be probed for whether it is known.
 */

/**
 * Example requests:
 * | Method | Request URI                             | User-Agent                                                        |
 * | ------ | --------------------------------------- | ----------------------------------------------------------------- |
 * | PUT    | `/0004f282e824-boot.log`                | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.47435 Type/Updater`    |
 * | GET    | `/0004f282e824.cfg`                     | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
 * | GET    | `/3111-44500-001.3111-44500-001.sip.ld` | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
 * | GET    | `/0004f282e824-phone.cfg`               | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
 * | GET    | `/0004f282e824-web.cfg`                 | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
 * | GET    | `/000000000000-license.cfg`             | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
 * | GET    | `/0004f282e824-license.cfg`             | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
 * | GET    | `/0004f282e824-calls.xml`               | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
 * | GET    | `/0004f282e824-directory.xml`           | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
 * | GET    | `/000000000000-directory.xml`           | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
 * | HEAD   | `/0004f282e824-app.log`                 | `FileTransport PolycomVVX-VVX_500-UA/5.9.7.4477 Type/Application` |
 */

// Bootstrap FreePBX for an anonymous request: skip the admin session and the
// Asterisk manager connection (this endpoint only reads the database), and
// load this module alone rather than every module's functions. These are read
// by bootstrap.php, which /etc/freepbx.conf pulls in.

$bootstrap_settings = [];
$bootstrap_settings['freepbx_auth'] = false;

// Don't load every module's functions.inc.php
$restrict_mods = true;

require '/etc/freepbx.conf';

$freepbx = \FreePBX::Create();
$provisioner = \FreePBX::Oryk_provisioner();

$headers = getallheaders();
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); // /provisioner/00908f3bbcba.cfg
$requestAgent = $_SERVER['HTTP_USER_AGENT'] ?? ''; // AUDC-IPPhone/2.0.0_build_15 (420HD; 00908F3BBCBA)

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$user = $_SERVER['PHP_AUTH_USER'] ?? null;
$pass = $_SERVER['PHP_AUTH_PW'] ?? null;

// --------------------------------------------------------------------------
// MAC
// --------------------------------------------------------------------------

$mac = isset($_REQUEST['mac']) ? (string) $_REQUEST['mac'] : '';

// Try extracting from request path
if ($mac === '' && preg_match('#/([^/]+)\.cfg$#i', $requestPath, $matches)) {
    $mac = $matches[1];
}

if (preg_match('/(?<![0-9A-Fa-f])(?:[0-9A-Fa-f]{2}[:-]?){5}[0-9A-Fa-f]{2}(?![0-9A-Fa-f])/', $requestPath, $matches)) {
		$mac = preg_replace('/[^0-9A-Fa-f]/', '', $matches[0]);
}

// Try extracting from AudioCodes User-Agent
if (
    $mac === '' &&
    str_contains($requestAgent, 'AUDC-IPPhone/2.0.0') &&
    preg_match('/;\s*([0-9A-Fa-f]{12})\)/', $requestAgent, $matches)
) {
    $mac = $matches[1];
}

$mac = strtolower($mac ?? '');

// --------------------------------------------------------------------------
// RESOURCE
// --------------------------------------------------------------------------

$filename = basename($requestPath);
$resource = $filename;

if ($mac !== '') {
    // Compare without caring about MAC case.
    if (strncasecmp($filename, $mac, strlen($mac)) === 0) {
        $resource = substr($filename, strlen($mac));

        // MAC.cfg => ".cfg"
        // MAC-directory.xml => "directory.xml"
        // MAC-phone.cfg => "phone.cfg"
        if ($resource !== '' && $resource[0] !== '.') {
            $resource = ltrim($resource, '-_');
        }
    }
}

// --------------------------------------------------------------------------
// AUTHENTICATION
// --------------------------------------------------------------------------

// to-do: on never-seen, create account to return config with that auth.
// so it loops back around with new auth.

// if ($user === null) {
//     header('WWW-Authenticate: Basic realm="Provisioning"');
//     http_response_code(401);
//     exit;
// }

// --------------------------------------------------------------------------
// RESOURCE - GET - .cfg
// --------------------------------------------------------------------------

if ($method === 'GET' && $resource === '.cfg') {
		$provisioner->serveConfig($mac);
		exit;
}

// --------------------------------------------------------------------------
// RESOURCE - 404
// --------------------------------------------------------------------------

// to-do: handle other resources, like .log, .xml, etc. For now, just return 404 for anything else
// from the profiles

http_response_code(404);

$freepbx->Logger->log(
	FPBX_LOG_INFO,
	json_encode([
			'status' => 404,
			'method' => $method,
			'mac' => $mac,
			'resource' => $resource,
	])
);
