<?php

// src/Endpoint.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Answering a provisioning request, and ending it.
 *
 * resolveRequest() works out what the answer is and returns it;
 * serve() is the only thing that ends the request. Anything that wants an
 * answer without the exit -- a preview, a console command -- calls the
 * former.
 */
class Endpoint extends Service
{
	/**
	 * @var Clients
	 */
	private $clients;

	/**
	 * @var Matcher
	 */
	private $matcher;

	/**
	 * @var Template
	 */
	private $template;

	/**
	 * @var FileRepo
	 */
	private $files;

	/**
	 * @var LogRepo
	 */
	private $logs;

	/**
	 * @var ProvisioningLog
	 */
	private $requestLog;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Clients $clients, Matcher $matcher, Template $template, FileRepo $files, LogRepo $logs, ProvisioningLog $requestLog)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->matcher = $matcher;
		$this->template = $template;
		$this->files = $files;
		$this->logs = $logs;
		$this->requestLog = $requestLog;
	}

	/**
	 * Answer a request for a file and end the request.
	 *
	 * Called by engine/provisioner.php, which is the only route a phone can
	 * reach: FreePBX's config.php sends every session-less request to the
	 * login page long before a module's doConfigPageInit() runs.
	 *
	 *   serve($mac)                          the main config, [mac].cfg
	 *   serve($mac, '0004f282e824-web.cfg')  any other file that profile serves
	 *   serve('', '3111-44500-001.sip.ld')   a file, asked for by name alone
	 *
	 * It is serve() rather than serveConfig() because what a resource holds is
	 * no longer always configuration: one with an uploaded file behind it is
	 * sent as it was stored, and firmware is why the upload exists.
	 *
	 * The MAC may be empty, which is the other half of the same change. A
	 * phone fetching firmware does not put its MAC anywhere in the request --
	 * a Polycom asks for /3111-44500-001.sip.ld and nothing else -- so a
	 * request that reaches no profile is answered from the resources that are
	 * the same for every caller, which is to say the ones with a file.
	 *
	 * The second argument is the filename as it was asked for, not a resource
	 * id: which resource that names is resolveRequest()'s business.
	 *
	 * The outcome is logged here rather than by the endpoint, because this is
	 * where the request ends and the endpoint never gets to see it.
	 *
	 * @param mixed       $mac       MAC address, written however it was written, or ''.
	 * @param string|null $requested Filename asked for, or null for the main config.
	 *
	 * @return void Never returns; the request ends here.
	 */
	public function serve($mac, $requested = null, $token = null)
	{
		$this->answer($this->resolveRequest($mac, $requested, $token), $mac, $requested);
	}

	/**
	 * Take what a phone sent us and end the request.
	 *
	 * serve() the other way round, and the reason a resource has a type at
	 * all: a phone does not only fetch files, it PUTs its boot and app logs
	 * back -- a Polycom sends /0004f282e824-boot.log the moment it finishes
	 * starting up -- and until 1.0.14 there was nowhere for those to go and
	 * nothing that could have said where.
	 *
	 * A resource of type Log is that somewhere. It is matched exactly as a
	 * served file is, by the same names against the same profile, so a
	 * profile says which logs it takes the same way it says which files it
	 * serves -- and one it has not declared is refused rather than written,
	 * which is what keeps this from being an open upload to the PBX for
	 * anyone who can reach the endpoint.
	 *
	 * The body goes to ASTLOGDIR/provisioner/[mac], under the resource's own
	 * name rendered against that client -- a directory per client, so a
	 * stored log says whose it is rather than leaving that to whatever the
	 * vendor happened to call the file. The outcome is logged here, as
	 * serve() logs its own, because this is where the request ends.
	 *
	 * @param mixed       $mac       MAC address, written however it was written.
	 * @param string|null $requested Filename PUT to.
	 * @param string|null $token     Token offered, when one was.
	 *
	 * @return void Never returns; the request ends here.
	 */
	public function receive($mac, $requested = null, $token = null)
	{
		$result = $this->resolveRequest($mac, $requested, $token, 'PUT');

		// The one thing this does that serve() does not: the body is written
		// before the request is answered. Everything either side of it --
		// resolving, logging, ending -- is answer()'s, and the same.
		if ($result['status']) {
			$stored = $this->logs->storeLog($result['path']);

			// Everything up to the write said yes, so a failure here is this
			// server's and not the phone's. It is still answered as one thing
			// -- the phone has nothing to do differently either way -- but the
			// log says which, and the byte count rides back on a success.
			$result = $stored['status']
				? $result + ['message' => sprintf('%s bytes', $stored['bytes'])]
				: $stored;
		}

		$this->answer($result, $mac, $requested);
	}

	/**
	 * Report what was decided, send it, and end the request.
	 *
	 * The whole of what serve() and receive() have in common, which is
	 * everything except the write in the middle of one of them: the status,
	 * the line in the Asterisk log, the 401, the row in the provisioning log,
	 * the 404, and the body. Two copies of this is how the two directions
	 * drift apart -- and they had already started to, logging the same
	 * outcome in two shapes.
	 *
	 * The kind decides the body, and every kind there is has one:
	 *
	 *   template  the rendered text, as text.
	 *   file      the file on disk, streamed -- an uploaded file, or a stored
	 *             log being read back. Neither ever becomes a string: a
	 *             firmware image is tens of megabytes and a boot log is
	 *             occasionally not much less. The type goes with it because it
	 *             decides the content type.
	 *   log       nothing. This is the answer to a PUT, and a phone uploading
	 *             a log reads the status and nothing else; a body it did not
	 *             ask for is a body to be wrong about.
	 *
	 * @param array<string, mixed> $result    What resolveRequest() decided.
	 * @param mixed                $mac       MAC the request was made with.
	 * @param string|null          $requested Filename as it was asked for.
	 *
	 * @return void Never returns; the request ends here.
	 */
	private function answer(array $result, $mac, $requested)
	{
		$status = $result['code'] ?? ($result['status'] ? 200 : 404);

		// The method is read here rather than passed: it is the same fact the
		// provisioning log records for itself, and a caller that had to say
		// which direction it was going would be a caller that could say it
		// wrongly.
		$this->log(sprintf(
			'oryk_provisioner: %s for %s %s (%s)',
			(string) $status,
			(string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
			(string) $requested !== '' ? (string) $requested : (string) $mac,
			$result['message'] ?? 'OK'
		), null, $result['status'] ? 'INFO' : 'DEBUG');

		if ($status === 401) {
			header('WWW-Authenticate: Basic realm="Provisioning"');
			http_response_code(401);
			exit;
		}

		// Both outcomes, and a MAC nothing is associated with as readily as one
		// that renders: a phone asking for a file nobody has written a client
		// for is the request an operator most needs to see, and it is the one
		// that leaves no other trace. On a success the message is whatever the
		// answer had to add -- nothing, for a file served; the byte count, for
		// a log stored.
		$this->requestLog->logRequest($mac, $requested, $status, $result['message'] ?? null);

		if (!$result['status']) {
			$this->sendText(404, $result['message'] . "\n");
		}

		if (($result['kind'] ?? '') === 'file') {
			$this->sendFile(
				(string) $result['path'],
				(string) $result['resource'],
				(string) ($result['type'] ?? 'file')
			);
		}

		if (($result['kind'] ?? '') === 'log') {
			$this->sendText(200, '');
		}

		$this->sendText(200, $result['config'], $this->matcher->contentType((string) $result['resource'], 'template'));
	}

	/**
	 * Which file answers a request, and what it is.
	 *
	 * Separate from serve() because this is the part worth calling again: a
	 * preview, a console command, a test. Only the caller there ends the
	 * request. It is resolveRequest() rather than renderConfig() because what
	 * comes back is not always rendered text -- a resource with an uploaded
	 * file behind it comes back as a path on disk.
	 *
	 * Three steps, and the first that answers wins:
	 *
	 *   1. A MAC naming a client with a profile: that profile's resources,
	 *      matched by name. The profile is the authority -- a name it does not
	 *      serve is refused here rather than looked for elsewhere, or a
	 *      profile could never withhold a file.
	 *   2. No MAC, a MAC naming no client, or a client with no profile: the
	 *      resources that carry an uploaded file, matched by name exactly.
	 *   3. Nothing.
	 *
	 * Step 2 is files only, and deliberately so. A template matched with no
	 * client behind it has no values to render against, so every placeholder
	 * in it would come out empty and the phone would receive a configuration
	 * that parses and is wrong -- which is worse than the 404 it gets instead.
	 * A file has no rendering at all, and that is exactly why it is the same
	 * bytes for every caller and can be handed out by name. The consequence is
	 * worth saying plainly: an uploaded file can be fetched by anyone who
	 * reaches the endpoint and knows what it is called.
	 *
	 * A request that names no file -- /provisioner/[mac], or an internal
	 * caller with nothing to pass -- is a request for the main config, which
	 * is to say [mac].cfg. It is filled in inside step 1 rather than left
	 * empty and special-cased further down, so exactly one string is matched
	 * against and the message on a miss names the file the caller will
	 * recognise. There is nothing to fill it in from without a client, so a
	 * caller with neither a MAC nor a filename has asked for nothing.
	 *
	 * @param mixed       $mac       MAC address, written however it was written, or ''.
	 * @param string|null $requested Filename asked for, or null for the main config.
	 *
	 * @return array<string, mixed> Status, what answers when something does,
	 *                              and a message when nothing did.
	 */
	public function resolveRequest($mac, $requested = null, $token = null, $method = 'GET')
	{
		$mac = Mac::normalize($mac);
		$requested = trim((string) $requested);
		$sending = $method === 'PUT' || $method === 'POST';

		$client = $mac === '' ? null : $this->clients->clientByMac($mac);

		// A client that has been switched off is answered nothing, and before
		// anything else is looked at: not its profile's files, not the files
		// that are served by name to callers with no client at all, and not a
		// log it tries to send. Disabling a client is the operator saying
		// this MAC is not to be provisioned, and a switch that only covered
		// what the profile serves would leave firmware -- the one resource
		// that can be fetched without a client behind the request -- still
		// going out to it.
		//
		// It reads as a refusal rather than as an unknown MAC, which is the
		// posture the rest of this method already takes: every message here
		// says which kind of no it is, because the operator reading them on
		// the Logs tab is the one the endpoint is talking to. A phone does
		// not read them either way. Closing that gap is the token scheme's
		// job and it is a change to all of them at once, not to this one.
		if ($client && !(int) ($client['enabled'] ?? 1)) {
			return [
				'status' => false,
				'message' => sprintf(_('%s is disabled.'), $mac),
			];
		}

		// And the same switch one level up. A profile that has been switched
		// off serves nothing to anybody, so every client assigned to it is
		// refused here without any of those clients having been touched --
		// which is the whole point of a switch on the profile rather than on
		// each of them in turn.
		//
		// The refusal names the profile rather than the client, because that
		// is the difference between this and the line above it: the operator
		// reading the Logs tab is looking at a run of refusals from phones
		// that are individually fine, and what they need told is which one
		// thing to switch back on.
		//
		// Its uploaded files are refused too, and that is decided elsewhere
		// -- fileByName() will not look inside a disabled profile, because
		// that lookup answers callers with no client behind them at all and
		// so never reaches this line.
		if ($client && $client['profile_id'] !== null && !(int) ($client['profile_enabled'] ?? 1)) {
			return [
				'status' => false,
				'message' => sprintf(
					_('The %s profile is disabled.'),
					(string) $client['profile_name']
				),
			];
		}

		if ($client && $client['profile_id'] !== null) {
			$values = $this->template->provisioningValues($client);

			// What is left of the filename with this client's own MAC off the
			// front: 0004f282e824-phone.cfg asked of that client is phone.cfg,
			// and 0004f282e824.cfg is .cfg. Worked out here rather than in the
			// endpoint, so the endpoint only has to report what was asked for.
			$suffix = $requested === '' ? '' : $this->matcher->resourceSuffix($requested, $mac);

			// Two ways of asking for nothing in particular, and both are asking
			// for the main config: no filename at all, and the client's own MAC
			// with no filename after it (/provisioner/0004f282e824, which is
			// what leaves nothing behind once the MAC is taken off the front).
			//
			// Only on the way out. A phone sending something has named what it
			// is sending; a PUT to no filename in particular is not a PUT of
			// the main config, it is a request that named nothing.
			if ($suffix === '' && !$sending) {
				$requested = $mac . '.cfg';
				$suffix = '.cfg';
			}

			$match = $suffix === ''
				? null
				: $this->matcher->matchResource((int) $client['profile_id'], $requested, $suffix, $values);

			// The token guards the client rather than any one of its files, so
			// it is asked for as soon as something has been matched -- before
			// the type is looked at, because a 401 that depended on what was
			// asked for would tell an unauthenticated caller which files exist.
			//
			// Before 1.0.14 this read the matched row's `template` column, so a
			// resource with nothing in that column -- an uploaded file, most of
			// them -- was served to a tokened client without the token. That
			// was the inference this release is removing, in the one place it
			// mattered most.
			if ($match !== null && $client['token']) {
				if (!$token) {
					return [
						'status' => false,
						'message' => _('A token is required for this client.'),
						'code' => 401,
					];
				}

				if (!password_verify($token, $client['token'])) {
					return [
						'status' => false,
						'message' => _('Invalid authentication provided for this client.'),
						'code' => 401,
					];
				}
			}

			if ($match !== null) {
				$outcome = $sending
					? $this->receivedResult($match, $values, $mac)
					: $this->resourceResult($match, $values, $mac);

				return $outcome + [
					'mac' => $mac,
					'profile' => (string) $client['profile_name'],
				];
			}

			// Nothing this profile serves answers to the name. That includes
			// [mac].cfg on a profile with no `.cfg` resource on it: there is no
			// template behind the profile to fall back to, and a profile that
			// serves nothing is one somebody has not finished writing rather
			// than one with an implicit main config.
			return [
				'status' => false,
				'message' => $sending
					? sprintf(_('%s is not something this profile takes.'), $requested)
					: sprintf(_('%s is not something this profile serves.'), $requested),
			];
		}

		// A phone sending something has to be one this module knows, because
		// what it is sending is written to disk. The file lookup below is for
		// fetching firmware by name with nobody behind the request, and there
		// is no counterpart to it in this direction on purpose.
		if ($sending) {
			return [
				'status' => false,
				'message' => $mac === ''
					? _('Nothing can be sent to this endpoint without a MAC address.')
					: sprintf(_('%s is not associated with anything.'), $mac),
			];
		}

		// No profile behind the request, which is the firmware case: a name and
		// nothing else to say who is asking.
		if ($requested !== '') {
			$file = $this->matcher->fileByName($requested);

			if ($file !== null) {
				return $this->resourceResult($file, []);
			}
		}

		if ($mac === '') {
			return [
				'status' => false,
				'message' => $requested === ''
					? _('No MAC address and no filename: nothing was asked for.')
					: sprintf(_('%s is not a file this server serves.'), $requested),
			];
		}

		if (!$client) {
			return [
				'status' => false,
				'message' => sprintf(_('%s is not associated with anything.'), $mac),
			];
		}

		return [
			'status' => false,
			'message' => sprintf(_('%s has no profile assigned.'), $mac),
		];
	}

	/**
	 * A matched resource as the thing that answers a request for it.
	 *
	 * The one place that knows what the kinds of resource are, and the only
	 * place that needs to: a template is rendered for the client that asked, a
	 * file is sent as it was stored, and a log is sent back as this client
	 * last PUT it.
	 *
	 * A log is readable on purpose. It is the one resource this module did not
	 * write and the only one whose content is a fact about the phone rather
	 * than about the profile, which is exactly what somebody debugging that
	 * phone wants to look at -- so it is a GET like everything else, at the
	 * URL that phone PUTs to, and the preview link on the client's Resources
	 * tab means the same thing on a log row as on any other. What it is *not*
	 * is rendered: what comes back is the bytes the phone sent, because a boot
	 * log with `{{` in it is a boot log and not a template.
	 *
	 * Read off `type` and nothing else. Until 1.0.14 it was read off
	 * file_size -- a size meant a file and its absence meant a template -- and
	 * every consequence of that was of the same shape: a resource could not be
	 * a file until a file was already on it, so there was no such thing as an
	 * unfinished one to report; it could not be a log at any point, because
	 * nothing a log has is a thing to notice; and a template that had once
	 * held a file and lost it came back silently as a template. What the
	 * column buys is that each of those is now a state the row can be in and
	 * say so.
	 *
	 * A row that says it is a file and a repository that has not got one is a
	 * refusal rather than a quiet fall back to the template underneath. A
	 * phone handed an empty config where it expected firmware fails in a way
	 * nobody can see; a 404 naming the file says what happened.
	 *
	 * @param array<string, mixed>  $resource The resource row.
	 * @param array<string, string> $values   Placeholder name to value, empty for a file.
	 * @param string                $mac      Client asking, '' when there is none.
	 *
	 * @return array<string, mixed> What serve() sends, or a refusal.
	 */
	private function resourceResult(array $resource, array $values, $mac = '')
	{
		$name = (string) $resource['name'];
		$type = (string) ($resource['type'] ?? 'template');

		// Read back from exactly where receive() writes it: both go through
		// storedLog(), so the side that stores a log and the side that hands
		// it back cannot disagree about the path.
		if ($type === 'log') {
			$stored = $this->storedLog($resource, $values, $mac);

			// A log that has not arrived is not a broken resource, so it says
			// so in its own words rather than borrowing the missing-file line:
			// the usual reason is a phone that has not rebooted since somebody
			// wrote this, and there is nothing to fix.
			if ($stored === '' || !is_file($stored)) {
				return [
					'status' => false,
					'message' => sprintf(_('No %s has been received from this client.'), $name),
				];
			}

			return [
				'status' => true,
				'kind' => 'file',
				'type' => 'log',
				'resource' => $name,
				'path' => $stored,
			];
		}

		if ($type !== 'file') {
			return [
				'status' => true,
				'kind' => 'template',
				'type' => 'template',
				'resource' => $name,
				'config' => $this->template->renderTemplate((string) $resource['template'], $values),
			];
		}

		$path = $this->files->repoFile($resource['id']);

		// Two ways to be a file with nothing behind it, and one message for
		// both: nothing has been uploaded yet, or something was and is no
		// longer on the disk. Either way the answer is that the file this
		// profile says it serves is not there to serve.
		if ($resource['file_size'] === null || !is_file($path)) {
			return [
				'status' => false,
				'message' => sprintf(_('The uploaded file for %s is missing.'), $name),
			];
		}

		return [
			'status' => true,
			'kind' => 'file',
			'type' => 'file',
			'resource' => $name,
			'path' => $path,
		];
	}

	/**
	 * A matched resource as the thing that answers a request *to* it.
	 *
	 * resourceResult() the other way round, and much shorter, because the
	 * only question in this direction is whether the profile said it takes
	 * this. A phone that PUTs to a resource of any other type is refused and
	 * told what that resource is instead -- not with a message about method
	 * support, which would be true of the endpoint and wrong about the file.
	 *
	 * The path carried back is storedLog()'s, which is the same path a GET of
	 * this resource reads from -- so what a phone PUTs and what it gets back
	 * are the same file by construction rather than by agreement.
	 *
	 * @param array<string, mixed>  $resource The resource row.
	 * @param array<string, string> $values   Placeholder name to value.
	 * @param string                $mac      Client sending it.
	 *
	 * @return array<string, mixed> What receive() stores, or a refusal.
	 */
	private function receivedResult(array $resource, array $values, $mac)
	{
		$name = (string) $resource['name'];
		$type = (string) ($resource['type'] ?? 'template');

		if ($type !== 'log') {
			return [
				'status' => false,
				'message' => sprintf(_('%s is a file this profile serves, not a log it receives.'), $name),
			];
		}

		return [
			'status' => true,
			'kind' => 'log',
			'type' => 'log',
			'resource' => $name,
			'path' => $this->storedLog($resource, $values, $mac),
		];
	}

	/**
	 * Where this client's copy of this log resource is on disk.
	 *
	 * The one expression both directions go through -- receivedResult() to
	 * write it, resourceResult() to read it back.
	 *
	 * The name is the resource's own, rendered against this client, and not
	 * the filename the phone PUT to. What the phone spelled is addressing: it
	 * is how the request found its way here, and it carries whatever the
	 * vendor decided to put in front of the name. What is stored is the file,
	 * and the file is the resource. So a profile whose log resource is
	 * `{{device.mac}}-boot.log` stores `0004f282e824-boot.log` and one whose
	 * resource is `boot.log` stores `boot.log` -- in that client's own
	 * directory either way, which is what says whose it is.
	 *
	 * Rendered rather than taken as typed, because a name is a template here
	 * as much as anywhere else in the module: it is the same call
	 * matchResource() makes on the way in, so what a resource is stored as is
	 * what it is addressed by.
	 *
	 * @param array<string, mixed>  $resource The resource row.
	 * @param array<string, string> $values   Placeholder name to value.
	 * @param string                $mac      Client whose copy it is.
	 *
	 * @return string Absolute path, or '' when there is no such path.
	 */
	private function storedLog(array $resource, array $values, $mac)
	{
		return $this->logs->logFile(
			$mac,
			$this->template->renderTemplate((string) $resource['name'], $values)
		);
	}

	/**
	 * Write a text response and end the request.
	 *
	 * @param int    $code HTTP status code.
	 * @param string $body Response body.
	 * @param string $type Content type, without the charset.
	 *
	 * @return void Never returns.
	 */
	private function sendText($code, $body, $type = 'text/plain')
	{
		$body = (string) $body;

		http_response_code($code);
		header('Content-Type: ' . $type . '; charset=utf-8');
		header('Content-Length: ' . strlen($body));
		// A phone that re-reads its config expects what is stored now, not
		// what a cache kept from the last time it asked.
		header('Cache-Control: no-store');

		// A phone HEADs before it GETs. The length is what it asked for; the
		// body is not.
		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
			echo $body;
		}

		exit;
	}

	/**
	 * Send an uploaded file and end the request.
	 *
	 * What sendText() does not have to think about, because a config is a few
	 * kilobytes and a firmware image is forty megabytes:
	 *
	 *  - the body is never a string. Output buffering is dropped and the file
	 *    is read straight out to the client.
	 *  - a conditional request is answered with 304. Polycom sends
	 *    If-Modified-Since for firmware, and on a fleet that re-provisions
	 *    nightly that is the difference between a handful of empty replies and
	 *    tens of gigabytes of the same image over and over.
	 *
	 * Cache-Control is no-cache rather than the no-store a config gets: ask
	 * every time, and be told when nothing has changed. The validators are the
	 * file's own size and modification time, so a re-upload invalidates them
	 * without anything having to remember to.
	 *
	 * Ranges are declined rather than half-implemented. No phone here asks for
	 * one, and saying so is better than a client believing 206 is available.
	 *
	 * @param string $path Absolute path to the stored file.
	 * @param string $name Resource name, which is the filename it is served as.
	 * @param string $type The resource's type -- 'file' or 'log'.
	 *
	 * @return void Never returns.
	 */
	private function sendFile($path, $name, $type = 'file')
	{
		$size = (int) @filesize($path);
		$modified = (int) @filemtime($path);
		$etag = sprintf('"%x-%x"', $modified, $size);

		header('Content-Type: ' . $this->matcher->contentType($name, $type));
		// The name it is saved under. Without this a fetch through a client's
		// own URL -- /provisioner/0004f282e824-3111-44500-001.sip.ld -- lands
		// on disk under that whole path segment, MAC and all, when what it is
		// is 3111-44500-001.sip.ld. The resource's name is the answer because
		// the resource's name is what the file is; the MAC in front of it is
		// addressing, and belongs to the request rather than to the file.
		//
		// A phone ignores this header and writes the file wherever it decided
		// to ask for it, so it costs nothing there and is the whole of the
		// difference in a browser. inline rather than attachment: something
		// displayable should still display, and the name is taken from here
		// either way.
		header('Content-Disposition: inline; ' . $this->filenameParameter($name));
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
		header('ETag: ' . $etag);
		header('Cache-Control: no-cache');
		header('Accept-Ranges: none');

		$tag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
		$since = (int) strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? ''));

		// The tag is the stronger of the two and is checked alone when it is
		// there: a client that sent both means the tag.
		if ($tag !== '' ? $tag === $etag : ($since > 0 && $modified > 0 && $since >= $modified)) {
			http_response_code(304);
			exit;
		}

		http_response_code(200);
		header('Content-Length: ' . $size);

		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
			exit;
		}

		// Nothing between the file and the client: a readfile() into an output
		// buffer is the string this whole path exists to avoid.
		while (ob_get_level()) {
			ob_end_clean();
		}

		@set_time_limit(0);
		readfile($path);
		exit;
	}

	/**
	 * A filename as Content-Disposition spells it.
	 *
	 * Two spellings of the one name, which is what RFC 6266 asks for: a bare
	 * `filename` every client understands, with anything outside printable
	 * ASCII -- and the quotes and backslashes that would end the header
	 * early -- folded to underscores, and a `filename*` carrying the name as
	 * it really is for the clients that read it.
	 *
	 * basename() because a name is a name. Saving a resource already refuses
	 * a slash; a response header is not where that should be found out.
	 *
	 * @param string $name Resource name, which is the filename it is served as.
	 *
	 * @return string The filename and filename* parameters.
	 */
	private function filenameParameter($name)
	{
		$name = basename((string) $name);
		$ascii = str_replace(['\\', '"'], '_', preg_replace('/[^\x20-\x7E]/', '_', $name));

		return sprintf('filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($name));
	}
}
