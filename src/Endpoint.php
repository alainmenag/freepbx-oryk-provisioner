<?php

// src/Endpoint.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Answering a provisioning request, and ending it.
 *
 * resolveRequest() decides; serve(), receive() and openProvision() are the only
 * things that end the request, and each asks Bans first. Anything that wants
 * the answer without the exit -- a preview, a console command -- calls
 * resolveRequest(); an admin's Render and Download go through adminResult().
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

	/** @var Profiles */
	private $profiles;

	/** @var Users */
	private $users;

	/** @var Bans */
	private $bans;

	/** @var RealtimeBridge|null Null where nothing is bridged: the tests. */
	private $bridge;

	/** @var SignupSweep|null Raises the PBX-cap notice; null in the tests. */
	private $sweep;

	/** @var Settings */
	private $settings;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Clients $clients, Matcher $matcher, Template $template, FileRepo $files, LogRepo $logs, ProvisioningLog $requestLog, Profiles $profiles, Users $users, Bans $bans, ?RealtimeBridge $bridge = null, ?SignupSweep $sweep = null)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->matcher = $matcher;
		$this->template = $template;
		$this->files = $files;
		$this->logs = $logs;
		$this->requestLog = $requestLog;
		$this->profiles = $profiles;
		$this->users = $users;
		$this->bans = $bans;
		$this->bridge = $bridge;
		$this->sweep = $sweep;
		$this->settings = new Settings($freepbx);
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
		$banned = $this->banned($mac);

		if ($banned) {
			$this->answer($banned, $mac, $requested);
		}

		$this->answer($this->resolveRequest($mac, $requested, $token, 'GET', self::vendor()), $mac, $requested, self::accept());
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
		$banned = $this->banned($mac);

		if ($banned) {
			$this->answer($banned, $mac, $requested);
		}

		$result = $this->resolveRequest($mac, $requested, $token, 'PUT', self::vendor());

		// The one thing this does that serve() does not: the body is written
		// before the request is answered.
		if ($result['status']) {
			$stored = $this->logs->storeLog($result['path']);

			// Everything up to the write said yes, so a failure here is this
			// server's and not the phone's. Answered as one thing either way; the
			// log says which, and the byte count rides back on a success.
			$result = $stored['status']
				? $result + ['message' => $stored['kept'] < $stored['bytes']
					? sprintf('%d bytes, kept the newest %d', $stored['bytes'], $stored['kept'])
					: sprintf('%d bytes', $stored['bytes'])]
				: $stored + ['code' => 500];
		}

		$this->answer($result, $mac, $requested);
	}

	/**
	 * Answer a request for Mac::OPEN by its credentials, and end the request.
	 *
	 * Served or received as the client openClient() returns, with the all-zero
	 * MAC in the filename swapped for that client's. The address, and the
	 * username as a user, are checked against Bans before anything is looked up
	 * or made -- an Allow that decides lifts the sign-up limits -- and the client
	 * found is checked by serve() or receive().
	 *
	 * @param string|null $username  Basic username offered.
	 * @param string|null $password  Basic password offered.
	 * @param string|null $requested Filename asked for, or null for the main config.
	 * @param string      $method    GET, HEAD or PUT.
	 *
	 * @return void Never returns; the request ends here.
	 */
	public function openProvision($username, $password, $requested = null, $method = 'GET')
	{
		$banned = $this->banned(Mac::OPEN, $username, $trusted);

		if ($banned) {
			$this->answer($banned, Mac::OPEN, $requested);
		}

		$opened = $this->openClient($username, $password, (string) ($_SERVER['REMOTE_ADDR'] ?? ''), $trusted);

		if (!$opened['status']) {
			$this->answer($opened, Mac::OPEN, $requested);
		}

		$requested = (string) $requested;
		$suffix = $this->matcher->resourceSuffix($requested, Mac::OPEN);
		$requested = $suffix === $requested ? $requested : $opened['mac'] . $suffix;
		$token = $username . ':' . $password;

		if ($method === 'PUT') {
			$this->receive($opened['mac'], $requested, $token);
		}

		$this->serve($opened['mac'], $requested, $token);
	}

	/**
	 * The client a request for Mac::OPEN is answered as, made when need be.
	 *
	 * An existing login is answered as its user. A username no account holds
	 * is a sign-up: under Users::LOCK, the name is checked (taken, malformed,
	 * reserved), the limits are counted unless $trusted, and the user, its
	 * client and its bridge rows are written -- see ARCHITECTURE.md, "Open
	 * provisioning". Every sign-up, and every refusal, is a line in FreePBX's
	 * security log; only a username held under another password is written as
	 * the GUI login failure FreePBX's own fail2ban jail bans for.
	 *
	 * @param string|null $username Basic username offered.
	 * @param string|null $password Basic password offered.
	 * @param string      $address  Address the request came from, for the
	 *                              limits and the security log.
	 * @param bool        $trusted  Whether an Allow ban decided the request:
	 *                              no limits. Never the context.
	 *
	 * @return array<string, mixed> status, and mac, extension and created on
	 *                              success; code, message and, on a 429,
	 *                              retry (seconds) on a refusal.
	 */
	public function openClient($username, $password, $address, $trusted = false)
	{
		if ((string) $username === '' || (string) $password === '') {
			return ['status' => false, 'code' => 401, 'message' => _('Open provisioning needs a username and password.')];
		}

		$username = (string) $username;
		$token = $username . ':' . $password;

		try {
			$user = $this->users->findLogin($username, $password);

			if ($user) {
				$client = $this->clients->findOrCreateForDevice($user['extension'], $token);

				// A login that outlived its extension or device was just given them back.
				if (!empty($user['rebuilt'])) {
					SecurityLog::write(sprintf(
						'Open provisioning rebuilt extension %s for user %s context %s from %s',
						$user['extension'],
						SecurityLog::scrub($username),
						$this->users->lobbyContext(),
						SecurityLog::scrub($address)
					));
				}
			} else {
				// One lock across the user, its client and its bridge rows: the
				// limits count clients, so a second sign-up must not count before
				// this one's client is written.
				$made = $this->users->withLock(function () use ($username, $password, $token, $address, $trusted) {
					$user = $this->users->signUp($username, $password, function () use ($address, $trusted) {
						$this->admit($address, $trusted);
					});

					if ($user === null) {
						return null;
					}

					$client = $this->clients->findOrCreateForDevice($user['extension'], $token, $address);

					if ($this->bridge) {
						$this->bridge->add($user['extension']);
					}

					return [$user, $client];
				});

				if ($made === null) {
					SecurityLog::write(sprintf('Authentication failure for %s from %s', SecurityLog::scrub($username), SecurityLog::scrub($address)));

					return ['status' => false, 'code' => 401, 'message' => _('That username is held under another password.')];
				}

				list($user, $client) = $made;

				SecurityLog::write(sprintf(
					'Open provisioning sign-up: user %s extension %s context %s from %s%s',
					SecurityLog::scrub($username),
					$user['extension'],
					$this->users->lobbyContext(),
					SecurityLog::scrub($address),
					$trusted ? ' (allowed)' : ''
				));
			}
		} catch (SignupRefused $e) {
			SecurityLog::write(sprintf(
				'Open provisioning sign-up refused (%s) for %s from %s',
				$e->reason(),
				SecurityLog::scrub($username),
				SecurityLog::scrub($address)
			));

			return ['status' => false, 'code' => $e->status(), 'message' => $e->getMessage(), 'retry' => $e->retry()];
		} catch (\InvalidArgumentException $e) {
			return ['status' => false, 'code' => 400, 'message' => $e->getMessage()];
		} catch (\RuntimeException $e) {
			return ['status' => false, 'code' => 409, 'message' => $e->getMessage()];
		} catch (\Exception $e) {
			$this->logError('open provisioning failed: ' . $e->getMessage());

			return ['status' => false, 'code' => 500, 'message' => _('The user or client could not be saved.')];
		}

		return [
			'status' => true,
			'mac' => $client['mac'],
			'extension' => $user['extension'],
			'created' => $user['created'] || $client['created'] || !empty($user['rebuilt']),
		];
	}

	/**
	 * Whether one more sign-up from an address is allowed, by the limits.
	 *
	 * Per address (an IPv6 /64 -- Clients::signupKey()) in a minute and in a
	 * rolling day, then the whole PBX in a day; 0 is no limit. Asked under
	 * Users::LOCK, so what it counts is everything already written.
	 *
	 * @param string $address Address the request came from.
	 * @param bool   $trusted Whether an Allow ban decided the request.
	 *
	 * @return void
	 *
	 * @throws SignupRefused A 429, with how long to wait, when a limit is reached.
	 */
	private function admit($address, $trusted)
	{
		if ($trusted) {
			return;
		}

		$limits = [
			'per-minute' => [(int) $this->settings->get(Settings::OPEN_PER_MINUTE), 60, $address],
			'per-day' => [(int) $this->settings->get(Settings::OPEN_PER_DAY), 86400, $address],
			'daily total' => [(int) $this->settings->get(Settings::OPEN_PER_DAY_TOTAL), 86400, null],
		];

		foreach ($limits as $reason => list($limit, $window, $from)) {
			if ($limit <= 0) {
				continue;
			}

			$count = $from === null
				? $this->clients->signupsSince($window)
				: $this->clients->signupsFrom($from, $window);

			if ($count < $limit) {
				continue;
			}

			if ($from === null && $this->sweep) {
				$this->sweep->capReached($limit);
			}

			throw new SignupRefused($reason, 429, $from === null
				? _('Open provisioning has taken all the sign-ups it takes in a day; try again later.')
				: _('Too many sign-ups from this address; try again later.'), $this->clients->signupRetry($from, $window));
		}
	}

	/**
	 * The 403 a ban answers this request with, or null when none does.
	 *
	 * Asked of the address it came from, its MAC, the client that MAC names, that
	 * client's device and extension, and the profile it is served -- its own, or
	 * the one Profiles::profileFor() picks -- or, with no client, the open
	 * provisioning username. See Bans::decision() for which row decides.
	 *
	 * @param mixed       $mac      MAC the request was made with.
	 * @param string|null $username Open provisioning's Basic username, or null.
	 * @param bool|null   $trusted  Set to whether an allow decided it.
	 *
	 * @return array<string, mixed>|null A refusal for answer(), or null.
	 */
	private function banned($mac, $username = null, &$trusted = null)
	{
		$mac = Mac::normalize($mac);
		$client = $mac === '' ? null : $this->clients->clientByMac($mac);
		$profile = $client ? ($client['profile_id'] ?? null) : null;

		if ($client && $profile === null) {
			$served = $this->profiles->profileFor(self::vendor());
			$profile = $served ? $served['id'] : null;
		}

		$row = $this->bans->decision([
			'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
			'mac' => $mac,
			'client' => $client ? (string) $client['id'] : '',
			'profile' => (string) $profile,
			'user' => $client
				? [(string) $client['device_id'], (string) $client['extension']]
				: [(string) $username],
		]);

		$trusted = $row !== null && $row['state'] === 'allow';

		if ($row === null || $trusted) {
			return null;
		}

		// The ban's details go to the provisioning log only: they map a MAC to its extension.
		return [
			'status' => false,
			'code' => 403,
			'message' => Bans::refusal($row),
			'body' => _('Forbidden'),
		];
	}

	/**
	 * The vendor this request's User-Agent names.
	 *
	 * @return string|null See Vendor::fromUserAgent().
	 */
	private static function vendor()
	{
		return Vendor::fromUserAgent($_SERVER['HTTP_USER_AGENT'] ?? '');
	}

	/**
	 * The format this request's Accept header explicitly asks for.
	 *
	 * @return array{format: string, type: string}|null See Transcoder::target().
	 */
	private static function accept()
	{
		return Transcoder::target($_SERVER['HTTP_ACCEPT'] ?? '');
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
	 * A template or file is first transcoded when the request asked for
	 * another format -- see transcoded().
	 *
	 * A refusal's `message` is what the logs record; `body`, when set, is what the
	 * caller is sent instead -- a ban's details are the operator's, not the caller's.
	 *
	 * @param array<string, mixed>                     $result    What resolveRequest() decided.
	 * @param mixed                                    $mac       MAC the request was made with.
	 * @param string|null                              $requested Filename as it was asked for.
	 * @param array{format: string, type: string}|null $target    Format asked for, or null to
	 *                                                            send as stored.
	 *
	 * @return void Never returns; the request ends here.
	 */
	private function answer(array $result, $mac, $requested, $target = null)
	{
		// Before the log lines, so a 406 is logged as one.
		$result = $this->transcoded($result, $target);

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

		// Both outcomes, and a MAC nothing is associated with as readily as one
		// that renders: that request is the one an operator most needs to see and
		// the one that leaves no other trace.
		$this->requestLog->logRequest($mac, $requested, $status, $result['message'] ?? null);

		// After the log line: a 401 is a request like any other, and a run of
		// them is how a phone with the wrong token shows up.
		if ($status === 401) {
			header('WWW-Authenticate: Basic realm="Provisioning"');
			http_response_code(401);
			exit;
		}

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

		if (!empty($result['retry'])) {
			header('Retry-After: ' . (int) $result['retry']);
		}

		if (!$result['status']) {
			$this->sendText($status, ($result['body'] ?? $result['message']) . "\n");
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

		$this->sendText(200, $result['config'], $result['contentType'] ?? $this->matcher->contentType((string) $result['resource'], 'template'));
	}

	/**
	 * A result rewritten into the format the request asked for.
	 *
	 * Left alone when nothing was asked for, when it is not a template or file,
	 * or when it is already in that format -- then it goes out byte for byte, and
	 * a file keeps its ETag. Otherwise it comes back as a template carrying the
	 * rewritten text, or a 406 when it cannot be rewritten: binary, too large,
	 * or in no format Transcoder reads.
	 *
	 * @param array<string, mixed>                     $result What resolveRequest() decided.
	 * @param array{format: string, type: string}|null $target Format asked for.
	 *
	 * @return array<string, mixed> The result to answer with.
	 */
	private function transcoded(array $result, $target)
	{
		$kind = $result['kind'] ?? '';

		if ($target === null || !$result['status'] || ($kind !== 'template' && $kind !== 'file')) {
			return $result;
		}

		$text = $kind === 'template'
			? (string) $result['config']
			: Transcoder::readText((string) $result['path']);

		$from = $text === null ? null : Transcoder::detect($text);

		if ($from === $target['format']) {
			return $result;
		}

		$body = $from === null ? null : Transcoder::transcode($text, $from, $target['format']);

		if ($body === null) {
			return [
				'status' => false,
				'code' => 406,
				'message' => sprintf(_('%s cannot be sent as %s.'), (string) $result['resource'], $target['type']),
			];
		}

		return [
			'kind' => 'template',
			'config' => $body . "\n",
			'contentType' => $target['type'],
			'message' => sprintf(_('as %s'), $target['type']),
		] + $result;
	}

	/**
	 * Which file answers a request, and what it is.
	 *
	 * Separate from serve() because this is the part worth calling again: a
	 * preview, a console command, a test. Only the caller there ends the request.
	 *
	 * Three steps, and the first that answers wins:
	 *
	 *   1. A MAC naming a client with a profile -- its own, or, when it has
	 *      none, the one named after $vendor, or failing that the one named
	 *      Profiles::FALLBACK: that profile's resources, matched
	 *      by name. The profile is the authority -- a name it does not serve is
	 *      refused here rather than looked for elsewhere, or a profile could never
	 *      withhold a file.
	 *   2. No MAC, a MAC naming no client, or a client still with no profile: the
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
	 * @param string|null $vendor    Vendor the User-Agent names (Vendor), or
	 *                               null for none.
	 *
	 * @return array<string, mixed> Status, what answers when something does,
	 *                              and a message when nothing did.
	 */
	public function resolveRequest($mac, $requested = null, $token = null, $method = 'GET', $vendor = null)
	{
		$mac = Mac::normalize($mac);
		$requested = trim((string) $requested);
		$sending = $method === 'PUT' || $method === 'POST';

		$client = $mac === '' ? null : $this->clients->clientByMac($mac);

		// A switched-off client is answered nothing, before anything else is
		// looked at: not its profile's files, not the files served by name to
		// callers with no client at all, and not a log it tries to send. A switch
		// that only covered what the profile serves would leave firmware still
		// going out to it. A 403, as for a disabled profile: the client is
		// known, it is switched off.
		if ($client && !(int) ($client['enabled'] ?? 1)) {
			return [
				'status' => false,
				'message' => sprintf(_('%s is disabled.'), $mac),
				'code' => 403,
			];
		}

		// A client with no profile of its own is served the one named after its
		// vendor, or the fallback, for this request only: nothing is stored, so
		// assigning a profile, or renaming this one, takes effect on the next request.
		if ($client && $client['profile_id'] === null) {
			$profile = $this->profiles->profileFor($vendor);

			if ($profile) {
				$client['profile_id'] = (int) $profile['id'];
				$client['profile_name'] = (string) $profile['name'];
				$client['profile_enabled'] = (int) $profile['enabled'];
			}
		}

		// The same switch one level up: a disabled profile serves nothing to
		// anybody, so every client assigned to it is refused without any of those
		// clients having been touched. The refusal names the profile, because an
		// operator reading a run of refusals from phones that are individually
		// fine needs told which one thing to switch back on. A 403, as for a
		// disabled client.
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
				'code' => 403,
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
	 * One resource as one client is sent it, as text in a tab of its own, and
	 * end the request.
	 *
	 * The Render button. text/plain with inertHeaders(): this is the GUI's
	 * origin, and the body may be a log a phone PUT. A file readText() will not
	 * read is answered with its size. Not transcoded.
	 *
	 * @param mixed $clientId   Client id.
	 * @param mixed $resourceId Resource id.
	 *
	 * @return void Never returns; the request ends here.
	 */
	public function viewResource($clientId, $resourceId)
	{
		$result = $this->adminResult($clientId, $resourceId);

		self::inertHeaders();

		if (!$result['status']) {
			$this->sendText($result['code'] ?? 404, $result['message'] . "\n");
		}

		$body = $result['kind'] === 'template'
			? (string) $result['config']
			: Transcoder::readText((string) $result['path']);

		if ($body === null) {
			$body = sprintf(
				_('%1$s is %2$d bytes, binary or too large to show here. Download saves it.'),
				(string) $result['filename'],
				(int) @filesize((string) $result['path'])
			) . "\n";
		}

		header('Content-Disposition: inline; ' . $this->filenameParameter((string) $result['filename']));
		$this->sendText(200, $body);
	}

	/**
	 * Headers that stop a browser running a response as a page: nosniff, and a
	 * sandboxing CSP. Phones ignore both; engine/provisioner.php sends the same pair.
	 *
	 * @return void
	 */
	private static function inertHeaders()
	{
		header('X-Content-Type-Options: nosniff');
		header("Content-Security-Policy: sandbox; default-src 'none'");
	}

	/**
	 * One resource as one client is sent it, as a download, and end the request.
	 *
	 * The Download button: the same body as Render, any size, saved under the
	 * filename this client asks for it by. A refusal is sent as text with its
	 * status, since there is no page to show it on.
	 *
	 * @param mixed $clientId   Client id.
	 * @param mixed $resourceId Resource id.
	 *
	 * @return void Never returns; the request ends here.
	 */
	public function downloadResource($clientId, $resourceId)
	{
		$result = $this->adminResult($clientId, $resourceId);

		if (!$result['status']) {
			$this->sendText($result['code'] ?? 404, $result['message'] . "\n");
		}

		if ($result['kind'] === 'file') {
			$this->sendFile((string) $result['path'], (string) $result['filename'], (string) $result['type'], 'attachment');
		}

		header('Content-Disposition: attachment; ' . $this->filenameParameter((string) $result['filename']));
		$this->sendText(200, $result['config'], $this->matcher->contentType((string) $result['filename'], 'template'));
	}

	/**
	 * What resourceResult() answers one client's request for one resource with,
	 * asked by id from the admin.
	 *
	 * Behind Render and Download. What guards the endpoint is not applied: the
	 * caller is a logged-in admin, so no token is asked for, and a disabled
	 * client or profile is answered all the same. Nothing is logged and the
	 * client is not marked as seen -- Open, the endpoint URL itself, is the
	 * request that counts.
	 *
	 * @param mixed $clientId   Client id.
	 * @param mixed $resourceId Resource id.
	 *
	 * @return array<string, mixed> resourceResult()'s answer plus the filename
	 *                              this client asks for it by, or a refusal.
	 */
	private function adminResult($clientId, $resourceId)
	{
		$row = $this->clients->clientRow($clientId);
		$client = $row ? $this->clients->clientByMac((string) $row['mac']) : null;

		if (!$client) {
			return ['status' => false, 'code' => 404, 'message' => _('That client does not exist.')];
		}

		$stmt = $this->db->prepare(
			"SELECT id, profile_id, name, type, template, file_size
			FROM `{$this->resourcesTable}`
			WHERE id = :id"
		);
		$stmt->execute([':id' => (int) $resourceId]);
		$resource = $stmt->fetch(\PDO::FETCH_ASSOC);

		if (!$resource) {
			return ['status' => false, 'code' => 404, 'message' => _('That resource does not exist.')];
		}

		// The same test Previews applies before it draws the buttons.
		if ($client['profile_id'] === null || (int) $client['profile_id'] !== (int) $resource['profile_id']) {
			return [
				'status' => false,
				'code' => 404,
				'message' => sprintf(_('%s is not served to this client.'), (string) $resource['name']),
			];
		}

		$values = $this->template->provisioningValues($client);
		$request = $this->matcher->resourceRequest((string) $resource['name'], $values, (string) $client['mac']);
		$result = $this->resourceResult($resource, $values, (int) $client['id']);

		return $result['status']
			? $result + ['filename' => (string) $request['filename']]
			: $result + ['code' => 404];
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
		header('Vary: Accept');

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
	 * @param string $type        The resource's type -- 'file' or 'log'.
	 * @param string $disposition inline, or attachment for a download.
	 *
	 * @return void Never returns.
	 */
	private function sendFile($path, $name, $type = 'file', $disposition = 'inline')
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
		header('Content-Disposition: ' . $disposition . '; ' . $this->filenameParameter($name));
		header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $modified) . ' GMT');
		header('ETag: ' . $etag);
		header('Cache-Control: no-cache');
		header('Vary: Accept');
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
