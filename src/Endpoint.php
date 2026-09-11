<?php

// src/Endpoint.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Answering a provisioning request, and ending it.
 *
 * resolveRequest() works out the answer and returns it; serve() is the only
 * thing that ends the request. A preview or a console command calls the former.
 */
class Endpoint extends Service
{
	/** @var Clients */
	private $clients;

	/** @var Matcher */
	private $matcher;

	/** @var Template */
	private $template;

	/** @var FileRepo */
	private $files;

	/** @var LogRepo */
	private $logs;

	/** @var ProvisioningLog */
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
	 * Called by engine/provisioner.php, the only route a phone can reach: FreePBX's
	 * config.php sends every session-less request to the login page long before a
	 * module's doConfigPageInit() runs.
	 *
	 *   serve($mac)                          the main config, [mac].cfg
	 *   serve($mac, '0004f282e824-web.cfg')  any other file that profile serves
	 *   serve('', '3111-44500-001.sip.ld')   a file, asked for by name alone
	 *
	 * The MAC may be empty -- a phone fetching firmware sends none -- and such a
	 * request is answered only from resources that carry a file.
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
	 * serve() the other way round, and the reason a resource has a type at all: a
	 * Polycom PUTs /0004f282e824-boot.log the moment it finishes starting up.
	 *
	 * A resource of type Log is where those go, matched exactly as a served file
	 * is -- one a profile has not declared is refused rather than written, which is
	 * what keeps this from being an open upload to the PBX.
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

			// Everything up to the write said yes, so a failure here is this server's
			// and not the phone's -- which has nothing to do differently either way.
			$result = $stored['status']
				? $result + ['message' => sprintf('%s bytes', $stored['bytes'])]
				: $stored;
		}

		$this->answer($result, $mac, $requested);
	}

	/**
	 * Report what was decided, send it, and end the request.
	 *
	 * Everything serve() and receive() have in common -- status, Asterisk log line,
	 * 401, provisioning-log row, 404, body -- since two copies of it is how the two
	 * directions drift apart.
	 *
	 * The kind decides the body: a template goes as text, a file is streamed from
	 * disk and never becomes a string, and a log gets none, because a phone that
	 * PUT one reads the status and nothing else.
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

		// A MAC nothing is associated with is logged as readily as one that
		// renders: it is the request an operator most needs to see and the one
		// that leaves no other trace.
		$this->requestLog->logRequest($mac, $requested, $status, $result['message'] ?? null);

		// And, when it went out, that this client was heard from -- here because
		// this is the one place every answered request passes through. Only a 200;
		// see touchClient().
		if ($status === 200) {
			$this->clients->touchClient($mac);
		}

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
	 * Separate from serve() because this is the part worth calling again -- a
	 * preview, a console command, a test. Only the caller there ends the request.
	 *
	 * Three steps, and the first that answers wins:
	 *
	 *   1. A MAC naming a client with a profile: that profile's resources, matched
	 *      by name. The profile is the authority -- a name it does not serve is
	 *      refused here, or a profile could never withhold a file.
	 *   2. No MAC, a MAC naming no client, or a client with no profile: the
	 *      resources that carry an uploaded file, matched by name exactly.
	 *   3. Nothing.
	 *
	 * Step 2 is files only: a template matched with no client has no values to
	 * render against, so the phone would get a configuration that parses and is
	 * wrong. The consequence, plainly -- an uploaded file can be fetched by anyone
	 * who reaches the endpoint and knows what it is called.
	 *
	 * A request naming no file is a request for [mac].cfg, filled in inside step 1
	 * so that exactly one string is ever matched against.
	 *
	 * @param mixed       $mac       MAC address, written however it was written, or ''.
	 * @param string|null $requested Filename asked for, or null for the main config.
	 *
	 * @return array<string, mixed> Status, what answers, and a message when nothing did.
	 */
	public function resolveRequest($mac, $requested = null, $token = null, $method = 'GET')
	{
		$mac = Mac::normalize($mac);
		$requested = trim((string) $requested);
		$sending = $method === 'PUT' || $method === 'POST';

		$client = $mac === '' ? null : $this->clients->clientByMac($mac);

		// A client switched off is answered nothing, before anything else is
		// looked at -- including the files served by name to callers with no
		// client at all, which a narrower switch would leave going out to it. It
		// reads as a refusal rather than an unknown MAC: the operator on the Logs
		// tab is who these messages are for.
		if ($client && !(int) ($client['enabled'] ?? 1)) {
			return [
				'status' => false,
				'message' => sprintf(_('%s is disabled.'), $mac),
			];
		}

		// And the same switch one level up: a disabled profile refuses every
		// client assigned to it without any of them being touched. The refusal
		// names the profile, because an operator looking at a run of refusals from
		// phones that are individually fine needs told which one thing to switch
		// back on. Its uploaded files are refused in fileByName().
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

			// Two ways of asking for nothing in particular, both meaning the main
			// config: no filename, and the client's own MAC with nothing after it.
			// Only on the way out -- a PUT to no filename in particular named nothing.
			if ($suffix === '' && !$sending) {
				$requested = $mac . '.cfg';
				$suffix = '.cfg';
			}

			$match = $suffix === ''
				? null
				: $this->matcher->matchResource((int) $client['profile_id'], $requested, $suffix, $values);

			// The token guards the client rather than any one of its files, so it is
			// asked for as soon as something is matched and before the type is looked
			// at: a 401 that depended on what was asked for would tell an
			// unauthenticated caller which files exist.
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

			// Nothing this profile serves answers to the name, [mac].cfg included: a
			// profile that serves nothing is unfinished, not one with an implicit
			// main config.
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
	 * The one place that knows what the kinds of resource are: a template is
	 * rendered for the client that asked, a file is sent as it was stored, and a
	 * log is sent back as this client last PUT it.
	 *
	 * A log is readable on purpose -- it is the one resource whose content is a
	 * fact about the phone rather than about the profile -- and it is not
	 * rendered: a boot log with `{{` in it is a boot log.
	 *
	 * A row that says it is a file with no file in the repository is a refusal,
	 * not a quiet fall back to the template underneath: a phone handed an empty
	 * config where it expected firmware fails where nobody can see it.
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
	 * resourceResult() the other way round, and shorter: the only question in this
	 * direction is whether the profile said it takes this. The path carried back is
	 * storedLog()'s, the same one a GET of this resource reads from, so what a
	 * phone PUTs and what it gets back are the same file by construction.
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
	 * The one expression both directions go through -- receivedResult() to write
	 * it, resourceResult() to read it back.
	 *
	 * The name is the resource's own rendered against this client, not the filename
	 * the phone PUT to: what the phone spelled is addressing, what is stored is the
	 * resource. In that client's own directory either way.
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
	 * kilobytes and a firmware image is forty megabytes: the body is never a string
	 * -- buffering is dropped and the file read straight out -- and a conditional
	 * request is answered with 304, which on a fleet that re-provisions nightly is
	 * the difference between empty replies and tens of gigabytes of the same image.
	 *
	 * Cache-Control is no-cache rather than a config's no-store, validated by the
	 * file's own size and mtime. Ranges are declined rather than half-implemented.
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
		// The name it is saved under. Without this, a fetch through a client's own
		// URL lands on disk under the whole path segment, MAC and all, when what
		// it is is 3111-44500-001.sip.ld. inline rather than attachment, so
		// something displayable still displays.
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
	 * Two spellings of the one name, as RFC 6266 asks: a bare `filename` every
	 * client understands, with anything outside printable ASCII -- and the quotes
	 * and backslashes that would end the header early -- folded to underscores,
	 * and a `filename*` carrying the name as it really is.
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
