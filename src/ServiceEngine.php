<?php

// src/ServiceEngine.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * What a change to services means for a user, and running it: the job worker.
 *
 * changes() turns a user's services before and after a change into steps;
 * run() and drain() carry them out, one job at a time per user, calling the
 * module's own reaction and then every module hooked to serviceGranted or
 * serviceRevoked. The rules -- ordering, retries, what stops a job -- are
 * ARCHITECTURE.md, "Jobs".
 */
class ServiceEngine extends Service
{
	/** The name the module's own reaction is recorded under in a step's `done_by`. */
	const OWN = 'oryk_provisioner';

	/** The class other modules hook in their module.xml, as FreePBX keys it: namespace\class. */
	const HOOK_CLASS = 'FreePBX\\modules\\Oryk_provisioner';

	/** The method a listening module declares as `callingMethod`, by event. */
	const METHODS = ['granted' => 'serviceGranted', 'revoked' => 'serviceRevoked'];

	/** @var Jobs */
	private $jobs;

	/** @var Services */
	private $services;

	/** @var Reactions */
	private $reactions;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Jobs $jobs, Services $services, Reactions $reactions)
	{
		parent::__construct($freepbx);

		$this->jobs = $jobs;
		$this->services = $services;
		$this->reactions = $reactions;
	}

	/**
	 * The steps a change makes for one user: what it holds after and did not
	 * before is granted, what it held and no longer does is revoked.
	 *
	 * A service still held another way is never revoked, and one already held
	 * is never granted again. Revokes come first, then grants, each by slug.
	 *
	 * @param array<int, array{0: string, 1: string}> $linksBefore Every link before the change.
	 * @param array<int, string>                      $before      What the user was assigned.
	 * @param array<int, array{0: string, 1: string}> $linksAfter  Every link after it.
	 * @param array<int, string>                      $after       What the user is assigned.
	 *
	 * @return array<int, array<string, mixed>> Each: service, event (granted|revoked),
	 *                                          via (the assigned service it comes or
	 *                                          went through, null for itself).
	 */
	public static function changes(array $linksBefore, array $before, array $linksAfter, array $after)
	{
		$held = Services::held($linksBefore, $before);
		$holds = Services::held($linksAfter, $after);
		$steps = [];

		foreach (array_values(array_diff($held, $holds)) as $slug) {
			$steps[] = ['service' => (string) $slug, 'event' => 'revoked', 'via' => self::via($linksBefore, $before, $slug)];
		}

		foreach (array_values(array_diff($holds, $held)) as $slug) {
			$steps[] = ['service' => (string) $slug, 'event' => 'granted', 'via' => self::via($linksAfter, $after, $slug)];
		}

		return $steps;
	}

	/**
	 * One run of the worker: one user's queue, or every user's with something in it.
	 *
	 * A user that throws is logged and passed over; the others still run.
	 *
	 * @param string|null $extension One user, or null for all of them and the purge.
	 *
	 * @return array<string, int> users, done, failed, purged.
	 */
	public function run($extension = null)
	{
		$result = ['users' => 0, 'done' => 0, 'failed' => 0, 'purged' => 0];
		$users = $extension !== null ? [(string) $extension] : $this->jobs->usersWaiting();

		foreach ($users as $user) {
			try {
				$tally = $this->drain($user);
			} catch (\Throwable $e) {
				$this->logError('the jobs of ' . $user . ' stopped: ' . $e->getMessage());

				continue;
			}

			$result['users']++;
			$result['done'] += $tally['done'];
			$result['failed'] += $tally['failed'];
		}

		if ($extension === null) {
			$result['purged'] = $this->jobs->purge();
		}

		return $result;
	}

	/**
	 * Run one user's queue, under its lock, until nothing is queued.
	 *
	 * After each job, the user's failed jobs older than it are tried again;
	 * with nothing queued it stops, and a failed job waits for Retry. The lock
	 * already held means another worker is draining this user: nothing is done.
	 *
	 * @param string $extension The user.
	 *
	 * @return array<string, int> done, failed: runs that ended so.
	 */
	public function drain($extension)
	{
		$extension = (string) $extension;
		$tally = ['done' => 0, 'failed' => 0];

		// Asked again once the lock is given back: a job queued while it was
		// held, after the last look, would otherwise wait for the minute job.
		do {
			if (!$this->jobs->lock($extension)) {
				break;
			}

			try {
				$this->jobs->failOrphans($extension);

				while ($next = $this->jobs->nextQueued($extension)) {
					$this->tally($tally, $this->runJob($next));

					foreach ($this->jobs->failedBefore($extension, (int) $next['id']) as $failed) {
						$this->tally($tally, $this->runJob($failed));
					}
				}
			} finally {
				$this->jobs->unlock($extension);
			}
		} while ($this->jobs->nextQueued($extension));

		return $tally;
	}

	/**
	 * Call every handler of one event in turn: the module's own, then each
	 * hooked module in FreePBX's hook order.
	 *
	 * @param string                $event    granted|revoked.
	 * @param array<string, mixed>  $payload  What each handler is given; see payload().
	 * @param array<int, string>    $skip     Handlers that have already finished it.
	 * @param callable|null         $finished Told the rawname of each one that returns.
	 *
	 * @return void
	 *
	 * @throws HandlerFailed The first handler that throws: the rest are not called.
	 */
	public function dispatch($event, array $payload, array $skip = [], ?callable $finished = null)
	{
		foreach ($this->handlers($event) as $name => $call) {
			if (in_array($name, $skip, true)) {
				continue;
			}

			try {
				$call($payload);
			} catch (HandlerFailed $e) {
				throw $e;
			} catch (\Throwable $e) {
				throw new HandlerFailed($name, $e->getMessage());
			}

			if ($finished) {
				$finished($name);
			}
		}
	}

	/**
	 * Run one job from where it stopped: the claim, then each step not yet finished.
	 *
	 * @param array<string, mixed> $job A jobs row.
	 *
	 * @return string|null done|failed, or null when it was not run: deleted,
	 *                     taken, or its user gone.
	 */
	private function runJob(array $job)
	{
		$id = (int) $job['id'];
		$extension = (string) $job['extension'];

		if (!$this->jobs->claim($id)) {
			return null;
		}

		// Not deleted: Core saves an extension by deleting and adding it again,
		// and a job claimed in between would take the user's queue with it. A
		// user really deleted loses its jobs to the hook on Core's delUser.
		if (!$this->services->userExists($extension)) {
			$this->jobs->finish($id, 'failed', sprintf(_('User %s was not found.'), $extension));

			return 'failed';
		}

		$job = $this->jobs->jobRow($id) ?: $job;

		foreach ($this->jobs->steps($id) as $step) {
			if (in_array((string) $step['state'], ['done', 'skipped'], true)) {
				continue;
			}

			// Deleting a job is how it is stopped; a renumber moves it to another
			// user's queue, whose worker takes it from here.
			if ($this->jobs->extensionOf($id) !== $extension) {
				return null;
			}

			$done = Jobs::doneBy($step);
			$holds = in_array((string) $step['service'], $this->services->userSlugs($extension), true);

			// What a later change has already undone is not done after it.
			if ($holds !== ((string) $step['event'] === 'granted')) {
				$this->jobs->markStep((int) $step['id'], 'skipped', $done);

				continue;
			}

			try {
				$this->dispatch((string) $step['event'], $this->payload($job, $step), $done, function ($name) use (&$done, $step) {
					$done[] = $name;
					$this->jobs->stepProgress((int) $step['id'], $done);
				});
			} catch (HandlerFailed $e) {
				$this->jobs->markStep((int) $step['id'], 'failed', $done, $e->getMessage());
				$this->jobs->finish($id, 'failed', $e->getMessage());

				return 'failed';
			}

			$this->jobs->markStep((int) $step['id'], 'done', $done);
		}

		$this->jobs->finish($id, 'done');

		return 'done';
	}

	/**
	 * What a handler is given for one step. Documented for module authors in docs/hooks.md.
	 *
	 * @param array<string, mixed> $job  The jobs row.
	 * @param array<string, mixed> $step The step.
	 *
	 * @return array<string, mixed> The event.
	 */
	private function payload(array $job, array $step)
	{
		return [
			'event' => (string) $step['event'],
			'extension' => (string) $job['extension'],
			'service' => (string) $step['service'],
			'name' => (string) $step['name'],
			'via' => $step['via'] !== null ? (string) $step['via'] : null,
			'reason' => (string) $job['reason'],
			'source' => (string) $job['source'],
			'changed' => (string) $job['service'],
			'job' => (int) $job['id'],
			'step' => (int) $step['id'],
			'attempt' => (int) $step['attempts'] + 1,
		];
	}

	/**
	 * Every handler of an event, by the rawname recorded for it, in the order called.
	 *
	 * @param string $event granted|revoked.
	 *
	 * @return array<string, callable> Handlers.
	 *
	 * @throws HandlerFailed When FreePBX's hook list cannot be read.
	 */
	private function handlers($event)
	{
		$handlers = [
			self::OWN => function (array $payload) use ($event) {
				if (!Reactions::handles((string) ($payload['service'] ?? ''))) {
					return;
				}

				if (!$this->jobs->lockReactions()) {
					throw new \RuntimeException(_('another worker held the module\'s reactions too long'));
				}

				try {
					$this->reactions->handle($event, $payload);
				} finally {
					$this->jobs->unlockReactions();
				}
			},
		];

		try {
			$hooks = $this->FreePBX->Hooks->returnHooksByClassMethod(self::HOOK_CLASS, self::METHODS[$event]);
		} catch (\Throwable $e) {
			throw new HandlerFailed('framework', sprintf(_('the hooks could not be read: %s'), $e->getMessage()));
		}

		foreach ((array) $hooks as $hook) {
			$name = strtolower((string) ($hook['module'] ?? ''));

			if ($name === '' || isset($handlers[$name])) {
				continue;
			}

			$handlers[$name] = function (array $payload) use ($hook) {
				$this->callHook($hook, $payload);
			};
		}

		return $handlers;
	}

	/**
	 * Call one listening module the way FreePBX's own Hooks::executeCall() does,
	 * minus what it does wrong for us: here a throw reaches the caller with the
	 * module's text domain popped.
	 *
	 * @param array<string, mixed> $hook    Hooks::returnHooksByClassMethod() entry.
	 * @param array<string, mixed> $payload The event.
	 *
	 * @return void
	 */
	private function callHook(array $hook, array $payload)
	{
		$module = (string) $hook['module'];
		$class = (string) $hook['class'];
		$method = (string) $hook['method'];
		$qualified = (string) $hook['namespace'] . $class;
		$domain = class_exists('modgettext');

		if ($domain) {
			\modgettext::push_textdomain(strtolower($module));
		}

		try {
			if ($module === $class) {
				$target = $this->FreePBX->$module;
			} elseif (!class_exists($qualified)) {
				throw new \RuntimeException(sprintf(_('class %s not found'), $qualified));
			} elseif (!empty($hook['static'])) {
				$target = $qualified;
			} else {
				$target = new $qualified($this->FreePBX);
			}

			if (!method_exists($target, $method)) {
				throw new \RuntimeException(sprintf(_('%s has no method %s'), is_object($target) ? get_class($target) : $target, $method));
			}

			call_user_func([$target, $method], $payload);
		} finally {
			if ($domain) {
				\modgettext::pop_textdomain();
			}
		}
	}

	/**
	 * The assigned service a held one comes through, for a step's `via`.
	 *
	 * @param array<int, array{0: string, 1: string}> $links    Every link.
	 * @param array<int, string>                      $assigned What the user is assigned.
	 * @param string                                  $slug     The service held.
	 *
	 * @return string|null The first by slug; null when it is assigned itself.
	 */
	private static function via(array $links, array $assigned, $slug)
	{
		$assigned = array_map('strval', $assigned);

		if (in_array((string) $slug, $assigned, true)) {
			return null;
		}

		$through = [];

		foreach ($assigned as $one) {
			if (in_array((string) $slug, Services::descendants($links, $one), true)) {
				$through[] = $one;
			}
		}

		sort($through, SORT_STRING);

		return $through ? $through[0] : null;
	}

	/**
	 * Count one run in a tally.
	 *
	 * @param array<string, int> $tally  done, failed.
	 * @param string|null        $result runJob().
	 *
	 * @return void
	 */
	private function tally(array &$tally, $result)
	{
		if ($result !== null) {
			$tally[$result]++;
		}
	}
}
