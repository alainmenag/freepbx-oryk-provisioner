<?php

// src/Jobs.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * The service jobs: one row per change to what one user holds, and its steps.
 *
 * What a job is, when one is made and how the worker runs it is
 * ARCHITECTURE.md, "Jobs". This is the two tables: writing a job, the list,
 * a job's page, and the questions ServiceEngine asks while it runs one.
 */
class Jobs extends Service
{
	/** What a job can be: waiting, being run, finished, or stopped at a step that threw. */
	const STATES = ['queued', 'running', 'done', 'failed'];

	/** Why a job was made. */
	const REASONS = ['assigned', 'unassigned', 'changed', 'service-deleted', 'pack-changed'];

	/** What made it: the module's pages, or an install or upgrade regrouping the defaults. */
	const SOURCES = ['gui', 'upgrade'];

	/** Days a finished job is kept; a failed one is kept until it succeeds or is deleted. */
	const KEEP_DAYS = 30;

	/** Seconds a reaction waits for another worker's to finish: they rewrite shared files. */
	const REACTION_WAIT = 30;

	/** @var bool Whether start() launches the worker; off where nothing can run it: the tests. */
	public $autostart = true;

	/**
	 * Write one job and its steps. The caller's transaction holds it.
	 *
	 * @param string                           $extension The user.
	 * @param string                           $service   Slug of the service the change was made to, '' for an upgrade or several at once.
	 * @param string                           $name      Its name as it is now.
	 * @param string                           $reason    One of REASONS.
	 * @param string                           $source    One of SOURCES.
	 * @param array<int, array<string, mixed>> $steps     ServiceEngine::changes(), each with `name` added.
	 *
	 * @return int The job's id.
	 */
	public function enqueue($extension, $service, $name, $reason, $source, array $steps)
	{
		$stmt = $this->db->prepare(
			"INSERT INTO `{$this->jobsTable}` (extension, service, name, reason, source)
			VALUES (:extension, :service, :name, :reason, :source)"
		);
		$stmt->execute([
			':extension' => (string) $extension,
			':service' => (string) $service,
			':name' => mb_substr((string) $name, 0, Services::NAME_MAX),
			':reason' => (string) $reason,
			':source' => (string) $source,
		]);

		$id = (int) $this->db->lastInsertId();

		$add = $this->db->prepare(
			"INSERT INTO `{$this->jobStepsTable}` (job_id, position, service, name, via, event)
			VALUES (:job, :position, :service, :name, :via, :event)"
		);

		foreach (array_values($steps) as $position => $step) {
			$add->execute([
				':job' => $id,
				':position' => $position,
				':service' => (string) $step['service'],
				':name' => mb_substr((string) ($step['name'] ?? ''), 0, Services::NAME_MAX),
				':via' => $step['via'] !== null ? (string) $step['via'] : null,
				':event' => (string) $step['event'],
			]);
		}

		return $id;
	}

	/**
	 * Start the worker in the background, and return at once.
	 *
	 * Fire and forget: when it cannot start, the minute job runs what is queued.
	 *
	 * @param string|null $extension One user's queue, or null for every user with one.
	 *
	 * @return bool True when it was started.
	 */
	public function start($extension = null)
	{
		if (!$this->autostart || !function_exists('exec')) {
			return false;
		}

		$command = escapeshellarg($this->phpBinary()) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/oryk-jobs');

		if ($extension !== null) {
			$command .= ' --user=' . escapeshellarg((string) $extension);
		}

		@exec('nohup ' . $command . ' > /dev/null 2>&1 &', $output, $status);

		return $status === 0;
	}

	/**
	 * Start the worker for the users some jobs were written for.
	 *
	 * @param array<int, string> $extensions The users.
	 *
	 * @return void
	 */
	public function startFor(array $extensions)
	{
		if (count($extensions) === 1) {
			$this->start((string) reset($extensions));
		} elseif ($extensions) {
			$this->start();
		}
	}

	/**
	 * What each state, reason, source and step event is called on a page.
	 *
	 * @return array<string, array<string, string>> state, reason, source, event: labels by value.
	 */
	public static function labels()
	{
		return [
			'state' => ['queued' => _('Queued'), 'running' => _('Running'), 'done' => _('Done'), 'failed' => _('Failed')],
			'reason' => ['assigned' => _('Assigned'), 'unassigned' => _('Unassigned'), 'changed' => _('Changed'), 'service-deleted' => _('Service deleted'), 'pack-changed' => _('Pack changed')],
			'source' => ['gui' => _('Admin'), 'upgrade' => _('Upgrade')],
			'event' => ['granted' => _('Grant'), 'revoked' => _('Revoke')],
			'step' => ['pending' => _('Pending'), 'done' => _('Done'), 'failed' => _('Failed'), 'skipped' => _('Skipped')],
		];
	}

	/**
	 * What a job is called: the service it was made for, or what made it for none.
	 *
	 * @param array<string, mixed> $job A jobs row.
	 *
	 * @return string Its title.
	 */
	public static function title(array $job)
	{
		if ((string) ($job['name'] ?? '') !== '') {
			return (string) $job['name'];
		}

		return (string) ($job['reason'] ?? '') === 'changed' ? _('Several services') : _('Module upgrade');
	}

	/**
	 * What the Jobs page's address says its three filters are set to.
	 *
	 * @param array<string, mixed> $request The page's request: `state`, `reason`, `source`.
	 *
	 * @return array<string, string> Each a value of its list, or all.
	 */
	public static function filters(array $request)
	{
		$lists = ['state' => self::STATES, 'reason' => self::REASONS, 'source' => self::SOURCES];
		$filters = [];

		foreach ($lists as $key => $values) {
			$filters[$key] = in_array($request[$key] ?? '', $values, true) ? (string) $request[$key] : 'all';
		}

		return $filters;
	}

	/**
	 * Rows for the Jobs table, newest first unless sorted otherwise.
	 *
	 * Narrowed by the three filters in the request, and by a navigator scope.
	 *
	 * @param array<string, array<int, string>|null>|null $narrow Navigator::scope()'s `jobs`: extensions
	 *                                                            and/or services, or null for every job.
	 *
	 * @return array<string, mixed> total, rows, and counts: for each filter,
	 *                              how many each value would list with the
	 *                              other two as they are.
	 */
	public function listJobs($narrow = null)
	{
		$sortable = [
			'id' => 'j.id',
			'created_at' => 'j.id',
			'extension' => 'j.extension',
			'name' => 'j.name',
			'reason' => 'j.reason',
			'state' => 'j.state',
			'attempts' => 'j.attempts',
			'finished_at' => 'j.finished_at',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? 'j.id';
		$order = strtolower((string) ($_REQUEST['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');
		$filters = self::filters($_REQUEST);

		$params = [];
		$clauses = $this->narrowed($narrow, $params);

		foreach ($filters as $column => $value) {
			if ($value !== 'all') {
				$clauses[] = "j.`$column` = :f_$column";
				$params[":f_$column"] = $value;
			}
		}

		if ($search !== '') {
			$clauses[] = '(j.extension LIKE :search OR j.name LIKE :search_name OR j.error LIKE :search_error)';
			$params[':search'] = '%' . $search . '%';
			$params[':search_name'] = '%' . $search . '%';
			$params[':search_error'] = '%' . $search . '%';
		}

		$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

		$count = $this->db->prepare("SELECT COUNT(*) FROM `{$this->jobsTable}` j $where");
		$count->execute($params);

		$stmt = $this->db->prepare(
			"SELECT j.*,
				(SELECT COUNT(*) FROM `{$this->jobStepsTable}` s WHERE s.job_id = j.id) AS steps,
				(SELECT COUNT(*) FROM `{$this->jobStepsTable}` s WHERE s.job_id = j.id AND s.state IN ('done', 'skipped')) AS steps_done
			FROM `{$this->jobsTable}` j
			$where
			ORDER BY $sort $order, j.id DESC
			LIMIT :limit OFFSET :offset"
		);

		foreach ($params as $key => $value) {
			$stmt->bindValue($key, $value);
		}

		$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
		$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
		$stmt->execute();

		return [
			'total' => (int) $count->fetchColumn(),
			'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
			'counts' => $this->counts($narrow, $filters),
		];
	}

	/**
	 * One job, with its steps.
	 *
	 * @param mixed $id Job id.
	 *
	 * @return array<string, mixed>|null The row, `steps` in order; null when there is none.
	 */
	public function jobRow($id)
	{
		if (!ctype_digit((string) $id)) {
			return null;
		}

		$stmt = $this->db->prepare("SELECT * FROM `{$this->jobsTable}` WHERE id = :id");
		$stmt->execute([':id' => (int) $id]);
		$job = $stmt->fetch(PDO::FETCH_ASSOC);

		if (!$job) {
			return null;
		}

		$job['steps'] = $this->steps((int) $id);

		return $job;
	}

	/**
	 * A job's steps, in the order they run.
	 *
	 * @param int $id Job id.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function steps($id)
	{
		$stmt = $this->db->prepare(
			"SELECT * FROM `{$this->jobStepsTable}` WHERE job_id = :id ORDER BY position"
		);
		$stmt->execute([':id' => (int) $id]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * The newest jobs in a scope, for the navigator.
	 *
	 * @param array<string, array<int, string>|null>|null $narrow As listJobs() takes it.
	 * @param int                                         $limit  The most returned.
	 *
	 * @return array<int, array<string, mixed>> id, extension, name, reason, state, created_at.
	 */
	public function jobChoices($narrow, $limit)
	{
		$params = [];
		$clauses = $this->narrowed($narrow, $params);

		$stmt = $this->db->prepare(
			"SELECT j.id, j.extension, j.service, j.name, j.reason, j.state, j.created_at
			FROM `{$this->jobsTable}` j
			" . ($clauses ? 'WHERE ' . implode(' AND ', $clauses) : '') . "
			ORDER BY j.id DESC
			LIMIT " . (int) $limit
		);
		$stmt->execute($params);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Where each service stands for a user: its newest step, and that step's job.
	 *
	 * @param string $extension The user.
	 *
	 * @return array<string, array<string, mixed>> By slug: job (id), state
	 *                                             (queued|running|failed), error. A
	 *                                             service whose newest step finished
	 *                                             is left out.
	 */
	public function statusFor($extension)
	{
		$stmt = $this->db->prepare(
			"SELECT s.service, s.state AS step, s.error, j.id, j.state
			FROM `{$this->jobStepsTable}` s
			JOIN `{$this->jobsTable}` j ON j.id = s.job_id
			WHERE j.extension = :extension
			ORDER BY j.id DESC, s.position"
		);
		$stmt->execute([':extension' => (string) $extension]);

		$status = [];
		$seen = [];

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
			$slug = (string) $row['service'];

			if (isset($seen[$slug])) {
				continue;
			}

			$seen[$slug] = true;

			if (in_array($row['step'], ['done', 'skipped'], true)) {
				continue;
			}

			// A job queued or running again is that, whatever its steps said last
			// time; a failed one's unfinished steps are all stuck, reached or not.
			$state = (string) $row['state'];

			$status[$slug] = [
				'job' => (int) $row['id'],
				'state' => $state,
				'error' => (string) ($row['error'] ?? ''),
			];
		}

		return $status;
	}

	/**
	 * Delete a job and its steps. A running one stops before its next step.
	 *
	 * @param mixed $id Job id.
	 *
	 * @return array<string, mixed> Status.
	 */
	public function deleteJob($id)
	{
		if (!ctype_digit((string) $id)) {
			return ['status' => false, 'message' => _('That job no longer exists.')];
		}

		$this->deleteWhere('j.id = :id', [':id' => (int) $id]);

		return ['status' => true];
	}

	/**
	 * Put a failed job back in its user's queue, and start the worker on it.
	 *
	 * Its steps that finished are not run again; see ServiceEngine.
	 *
	 * @param mixed $id Job id.
	 *
	 * @return array<string, mixed> Status, and a message when refused.
	 */
	public function retryJob($id)
	{
		$job = $this->jobRow($id);

		if (!$job) {
			return ['status' => false, 'message' => _('That job no longer exists.')];
		}

		$stmt = $this->db->prepare(
			"UPDATE `{$this->jobsTable}` SET state = 'queued' WHERE id = :id AND state = 'failed'"
		);
		$stmt->execute([':id' => (int) $id]);

		if (!$stmt->rowCount()) {
			return ['status' => false, 'message' => _('Only a failed job can be retried.')];
		}

		$this->start((string) $job['extension']);

		return ['status' => true];
	}

	/**
	 * Delete every job of a user: it has been deleted.
	 *
	 * @param mixed $extension The user's extension.
	 *
	 * @return void
	 */
	public function forgetUser($extension)
	{
		try {
			$this->deleteWhere('j.extension = :extension', [':extension' => (string) $extension]);
		} catch (\Exception $e) {
			// No table before the upgrade that adds it: nothing to delete.
		}
	}

	/**
	 * Carry a user's jobs to its new number: it has been renumbered.
	 *
	 * @param mixed $old The extension it had.
	 * @param mixed $new The extension it has.
	 *
	 * @return void
	 */
	public function moveUser($old, $new)
	{
		try {
			$stmt = $this->db->prepare(
				"UPDATE `{$this->jobsTable}` SET extension = :new WHERE extension = :old"
			);
			$stmt->execute([':new' => (string) $new, ':old' => (string) $old]);
		} catch (\Exception $e) {
			$this->logError('could not move the jobs of ' . $old . ' to ' . $new . ': ' . $e->getMessage());
		}
	}

	/**
	 * Carry a renamed service's slug to every job and step naming it.
	 *
	 * Called from inside Services::renameLinks()'s transaction.
	 *
	 * @param string $was  The slug they hold.
	 * @param string $slug The slug they are to hold.
	 *
	 * @return void
	 */
	public function renameService($was, $slug)
	{
		$updates = [
			[$this->jobsTable, 'service'],
			[$this->jobStepsTable, 'service'],
			[$this->jobStepsTable, 'via'],
		];

		foreach ($updates as list($table, $column)) {
			$stmt = $this->db->prepare("UPDATE `$table` SET `$column` = :slug WHERE `$column` = :was");
			$stmt->execute([':slug' => $slug, ':was' => $was]);
		}
	}

	/**
	 * Delete the jobs that finished more than KEEP_DAYS ago.
	 *
	 * @return int How many.
	 */
	public function purge()
	{
		return $this->deleteWhere(
			"j.state = 'done' AND j.finished_at < DATE_SUB(NOW(), INTERVAL " . (int) self::KEEP_DAYS . ' DAY)',
			[]
		);
	}

	/**
	 * Take a user's queue, for as long as this connection holds it.
	 *
	 * A MySQL named lock: freed by the server when the process holding it
	 * dies, so a crashed worker never keeps a user locked.
	 *
	 * @param string $extension The user.
	 *
	 * @return bool True when this connection now holds it.
	 */
	public function lock($extension)
	{
		return $this->getLock(self::lockName($extension), 0);
	}

	/**
	 * Give a user's queue back.
	 *
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	public function unlock($extension)
	{
		$this->releaseLock(self::lockName($extension));
	}

	/**
	 * Take the lock the module's own reactions run under, across every worker.
	 *
	 * Users' queues run side by side, and Voicemail's reaction rewrites all of
	 * voicemail.conf: two at once would each write over the other's mailbox.
	 *
	 * @return bool False when another worker held it for REACTION_WAIT seconds.
	 */
	public function lockReactions()
	{
		return $this->getLock('oryk_provisioner_reactions', self::REACTION_WAIT);
	}

	/**
	 * Give lockReactions()'s lock back.
	 *
	 * @return void
	 */
	public function unlockReactions()
	{
		$this->releaseLock('oryk_provisioner_reactions');
	}

	/**
	 * Take the lock the reload after the jobs runs under, if no worker has it.
	 *
	 * @return bool False when another worker is reloading.
	 */
	public function lockReload()
	{
		return $this->getLock('oryk_provisioner_reload', 0);
	}

	/**
	 * Give lockReload()'s lock back.
	 *
	 * @return void
	 */
	public function unlockReload()
	{
		$this->releaseLock('oryk_provisioner_reload');
	}

	/**
	 * Whose a job is now: renumbering a user moves its jobs, a running one included.
	 *
	 * @param int $id Job id.
	 *
	 * @return string|null The extension, or null when the job has been deleted.
	 */
	public function extensionOf($id)
	{
		$stmt = $this->db->prepare("SELECT extension FROM `{$this->jobsTable}` WHERE id = :id");
		$stmt->execute([':id' => (int) $id]);
		$extension = $stmt->fetchColumn();

		return $extension === false ? null : (string) $extension;
	}

	/**
	 * Every user with a job waiting, or one left running by a worker that died.
	 *
	 * @return array<int, string> Extensions.
	 */
	public function usersWaiting()
	{
		$stmt = $this->db->prepare(
			"SELECT DISTINCT extension FROM `{$this->jobsTable}` WHERE state IN ('queued', 'running') ORDER BY extension"
		);
		$stmt->execute();

		return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
	}

	/**
	 * Fail a user's running jobs. Only asked by the holder of the user's lock,
	 * which is the only thing that runs one: any it finds was left by a worker
	 * that died.
	 *
	 * @param string $extension The user.
	 *
	 * @return int How many.
	 */
	public function failOrphans($extension)
	{
		$stmt = $this->db->prepare(
			"UPDATE `{$this->jobsTable}` SET state = 'failed', error = :error, finished_at = NOW()
			WHERE extension = :extension AND state = 'running'"
		);
		$stmt->execute([':error' => _('The worker stopped before this job finished.'), ':extension' => (string) $extension]);

		return $stmt->rowCount();
	}

	/**
	 * A user's oldest queued job.
	 *
	 * @param string $extension The user.
	 *
	 * @return array<string, mixed>|null The row, or null when nothing is queued.
	 */
	public function nextQueued($extension)
	{
		$stmt = $this->db->prepare(
			"SELECT * FROM `{$this->jobsTable}` WHERE extension = :extension AND state = 'queued' ORDER BY id LIMIT 1"
		);
		$stmt->execute([':extension' => (string) $extension]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ?: null;
	}

	/**
	 * A user's failed jobs older than one, oldest first: what the automatic retry runs.
	 *
	 * @param string $extension The user.
	 * @param int    $before    The job just run.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function failedBefore($extension, $before)
	{
		$stmt = $this->db->prepare(
			"SELECT * FROM `{$this->jobsTable}` WHERE extension = :extension AND state = 'failed' AND id < :before ORDER BY id"
		);
		$stmt->execute([':extension' => (string) $extension, ':before' => (int) $before]);

		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/**
	 * Mark a queued or failed job running, if nothing else has: the claim.
	 *
	 * @param int $id Job id.
	 *
	 * @return bool False when it has been deleted, or is not waiting.
	 */
	public function claim($id)
	{
		$stmt = $this->db->prepare(
			"UPDATE `{$this->jobsTable}`
			SET state = 'running', attempts = attempts + 1, started_at = NOW(), error = NULL
			WHERE id = :id AND state IN ('queued', 'failed')"
		);
		$stmt->execute([':id' => (int) $id]);

		return $stmt->rowCount() === 1;
	}

	/**
	 * Record what became of one step.
	 *
	 * @param int               $id     Step id.
	 * @param string            $state  done|failed|skipped.
	 * @param array<int,string> $doneBy The handlers that have finished it.
	 * @param string|null       $error  Why it failed.
	 *
	 * @return void
	 */
	public function markStep($id, $state, array $doneBy, $error = null)
	{
		$stmt = $this->db->prepare(
			"UPDATE `{$this->jobStepsTable}`
			SET state = :state, done_by = :done, error = :error, attempts = attempts + :tried,
				finished_at = " . ($state === 'failed' ? 'NULL' : 'NOW()') . "
			WHERE id = :id"
		);
		$stmt->execute([
			':state' => $state,
			':done' => implode(',', $doneBy),
			':error' => $error,
			':tried' => $state === 'skipped' ? 0 : 1,
			':id' => (int) $id,
		]);
	}

	/**
	 * Record which handlers have finished a step, as each one does.
	 *
	 * @param int               $id     Step id.
	 * @param array<int,string> $doneBy The handlers that have finished it.
	 *
	 * @return void
	 */
	public function stepProgress($id, array $doneBy)
	{
		$stmt = $this->db->prepare("UPDATE `{$this->jobStepsTable}` SET done_by = :done WHERE id = :id");
		$stmt->execute([':done' => implode(',', $doneBy), ':id' => (int) $id]);
	}

	/**
	 * Record which of the module's own jobs ran for a step.
	 *
	 * @param int    $id    Step id.
	 * @param string $class Its class name under src/Jobs/, unqualified.
	 *
	 * @return void
	 */
	public function stepOwnJob($id, $class)
	{
		$stmt = $this->db->prepare("UPDATE `{$this->jobStepsTable}` SET own_job = :job WHERE id = :id");
		$stmt->execute([':job' => mb_substr((string) $class, 0, 64), ':id' => (int) $id]);
	}

	/**
	 * Record how a run of a job ended.
	 *
	 * @param int         $id    Job id.
	 * @param string      $state done|failed.
	 * @param string|null $error What stopped it.
	 *
	 * @return void
	 */
	public function finish($id, $state, $error = null)
	{
		$stmt = $this->db->prepare(
			"UPDATE `{$this->jobsTable}` SET state = :state, error = :error, finished_at = NOW() WHERE id = :id"
		);
		$stmt->execute([':state' => $state, ':error' => $error, ':id' => (int) $id]);
	}

	/**
	 * The handlers that have finished a step, as markStep() stored them.
	 *
	 * @param array<string, mixed> $step A steps row.
	 *
	 * @return array<int, string> Rawnames.
	 */
	public static function doneBy(array $step)
	{
		return array_values(array_filter(explode(',', (string) ($step['done_by'] ?? '')), 'strlen'));
	}

	/**
	 * Take a MySQL named lock for this connection.
	 *
	 * @param string $name    Lock name.
	 * @param int    $timeout Seconds to wait for it.
	 *
	 * @return bool True when it is now held.
	 */
	private function getLock($name, $timeout)
	{
		$stmt = $this->db->prepare('SELECT GET_LOCK(:name, ' . (int) $timeout . ')');
		$stmt->execute([':name' => $name]);

		return (int) $stmt->fetchColumn() === 1;
	}

	/**
	 * Give a named lock back.
	 *
	 * @param string $name Lock name.
	 *
	 * @return void
	 */
	private function releaseLock($name)
	{
		$stmt = $this->db->prepare('SELECT RELEASE_LOCK(:name)');
		$stmt->execute([':name' => $name]);
	}

	/**
	 * The named lock a user's queue is held by.
	 *
	 * @param string $extension The user.
	 *
	 * @return string Within MySQL's 64 characters: an extension is at most 20.
	 */
	private static function lockName($extension)
	{
		return 'oryk_provisioner_jobs_' . substr((string) $extension, 0, 40);
	}

	/**
	 * The clauses a navigator scope narrows the jobs by.
	 *
	 * @param array<string, array<int, string>|null>|null $narrow As listJobs() takes it.
	 * @param array<string, mixed>                        $params Placeholders, added to.
	 *
	 * @return array<int, string> Clauses, ANDed.
	 */
	private function narrowed($narrow, array &$params)
	{
		$clauses = [];

		if (is_array($narrow) && isset($narrow['extensions'])) {
			$clauses[] = $this->inClause('j.extension', $narrow['extensions'], 'ext', $params);
		}

		if (is_array($narrow) && isset($narrow['services'])) {
			$clauses[] = $this->inClause('j.service', $narrow['services'], 'svc', $params);
		}

		return $clauses;
	}

	/**
	 * How many jobs each value of each filter holds, the other two as set.
	 *
	 * @param array<string, array<int, string>|null>|null $narrow  As listJobs() takes it.
	 * @param array<string, string>                       $filters filters().
	 *
	 * @return array<string, array<string, int>> By filter, then value.
	 */
	private function counts($narrow, array $filters)
	{
		$counts = [];

		foreach (['state' => self::STATES, 'reason' => self::REASONS, 'source' => self::SOURCES] as $column => $values) {
			$params = [];
			$clauses = $this->narrowed($narrow, $params);

			foreach ($filters as $other => $value) {
				if ($other !== $column && $value !== 'all') {
					$clauses[] = "j.`$other` = :f_$other";
					$params[":f_$other"] = $value;
				}
			}

			$stmt = $this->db->prepare(
				"SELECT j.`$column`, COUNT(*) FROM `{$this->jobsTable}` j "
				. ($clauses ? 'WHERE ' . implode(' AND ', $clauses) : '')
				. " GROUP BY j.`$column`"
			);
			$stmt->execute($params);

			$counts[$column] = array_fill_keys($values, 0);

			foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
				if (isset($counts[$column][(string) $row[0]])) {
					$counts[$column][(string) $row[0]] = (int) $row[1];
				}
			}
		}

		return $counts;
	}

	/**
	 * Delete some jobs and their steps.
	 *
	 * @param string               $where  A clause over `j`, written here, never from a request.
	 * @param array<string, mixed> $params Its placeholders.
	 *
	 * @return int Jobs deleted.
	 */
	private function deleteWhere($where, array $params)
	{
		$steps = $this->db->prepare(
			"DELETE s FROM `{$this->jobStepsTable}` s JOIN `{$this->jobsTable}` j ON j.id = s.job_id WHERE $where"
		);
		$steps->execute($params);

		$jobs = $this->db->prepare("DELETE j FROM `{$this->jobsTable}` j WHERE $where");
		$jobs->execute($params);

		return $jobs->rowCount();
	}
}
