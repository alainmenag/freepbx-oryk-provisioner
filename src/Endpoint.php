<?php

// src/Endpoint.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Answering a provisioning request, and ending it.
 *
 * resolveRequest() decides; serve() and receive() are the only things that end
 * the request. Anything that wants the answer without the exit -- a preview, a
 * console command -- calls resolveRequest().
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
	 * Called by engine/provisioner.php, the only route a phone can reach.
	 *
	 *   serve($mac)                          the main config, [mac].cfg
	 *   serve($mac, '0004f282e824-web.cfg')  any other file that profile serves
	 *   serve('', '3111-44500-001.sip.ld')   a file, asked for by name alone
	 *
	 * The MAC may be empty: a phone fetching firmware puts it nowhere in the
	 * request, so a request that reaches no profile is answered from the resources
	 * that are the same for every caller, which is to say the ones with a file.
	 *
	 * The second argument is the filename as asked for, not a resource id.
	 *
	 * @param mixed       $mac       MAC address, written however it was written, or ''.
	 * @param string|null $requested Filename asked for, or null for the main config.
	 * @param string|null $token     Token offered, when one was -- user:password
	 *                               off the request's Basic credentials.
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
	 * serve() the other way round: a phone PUTs its boot and app logs back, and a
	 * resource of type Log is where those go. It is matched exactly as a served
	 * file is, by the same names against the same profile, so a name the profile
	 * has not declared is refused rather than written -- which is what keeps this
	 * from being an open upload to the PBX for anyone who reaches the endpoint.
	 *
	 * The body goes to ASTLOGDIR/provisioner/[client id], under the resource's own
	 * name rendered against that client.
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
		// before the request is answered.
		if ($result['status']) {
			$stored = $this->logs->storeLog($result['path']);

			// Everything up to the write said yes, so a failure here is this
			// server's and not the phone's. Answered as one thing either way; the
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
	 * The whole of what serve() and receive() have in common, which is everything
	 * except the write in the middle of one of them. Two copies of this is how the
	 * two directions drift apart.
	 *
	 * The kind decides the body, and every kind there is has one:
	 *
	 *   template  the rendered text, as text.
	 *   file      the file on disk, streamed -- never a string: a firmware image
	 *             is tens of megabytes. The type goes with it because it decides
	 *             the content type.
	 *   log       nothing. A phone uploading a log reads the status and nothing
	 *             else.
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
		// provisioning log records for itself.
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
		// that renders: that request is the one an operator most needs to see and
		// the one that leaves no other trace.
		$this->requestLog->logRequest($mac, $requested, $status, $result['message'] ?? null);

		// And, when it went out, that this client was heard from: the one place
		// every answered request passes through, whichever direction it went. The
		// status is the number the provisioning log was just given, so the Clients
		// list and the Logs tab cannot disagree about whether the phone was
		// answered.
		//
		// Only a 200 -- see touchClient().
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
	 * Separate from serve() because this is the part worth calling again: a
	 * preview, a console command, a test. Only the caller there ends the request.
	 *
	 * Three steps, and the first that answers wins:
	 *
	 *   1. A MAC naming a client with a profile: that profile's resources, matched
	 *      by name. The profile is the authority -- a name it does not serve is
	 *      refused here rather than looked for elsewhere, or a profile could never
	 *      withhold a file.
	 *   2. No MAC, a MAC naming no client, or a client with no profile: the
	 *      resources that carry an uploaded file, matched by name exactly.
	 *   3. Nothing.
	 *
	 * Step 2 is files only. A template matched with no client behind it has no
	 * values to render against, so the phone would receive a configuration that
	 * parses and is wrong. The consequence, plainly: an uploaded file can be
	 * fetched by anyone who reaches the endpoint and knows what it is called.
	 *
	 * A request that names no file is a request for the main config, [mac].cfg,
	 * filled in inside step 1 so that exactly one string is matched against.
	 *
	 * Order matters, and is the security of the thing: disabled client, disabled
	 * profile, match, token, then the resource's type. The token is asked for
	 * after a match and before the type, so a 401 cannot reveal which files exist.
	 *
	 * @param mixed       $mac       MAC address, written however it was written, or ''.
	 * @param string|null $requested Filename asked for, or null for the main config.
	 * @param string|null $token     Token offered, when one was -- user:password
	 *                               off the request's Basic credentials.
	 * @param string      $method    Method it is being asked with. PUT and POST
	 *                               are a phone sending a log; anything else is
	 *                               a fetch.
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

		// A switched-off client is answered nothing, before anything else is
		// looked at: not its profile's files, not the files served by name to
		// callers with no client at all, and not a log it tries to send. A switch
		// that only covered what the profile serves would leave firmware still
		// going out to it.
		if ($client && !(int) ($client['enabled'] ?? 1)) {
			return [
				'status' => false,
				'message' => sprintf(_('%s is disabled.'), $mac),
			];
		}

		// The same switch one level up: a disabled profile serves nothing to
		// anybody, so every client assigned to it is refused without any of those
		// clients having been touched. The refusal names the profile, because an
		// operator reading a run of refusals from phones that are individually
		// fine needs told which one thing to switch back on.
		//
		// Its uploaded files are refused in fileByName(), which answers callers
		// with no client behind them and so never reaches this line.
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

			// What is left of the filename with this client's own MAC off the front:
			// 0004f282e824-phone.cfg asked of that client is phone.cfg, and
			// 0004f282e824.cfg is .cfg.
			$suffix = $requested === '' ? '' : $this->matcher->resourceSuffix($requested, $mac);

			// Two ways of asking for nothing in particular, both asking for the main
			// config: no filename at all, and the client's own MAC with nothing after
			// it. Only on the way out -- a PUT to no filename in particular is a
			// request that named nothing, not a PUT of the main config.
			if ($suffix === '' && !$sending) {
				$requested = $mac . '.cfg';
				$suffix = '.cfg';
			}

			$match = $suffix === ''
				? null
				: $this->matcher->matchResource((int) $client['profile_id'], $requested, $suffix, $values);

			// The token guards the client rather than any one of its files, so it is
			// asked for as soon as something has been matched and before the type is
			// looked at: a 401 that depended on what was asked for would tell an
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
					? $this->receivedResult($match, $values, (int) $client['id'])
					: $this->resourceResult($match, $values, (int) $client['id']);

				return $outcome + [
					'mac' => $mac,
					'profile' => (string) $client['profile_name'],
				];
			}

			// Nothing this profile serves answers to the name -- [mac].cfg on a
			// profile with no `.cfg` resource included. There is no template behind a
			// profile to fall back to.
			return [
				'status' => false,
				'message' => $sending
					? sprintf(_('%s is not something this profile takes.'), $requested)
					: sprintf(_('%s is not something this profile serves.'), $requested),
			];
		}

		// A phone sending something has to be one this module knows, because what
		// it is sending is written to disk. The file lookup below is for fetching
		// by name with nobody behind the request, and has no counterpart here.
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
	 * fact about the phone, which is what somebody debugging that phone wants. It
	 * is not rendered: a boot log with `{{` in it is a boot log, not a template.
	 *
	 * Read off `type` and nothing else; never inferred from file_size or from the
	 * template column.
	 *
	 * A row that says it is a file and a repository that has not got one is a
	 * refusal, not a quiet fall back to the template underneath: a phone handed an
	 * empty config where it expected firmware fails in a way nobody can see.
	 *
	 * @param array<string, mixed>  $resource The resource row.
	 * @param array<string, string> $values   Placeholder name to value, empty for a file.
	 * @param int                   $client   Id of the client asking, 0 when there is none.
	 *
	 * @return array<string, mixed> What serve() sends, or a refusal.
	 */
	private function resourceResult(array $resource, array $values, $client = 0)
	{
		$name = (string) $resource['name'];
		$type = (string) ($resource['type'] ?? 'template');

		// Read back from exactly where receive() writes it: both go through
		// storedLog(), so the two sides cannot disagree about the path.
		if ($type === 'log') {
			$stored = $this->storedLog($resource, $values, $client);

			// A log that has not arrived is not a broken resource: the usual reason is
			// a phone that has not rebooted since somebody wrote this.
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

		// Two ways to be a file with nothing behind it, and one message for both:
		// nothing uploaded yet, or something uploaded and no longer on disk.
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
	 * resourceResult() the other way round, and much shorter: the only question in
	 * this direction is whether the profile said it takes this. A PUT to a
	 * resource of any other type is told what that resource is instead, rather
	 * than given a message about method support.
	 *
	 * The path carried back is storedLog()'s, the same path a GET of this resource
	 * reads from, so what a phone PUTs and what it gets back are the same file by
	 * construction.
	 *
	 * @param array<string, mixed>  $resource The resource row.
	 * @param array<string, string> $values   Placeholder name to value.
	 * @param int                   $client   Id of the client sending it.
	 *
	 * @return array<string, mixed> What receive() stores, or a refusal.
	 */
	private function receivedResult(array $resource, array $values, $client)
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
			'path' => $this->storedLog($resource, $values, $client),
		];
	}

	/**
	 * Where this client's copy of this log resource is on disk.
	 *
	 * The one expression both directions go through -- receivedResult() to write
	 * it, resourceResult() to read it back.
	 *
	 * The name is the resource's own, rendered against this client, not the
	 * filename the phone PUT to: what the phone spelled is addressing and carries
	 * whatever the vendor put in front of the name; what is stored is the file,
	 * and the file is the resource. The directory is the client's id, so a client
	 * whose MAC is corrected keeps what it has already sent, and a deleted
	 * client's logs go with the row.
	 *
	 * @param array<string, mixed>  $resource The resource row.
	 * @param array<string, string> $values   Placeholder name to value.
	 * @param int                   $client   Id of the client whose copy it is.
	 *
	 * @return string Absolute path, or '' when there is no such path.
	 */
	private function storedLog(array $resource, array $values, $client)
	{
		return $this->logs->logFile(
			$client,
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
	 *  - the body is never a string. Output buffering is dropped and the file is
	 *    read straight out to the client.
	 *  - a conditional request is answered with 304. Polycom sends
	 *    If-Modified-Since for firmware, and on a fleet that re-provisions nightly
	 *    that is the difference between a handful of empty replies and tens of
	 *    gigabytes of the same image.
	 *
	 * Cache-Control is no-cache rather than the no-store a config gets: ask every
	 * time, and be told when nothing has changed. The validators are the file's
	 * own size and mtime, so a re-upload invalidates them by itself.
	 *
	 * Ranges are declined rather than half-implemented.
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
		// The name it is saved under. Without this a fetch through a client's own
		// URL lands on disk under the whole path segment, MAC and all. The
		// resource's name is what the file is; the MAC in front of it belongs to
		// the request. A phone ignores the header either way, so this is the whole
		// of the difference in a browser.
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
	 * `filename` every client understands, with anything outside printable ASCII
	 * -- and the quotes and backslashes that would end the header early -- folded
	 * to underscores, and a `filename*` carrying the name as it really is.
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
