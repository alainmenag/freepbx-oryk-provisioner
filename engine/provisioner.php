<?php

/**
 * /engine/provisioner.php -- the unauthenticated client provisioning endpoint.
 *
 * A phone has no admin login, and FreePBX's config.php forces every
 * session-less request to the login page before a module's doConfigPageInit()
 * ever runs. So this file is reached directly and bootstraps FreePBX itself.
 *
 * What it works out is who is asking -- the MAC, when there is one -- and what
 * they asked for -- the last segment of the path. Which file of which profile
 * that names is the module's business; none of that is here.
 *
 *   /provisioner/0004f282e824.cfg            the profile's main config
 *   /provisioner/?mac=0004f282e824           the same, for a caller with no
 *                                            filename to give
 *   /provisioner/0004f282e824-phone.cfg      a resource of that profile
 *   /provisioner/3111-44500-001.sip.ld       an uploaded file, asked for by
 *                                            name alone -- a phone fetching
 *                                            firmware sends no MAC at all
 *   PUT /provisioner/0004f282e824-boot.log   a log the phone is sending back,
 *                                            taken when the profile has a
 *                                            resource of type Log by that name
 *
 * GET or HEAD to fetch, PUT to send; the MAC is read from the path, the query
 * string or the User-Agent, and on a fetch may be missing altogether. A
 * request naming neither a MAC nor a file is a 404 here; anything else is the
 * module's to answer or refuse. ARCHITECTURE.md has the observed request table
 * from a real Polycom.
 */

// Bootstrap FreePBX for an anonymous request: no admin session, and this
// module's functions rather than every module's. Both are read by
// bootstrap.php, which /etc/freepbx.conf pulls in.

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, HEAD, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$bootstrap_settings = [];
$bootstrap_settings['freepbx_auth'] = false;

// Don't load every module's functions.inc.php
$restrict_mods = true;

require '/etc/freepbx.conf';

$freepbx = \FreePBX::Create();
$provisioner = \FreePBX::Oryk_provisioner();

// Read after the bootstrap: before it there is no $freepbx and no autoloader.
$provisioning = (new \FreePBX\Modules\Oryk_Provisioner\Settings($freepbx))->get(\FreePBX\Modules\Oryk_Provisioner\Settings::PROVISIONING);

if ($provisioning === 'DISABLED') {
	http_response_code(503);
	exit;
}

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH); // /provisioner/00908f3bbcba.cfg
$requestAgent = $_SERVER['HTTP_USER_AGENT'] ?? ''; // AUDC-IPPhone/2.0.0_build_15 (420HD; 00908F3BBCBA)

// --- MAC ---

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

// --- FILE ---

// The last segment of the path, exactly as asked for -- working out which
// resource of which profile that is belongs with the profile. A path ending
// in a slash asked for no file at all, which means the main config.

$filename = substr($requestPath, -1) === '/' ? '' : basename($requestPath);

// --- AUTHENTICATION ---

$user = $_SERVER['PHP_AUTH_USER'] ?? null;
$pass = $_SERVER['PHP_AUTH_PW'] ?? null;

// Create token to compare against the stored token for authentication
$token = null;

if ($user !== null && $pass !== null) {
	$token = $user . ':' . $pass;
}

// $provisioner->log('asdfadfasf', [
// 	'$_SERVER' => $_SERVER,
// ], 'ERROR');

// --- OPEN PROVISIONING ---

// The all-zero MAC is answered here, before any client is looked up, and only
// while ORYK_PROVISIONING is OPEN. Settings::get() falls back to CLOSED when
// the setting is not registered yet, so this fails closed.
//
// The Basic credentials are a User Manager login: they find the user it
// belongs to, or make one the way the Users editor does with a blank
// Extension and give its account that username and password. Then the user's
// internal-MAC client is found, or made with the credentials as its token, and
// that MAC is what the phone asks with from then on. The password is never
// logged.
if ($mac === '000000000000' && $provisioning === 'OPEN') {
	header('Content-Type: application/json');

	if ($user === null || $pass === null) {
		http_response_code(401);
		header('WWW-Authenticate: Basic realm="oryk_provisioner"');
		die(json_encode(['mac' => $mac, 'message' => 'Credentials are required.']));
	}

	try {
		$found = $provisioner->findOrCreateUser($user, $pass);
	} catch (\InvalidArgumentException $e) {
		http_response_code(400);
		die(json_encode(['mac' => $mac, 'message' => $e->getMessage()]));
	} catch (\RuntimeException $e) {
		http_response_code(409);
		die(json_encode(['mac' => $mac, 'message' => $e->getMessage()]));
	} catch (\Throwable $e) {
		http_response_code(500);
		die(json_encode(['mac' => $mac, 'message' => 'The user could not be saved.']));
	}

	if ($found === null) {
		http_response_code(401);
		header('WWW-Authenticate: Basic realm="oryk_provisioner"');
		die(json_encode(['mac' => $mac, 'message' => 'Invalid credentials.']));
	}

	try {
		$client = $provisioner->findOrCreateClient($found['extension'], $token);
	} catch (\Throwable $e) {
		$provisioner->logRequest($mac, $filename, 500, 'Client could not be saved: ' . $e->getMessage());
		http_response_code(500);
		die(json_encode(['mac' => $mac, 'message' => 'The client could not be saved.']));
	}

	$created = $found['created'] || $client['created'];
	$reason = sprintf(
		'User %s %s; client %s %s.',
		$found['extension'],
		$found['created'] ? 'created' : 'found',
		$client['mac'],
		$client['created'] ? 'created' : 'found'
	);
	$provisioner->logRequest($mac, $filename, $created ? 201 : 200, $reason);

	http_response_code($created ? 201 : 200);
	die(json_encode([
		'mac' => $mac,
		'extension' => $found['extension'],
		'created' => $found['created'],
		'client' => [
			'id' => $client['id'],
			'mac' => $client['mac'],
			'created' => $client['created'],
		],
	]));
}

// --- SERVE ---

// serve() ends the request either way -- with the file, rendered or stored,
// or with a 404 when nothing answers. It logs the outcome itself.
//
// The MAC may be empty, and that is not this file's business to refuse: a
// phone fetching firmware puts no MAC anywhere in the request.
if (($method === 'GET' || $method === 'HEAD') && ($mac !== '' || $filename !== '')) {
    $provisioner->serve($mac, $filename, $token);
    exit;
}

// --- RECEIVE ---

// A phone does not only fetch: it PUTs its boot and app logs back, and a
// resource of type Log is where those go. receive() ends the request the
// way serve() does.
//
// The MAC is required here where it is not for a fetch, and that is the
// whole of the difference: what a phone sends is written to disk, so it has
// to be a phone this module knows.
if ($method === 'PUT' && $mac !== '' && $filename !== '') {
    $provisioner->receive($mac, $filename, $token);
    exit;
}

// --- 404 ---

http_response_code(404);

// Why, in the words the provisioning log will show: a phone PUTting a boot
// log and a request with no MAC anywhere in it are two different faults.
// These are the requests serve() never sees.
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
