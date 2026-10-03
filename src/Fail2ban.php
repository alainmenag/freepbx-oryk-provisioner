<?php

// src/Fail2ban.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The only file that asks fail2ban anything.
 *
 * Every question goes through `sudo -n` to the root-owned helper that
 * bin/oryk-fail2ban-setup installs -- see ARCHITECTURE.md, "Syncing with
 * fail2ban". The helper is the privilege boundary and checks its own
 * arguments; what is checked here is checked again there.
 */
class Fail2ban extends Service
{
	/** @var Settings Whose ORYK_FAIL2BAN_SYNC says whether fail2ban is asked at all. */
	private $settings;

	/** @var string Where the setup script installs the helper. */
	const HELPER = '/usr/local/sbin/oryk-fail2ban';

	/** @var array<int, string> Where sudo is looked for, in order. */
	const SUDO = ['/usr/bin/sudo', '/bin/sudo', '/usr/local/bin/sudo'];

	/** @var int Helper exit code: an argument it refused. */
	const EX_USAGE = 64;

	/** @var int Helper exit code: fail2ban not installed or not answering. */
	const EX_UNAVAILABLE = 69;

	/** The permanent jail a Banned row is kept in until it expires, which setup writes. */
	const BANNED_JAIL = 'banned';

	/** The permanent jail a Deny row is pushed into, which setup writes. */
	const DENY_JAIL = 'deny';

	/** @var array<int, string> Jails the sync needs to exist. */
	const JAILS = [self::BANNED_JAIL, self::DENY_JAIL];

	/** @var array<string, mixed>|null status(), once per request. */
	private $status;

	/** @var array<string, array<string, mixed>> Read answers, once per request; a write empties it. */
	private $answers = [];

	/**
	 * @param object   $freepbx  FreePBX application instance.
	 * @param Settings $settings The module's settings.
	 */
	public function __construct($freepbx, Settings $settings)
	{
		parent::__construct($freepbx);

		$this->settings = $settings;
	}

	/**
	 * Whether the sync is on (ORYK_FAIL2BAN_SYNC). Off, nothing here calls
	 * sudo: status() is `disabled` and every question answers not-ok.
	 *
	 * @return bool True when it is on.
	 */
	public function enabled()
	{
		return (bool) $this->settings->get(Settings::FAIL2BAN_SYNC);
	}

	/**
	 * Whether the module can manage fail2ban, and if not, why not.
	 *
	 * `disabled` when ORYK_FAIL2BAN_SYNC is off, without asking anything.
	 * Otherwise the first of these that is true, which is also the order they
	 * have to be fixed in: missing, sudo, stale, fail2ban, nojail, ok.
	 *
	 * @return array<string, mixed> See state().
	 */
	public function status()
	{
		if ($this->status !== null) {
			return $this->status;
		}

		if (!$this->enabled()) {
			return $this->status = self::state('disabled', null, $this->bundledVersion());
		}

		if (!is_file(self::HELPER)) {
			return $this->status = self::state('missing', null, $this->bundledVersion());
		}

		return $this->status = self::state(null, $this->run(['check']), $this->bundledVersion());
	}

	/**
	 * Read a helper's answer to `check` as one of the setup states.
	 *
	 * Static and pure so tests/smoke.php can feed it every answer there is.
	 *
	 * @param string|null               $state   A state already decided, or null.
	 * @param array<string, mixed>|null $answer  run(['check']), when it was run.
	 * @param int|null                  $bundled The helper version this module ships.
	 *
	 * @return array<string, mixed> state, message, detail (what the helper or
	 *                              sudo said, or the jails missing), fail2ban
	 *                              (its version).
	 */
	public static function state($state, $answer, $bundled)
	{
		$answer = is_array($answer) ? $answer : [];
		$exit = (int) ($answer['exit'] ?? 0);

		if ($state === null) {
			if (!array_key_exists('version', $answer)) {
				// No JSON at all: sudo said no before the helper ever ran.
				$state = 'sudo';
			} elseif ((int) $answer['version'] !== (int) $bundled) {
				$state = 'stale';
			} elseif ($exit === self::EX_UNAVAILABLE || empty($answer['ok'])) {
				$state = 'fail2ban';
			} elseif (array_diff(self::JAILS, (array) ($answer['jails'] ?? []))) {
				$state = 'nojail';
				$answer['error'] = implode(', ', array_diff(self::JAILS, (array) ($answer['jails'] ?? [])));
			} else {
				$state = 'ok';
			}
		}

		$messages = [
			'missing' => _('The fail2ban helper is not installed on this PBX.'),
			'sudo' => _('The fail2ban helper is installed, but the web server is not allowed to run it: the sudo rule is missing or wrong.'),
			'stale' => _('The fail2ban helper installed on this PBX is not the one this version of the module ships. Run setup again to update it.'),
			'fail2ban' => _('The helper works, but fail2ban is not installed or not running.'),
			'nojail' => _('fail2ban is missing a jail bans are synced into: banned and deny are made by the setup script.'),
			'disabled' => _('Fail2ban Sync is switched off on the Settings tab.'),
			'ok' => '',
		];

		return [
			'state' => $state,
			'message' => $messages[$state],
			'detail' => $state === 'ok' ? '' : (string) ($answer['error'] ?? ''),
			'fail2ban' => (string) ($answer['fail2ban'] ?? ''),
		];
	}

	/**
	 * The command that fixes every state but `fail2ban`, as root, for this PBX.
	 *
	 * @return string A shell command line.
	 */
	public function setupCommand()
	{
		$script = $this->setupScript();

		return 'sudo bash ' . (preg_match('#^[A-Za-z0-9_./-]+$#', $script) ? $script : escapeshellarg($script));
	}

	/**
	 * Run the setup script as root, for Installer. Never throws.
	 *
	 * @param array<int, string> $args Its flags: none to install, --remove.
	 *
	 * @return array<int, string> What it printed, line by line.
	 */
	public function runSetup(array $args)
	{
		$done = self::process(array_merge(['/bin/bash', $this->setupScript()], $args));

		if ($done === null) {
			return ['could not run ' . $this->setupScript()];
		}

		return array_values(array_filter(array_map('rtrim', preg_split('/\R/', $done['stdout'] . $done['stderr'])), 'strlen'));
	}

	/**
	 * Where the setup script is, in this module as installed.
	 *
	 * @return string Absolute path.
	 */
	public function setupScript()
	{
		$bin = dirname(__DIR__) . '/bin';

		return (realpath($bin) ?: $bin) . '/oryk-fail2ban-setup';
	}

	/**
	 * Every jail's bans, with times, and every jail's ignore list.
	 *
	 * @return array<string, mixed> ok, error; jails, bans (jail, ip, banned_at,
	 *                              bantime, permanent, expires_at -- epochs on
	 *                              the helper's clock), ignore (jail => entries).
	 */
	public function listAll()
	{
		return $this->run(['list']);
	}

	/**
	 * Ban one address in `banned` or `deny`.
	 *
	 * @param string $jail BANNED_JAIL or DENY_JAIL.
	 * @param string $ip   One IP address.
	 *
	 * @return array<string, mixed> ok, error.
	 */
	public function ban($jail, $ip)
	{
		return $this->run(['ban', (string) $jail, (string) $ip]);
	}

	/**
	 * Lift one ban. An address that is not banned is answered as success.
	 *
	 * @param string $jail A jail name.
	 * @param string $ip   One IP address.
	 *
	 * @return array<string, mixed> ok, error.
	 */
	public function unban($jail, $ip)
	{
		return $this->run(['unban', (string) $jail, (string) $ip]);
	}

	/**
	 * Add an address to every jail's ignore list that lacks it.
	 *
	 * @param string $ip One IP address.
	 *
	 * @return array<string, mixed> ok, error, jails (those it was added to).
	 */
	public function ignore($ip)
	{
		return $this->run(['ignore', (string) $ip]);
	}

	/**
	 * Take an address off every jail's ignore list that has it.
	 *
	 * @param string $ip One IP address.
	 *
	 * @return array<string, mixed> ok, error, jails.
	 */
	public function unignore($ip)
	{
		return $this->run(['unignore', (string) $ip]);
	}

	/**
	 * The helper version this module ships, read off bin/oryk-fail2ban.
	 *
	 * The same line the setup script compares, so the tab and the script never
	 * disagree about whether the installed copy is current.
	 *
	 * @return int|null Version, or null when the file cannot be read.
	 */
	public function bundledVersion()
	{
		$source = @file_get_contents(dirname(__DIR__) . '/bin/oryk-fail2ban');

		return self::helperVersion($source === false ? '' : $source);
	}

	/**
	 * The `HELPER_VERSION = <n>` line of a copy of the helper.
	 *
	 * @param string $source The helper's text.
	 *
	 * @return int|null Version, or null when there is no such line.
	 */
	public static function helperVersion($source)
	{
		return preg_match('/^HELPER_VERSION *= *(\d+)/m', (string) $source, $match) ? (int) $match[1] : null;
	}

	/**
	 * Run the helper through sudo and decode what it answered.
	 *
	 * An argument array rather than a command line, so nothing here passes
	 * through a shell. Each call is a sudo and a Python start-up, so a read is
	 * asked once per request and asked again only after a write.
	 *
	 * @param array<int, string> $args Verb and arguments.
	 *
	 * @return array<string, mixed> The helper's JSON, plus exit; ok false and an
	 *                              error when nothing usable came back.
	 */
	private function run(array $args)
	{
		if (!$this->enabled()) {
			return ['ok' => false, 'exit' => 0, 'error' => _('Fail2ban Sync is switched off on the Settings tab.')];
		}

		$key = implode(' ', $args);
		$reads = in_array($args[0], ['check', 'list'], true);

		if ($reads && isset($this->answers[$key])) {
			return $this->answers[$key];
		}

		if (!$reads) {
			$this->answers = [];
		}

		$answer = $this->call($args);

		if ($reads) {
			$this->answers[$key] = $answer;
		}

		return $answer;
	}

	/**
	 * One sudo call to the helper.
	 *
	 * @param array<int, string> $args Verb and arguments.
	 *
	 * @return array<string, mixed> See run().
	 */
	private function call(array $args)
	{
		$sudo = null;

		foreach (self::SUDO as $candidate) {
			if (is_executable($candidate)) {
				$sudo = $candidate;

				break;
			}
		}

		if ($sudo === null) {
			return ['ok' => false, 'exit' => 1, 'error' => _('sudo is not installed.')];
		}

		$done = self::process(array_merge([$sudo, '-n', self::HELPER], $args));

		if ($done === null) {
			return ['ok' => false, 'exit' => 1, 'error' => _('Could not start sudo.')];
		}

		list('stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit) = $done;

		$answer = json_decode(trim($stdout), true);

		if (!is_array($answer)) {
			$this->logWarning('fail2ban helper: ' . trim($stderr . ' ' . $stdout));

			return ['ok' => false, 'exit' => $exit, 'error' => trim($stderr) !== '' ? trim($stderr) : _('The fail2ban helper gave no answer.')];
		}

		return $answer + ['exit' => $exit];
	}

	/**
	 * Run one program with an argument array -- no shell -- and collect what it said.
	 *
	 * @param array<int, string> $command Program and arguments.
	 *
	 * @return array<string, mixed>|null stdout, stderr, exit; null when it could not start.
	 */
	private static function process(array $command)
	{
		$pipes = [];
		$process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

		if (!is_resource($process)) {
			return null;
		}

		$stdout = (string) stream_get_contents($pipes[1]);
		$stderr = (string) stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($process)];
	}
}
