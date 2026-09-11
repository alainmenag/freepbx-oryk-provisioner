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
 * MAC, when there is one -- and what they asked for -- the last segment of
 * the path. Which file of which profile that names is the module's business:
 * it knows the profile the MAC resolves to, what resources that profile
 * serves, and how to take the MAC off the front of a filename to match them.
 * None of that is here.
 *
 *   /provisioner/0004f282e824.cfg            the profile's main config
 *   /provisioner/?mac=0004f282e824           the same, for a caller with no
 *                                            filename to give
 *   /provisioner/0004f282e824-phone.cfg      a resource of that profile
 *   /provisioner/0004f282e824-directory.xml  another
 *   /provisioner/3111-44500-001.sip.ld       an uploaded file, asked for by
 *                                            name alone -- a phone fetching
 *                                            firmware sends no MAC at all
 *
 * and, in the other direction:
 *
 *   PUT /provisioner/0004f282e824-boot.log   a log the phone is sending back,
 *                                            taken when the profile has a
 *                                            resource of type Log by that name
 *
 * GET or HEAD to fetch, PUT to send; the MAC is read from the path, the query
 * string or the User-Agent, and on a fetch may be missing altogether. A
 * request that names neither a MAC nor a file is a 404 here; anything else is
 * the module's to answer or refuse.
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

$user = $_SERVER['PHP_AUTH_USER'] ?? null;
$pass = $_SERVER['PHP_AUTH_PW'] ?? null;

// Create token to compare against the stored token for authentication
$token = null;

if ($user !== null && $pass !== null) {
	$token = $user . ':' . $pass;
}

// --------------------------------------------------------------------------
// SERVE
// --------------------------------------------------------------------------

// serve() ends the request either way -- with the file, rendered or stored,
// or with a 404 when nothing answers to what was asked for. It logs the
// outcome itself.
//
// The MAC may be empty, and that is not this file's business to refuse. A
// phone fetching firmware puts no MAC anywhere in the request --
// /3111-44500-001.sip.ld is the whole of what a Polycom sends -- so a
// request that names a file is a request, and whether anything answers to it
// is a question for the module rather than an assumption here.
if (($method === 'GET' || $method === 'HEAD') && ($mac !== '' || $filename !== '')) {
    $provisioner->serve($mac, $filename, $token);
    exit;
}

// --------------------------------------------------------------------------
// RECEIVE
// --------------------------------------------------------------------------

// A phone does not only fetch. It PUTs its boot and app logs back when it has
// finished starting up, and until 1.0.14 there was nowhere for those to go.
// A resource of type Log is that somewhere, and receive() ends the request
// the way serve() does -- with a 200 when the profile takes what was sent, a
// 404 when it does not.
//
// The MAC is required here where it is not for a fetch, and that is the whole
// of the difference: what a phone sends is written to disk, so it has to be a
// phone this module knows. There is no equivalent of the by-name firmware
// lookup in this direction.
if ($method === 'PUT' && $mac !== '' && $filename !== '') {
    $provisioner->receive($mac, $filename, $token);
    exit;
}

// --------------------------------------------------------------------------
// 404
// --------------------------------------------------------------------------

http_response_code(404);

// Why, in the words the provisioning log will show: a phone PUTting a boot
// log and a request with no MAC anywhere in it are two different faults, and
// a log that says 'Not Found' to both is a log that says nothing. These are
// the requests serve() never sees, so this is the only place they can
// be recorded at all.
$reason = ($method === 'GET' || $method === 'HEAD')
	? 'Neither a MAC address nor a filename in the request.'
	: ($method === 'PUT'
		? 'A PUT needs both a MAC address and a filename.'
		: sprintf('%s is not a request this endpoint answers.', $method));

$provisioner->log(sprintf(
	'oryk_provisioner: %s for %s (%s)',
	(string) '404',
	(string) $filename,
	$reason,
), null, 'DEBUG');

$provisioner->logRequest($mac, $filename, 404, $reason);
