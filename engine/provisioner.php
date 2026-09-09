<?php

/**
 * /engine/provisioner.php -- the unauthenticated client provisioning endpoint.
 *
 * A phone has no admin login, and FreePBX's config.php forces every
 * session-less request to the login page before a module's doConfigPageInit()
 * ever runs -- a menu item's requires_auth flag governs menu visibility and
 * permissions, not anonymous access to a display page. So the client endpoint
 * does not go through config.php at all. This file is reached directly:
 *
 *   http(s)://<pbx>/provisioner/?mac=00908F3BBCBA
 *   http(s)://<pbx>/provisioner/00908F3BBCBA.cfg
 *   http(s)://<pbx>/provisioner/00908F3BBCBA
 *
 * and bootstraps FreePBX itself. What it works out is who is asking -- the
 * MAC -- and what they asked for -- the last segment of the path. Which file
 * of which profile that names is the module's business: it knows the profile
 * the MAC resolves to, what resources that profile serves, and how to take
 * the MAC off the front of a filename to match them. None of that is here.
 *
 *   /provisioner/0004f282e824.cfg            the profile's main config
 *   /provisioner/?mac=0004f282e824           the same, for a caller with no
 *                                            filename to give
 *   /provisioner/0004f282e824-phone.cfg      a resource of that profile
 *   /provisioner/0004f282e824-directory.xml  another
 *
 * GET or HEAD; the MAC is read from the path, the query string or the
 * User-Agent. A file the profile does not serve is a 404.
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
// FILE
// --------------------------------------------------------------------------

// The last segment of the path, exactly as it was asked for and no more --
// working out which resource of which profile that is belongs with the
// profile, not here. A path ending in a slash asked for no file at all, which
// is the /provisioner/?mac=... shape and means the main config.

$filename = substr($requestPath, -1) === '/' ? '' : basename($requestPath);

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
// SERVE
// --------------------------------------------------------------------------

// serveConfig() ends the request either way -- with the rendered file, or
// with a 404 when the MAC is unknown, has no profile, or that profile serves
// nothing by that name. It logs the outcome itself.
if ($mac && ($method === 'GET' || $method === 'HEAD')) {
    $provisioner->serveConfig($mac, $filename);
    exit;
}

// --------------------------------------------------------------------------
// 404
// --------------------------------------------------------------------------

// to-do: a phone also PUTs its boot and app logs. Until there is somewhere
// for those to go, anything that is not a fetch is not a request this
// endpoint answers.

http_response_code(404);

// Why, in the words the provisioning log will show: a phone PUTting a boot
// log and a request with no MAC anywhere in it are two different faults, and
// a log that says 'Not Found' to both is a log that says nothing. These are
// the requests serveConfig() never sees, so this is the only place they can
// be recorded at all.
$reason = ($method === 'GET' || $method === 'HEAD')
	? 'No MAC address in the request.'
	: sprintf('%s is not a request this endpoint answers.', $method);

$provisioner->log(sprintf(
	'oryk_provisioner: %s for %s (%s)',
	(string) '404',
	(string) $filename,
	$reason,
), null, 'DEBUG');

$provisioner->logRequest($mac, $filename, 404, $reason);
