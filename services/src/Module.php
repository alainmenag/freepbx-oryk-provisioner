<?php

// services/src/Module.php

namespace FreePBX\Modules\Oryk_Services;

/**
 * The services side as one thing: everything outside services/ reaches it
 * through this class and nothing else. See ARCHITECTURE.md, "The services
 * boundary".
 *
 * Built once, in dependency order, like the provisioner's module class. What
 * it offers is what the provisioner, or any other module, may ask of the
 * services; the classes behind it are its own business.
 */
class Module
{
	/** The AJAX commands the services answer: the provisioner hands each to ajax(). */
	const COMMANDS = [
		'listServices', 'saveService', 'deleteService',
		'setUserServices', 'userServicesImpact', 'serviceImpact', 'userServiceJobs',
		'listJobs', 'retryJob', 'deleteJob',
	];

	/** The COMMANDS that only read, and so may be answered to a GET. */
	const READS = ['listServices', 'listJobs', 'userServicesImpact', 'serviceImpact', 'userServiceJobs'];

	/** @var Services */
	private $services;

	/** @var Jobs */
	private $jobs;

	/** @var ServiceEngine */
	private $engine;

	/** @var CalendarManager */
	private $calendar;

	/** @var Schema */
	private $schema;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx)
	{
		$this->jobs = new Jobs($freepbx);
		$this->services = new Services($freepbx, $this->jobs);
		$this->engine = new ServiceEngine($freepbx, $this->jobs, $this->services, new Reactions($freepbx, new Extensions($freepbx)));
		$this->calendar = new CalendarManager($freepbx);
		$this->schema = new Schema($freepbx);
	}

	/**
	 * Make and update the tables, and write the services the module ships.
	 *
	 * A regrouping of those that changes what users hold queues their jobs,
	 * for the minute job to run: an install may be root, and jobs run as the
	 * web user.
	 *
	 * @return array<int, string> What an operator should be told.
	 */
	public function install()
	{
		$this->schema->createTables();

		// CREATE TABLE IF NOT EXISTS leaves a table that is there alone, so
		// every column added after one first existed is added from here.
		$this->schema->addServiceSlugColumn();
		$this->schema->addServiceOwnerColumn(array_keys(Services::DEFAULTS));
		$this->schema->addServiceLinkKindColumn();
		$this->schema->addJobStepOwnJobColumn();
		$this->schema->addJobAdminColumn();
		$this->schema->addServiceAssignmentStatusColumn();

		// After the slug column, which the defaults are found by, and the
		// owner column, which says which rows are the module's.
		$said = $this->services->seed();

		// After seed(), which is what gives every older row the slug this keys on.
		if (!$this->schema->dropServiceIdColumn()) {
			$said[] = 'the services table could not be re-keyed by slug; see the FreePBX log.';
		}

		return $said;
	}

	/**
	 * Answer one of COMMANDS.
	 *
	 * @param string                                  $command One of COMMANDS.
	 * @param array<string, mixed>                    $request The request's fields.
	 * @param array<string, mixed>|null               $scope   What the list is narrowed to:
	 *                                                         `services` and `jobs` as the
	 *                                                         provisioner's Navigator::scope() gives them.
	 *
	 * @return array<string, mixed> AJAX response data.
	 */
	public function ajax($command, array $request, ?array $scope = null)
	{
		switch ($command) {
			case 'listServices':
				return $this->services->listServices($scope['services'] ?? null);

			case 'saveService':
				return $this->services->saveService($request);

			case 'deleteService':
				return $this->services->deleteService($request['slug'] ?? null);

			// What a user's Services tab has staged, saved as one change.
			case 'setUserServices':
				return $this->services->setUserServices($request);

			case 'userServicesImpact':
				return $this->services->userServicesImpact($request);

			case 'serviceImpact':
				return $this->services->serviceImpact($request);

			// Where a user's services stand, for the Services tab's Status while jobs run.
			case 'userServiceJobs':
				return ['status' => true, 'jobs' => $this->jobs->statusFor((string) ($request['extension'] ?? ''))];

			case 'listJobs':
				return $this->jobs->listJobs($scope['jobs'] ?? null);

			case 'retryJob':
				return $this->jobs->retryJob($request['id'] ?? null);

			case 'deleteJob':
				return $this->jobs->deleteJob($request['id'] ?? null);
		}

		return ['status' => false, 'message' => _('Unknown command.')];
	}

	/**
	 * One run of the job worker: one user's queue, or every user's.
	 *
	 * @param string|null $extension One user, or null for every user with a queue.
	 *
	 * @return array<string, int> users, done, failed, purged.
	 */
	public function runJobs($extension = null)
	{
		return $this->engine->run($extension);
	}

	/**
	 * Run every handler of an event, outside any job. See docs/hooks.md.
	 *
	 * @param string               $event   granted|revoked.
	 * @param array<string, mixed> $payload extension and service at least.
	 *
	 * @return void
	 *
	 * @throws HandlerFailed The first handler that threw.
	 */
	public function dispatch($event, array $payload)
	{
		$this->engine->dispatch($event, ['event' => $event] + $payload);
	}

	/**
	 * Run the services' own job for an event's service, if it has one.
	 *
	 * @param string               $event   granted|revoked.
	 * @param array<string, mixed> $payload See docs/hooks.md.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When the job cannot be done.
	 */
	public function ownJob($event, array $payload)
	{
		$this->engine->ownJob($event, $payload);
	}

	/**
	 * Every service a user holds, packs and requirements followed.
	 *
	 * @param string $extension The user.
	 *
	 * @return array<int, string> Slugs, each once, sorted.
	 */
	public function userSlugs($extension)
	{
		return $this->services->userSlugs((string) $extension);
	}

	/**
	 * Whether a user holds a service, however it came by it.
	 *
	 * @param string $extension The user.
	 * @param string $slug      The service.
	 *
	 * @return bool True when it does.
	 */
	public function hasService($extension, $slug)
	{
		return $this->services->hasService((string) $extension, (string) $slug);
	}

	/**
	 * A user is about to be deleted: what of it the services made that the
	 * user's own rows point at goes first -- its calendar, found by its Follow Me row.
	 *
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	public function userDeleting($extension)
	{
		$this->calendar->remove((string) $extension);
	}

	/**
	 * A user has been deleted: its assignments and jobs go.
	 *
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	public function userDeleted($extension)
	{
		$this->services->forgetUser((string) $extension);
	}

	/**
	 * A user has moved to another number: its calendar, assignments and jobs go with it.
	 *
	 * **Call before the old number is reused**: until then its Follow Me row
	 * is what finds the calendar.
	 *
	 * @param string $old The extension it had.
	 * @param string $new The extension it has.
	 *
	 * @return void
	 */
	public function userRenumbered($old, $new)
	{
		$this->calendar->rename((string) $old, (string) $new);
		$this->services->moveUser((string) $old, (string) $new);
	}

	/**
	 * Whether a user has a calendar the services made, which deleting it removes.
	 *
	 * @param string $extension The user.
	 *
	 * @return bool True when it has.
	 */
	public function hasCalendar($extension)
	{
		return $this->calendar->has((string) $extension);
	}

	/**
	 * Delete every job of a user's, run or not.
	 *
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	public function clearJobs($extension)
	{
		$this->jobs->forgetUser((string) $extension);
	}

	/**
	 * Every service, as one user has it, for its Services tab: Services::userServices().
	 *
	 * @param string $extension The user.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function userServices($extension)
	{
		return $this->services->userServices((string) $extension);
	}

	/**
	 * Where a user's services stand while jobs run: Jobs::statusFor().
	 *
	 * @param string $extension The user.
	 *
	 * @return array<string, mixed> By service slug.
	 */
	public function jobStatus($extension)
	{
		return $this->jobs->statusFor((string) $extension);
	}

	/**
	 * One service, by its slug.
	 *
	 * @param string $slug The service.
	 *
	 * @return array<string, mixed>|null The row, or null when there is none.
	 */
	public function serviceBySlug($slug)
	{
		return $this->services->serviceBySlug((string) $slug);
	}

	/**
	 * Every service, for a picker: Services::serviceChoices().
	 *
	 * @return array<int, array<string, mixed>> Rows with slug and name.
	 */
	public function serviceChoices()
	{
		return $this->services->serviceChoices();
	}

	/**
	 * A service's parents, children and requirements, for its page: Services::related().
	 *
	 * @param string $slug The service, or '' for a new one.
	 *
	 * @return array<string, mixed> See Services::related().
	 */
	public function related($slug)
	{
		return $this->services->related((string) $slug);
	}

	/**
	 * The slugs of every service with one under it.
	 *
	 * @return array<int, string> Slugs.
	 */
	public function packs()
	{
		return $this->services->packs();
	}

	/**
	 * The users assigned a service themselves.
	 *
	 * @param string $slug The service.
	 *
	 * @return array<int, string> Extensions.
	 */
	public function usersOf($slug)
	{
		return $this->services->usersOf((string) $slug);
	}

	/**
	 * The users holding a service, through a pack included.
	 *
	 * @param string $slug The service.
	 *
	 * @return array<int, string> Extensions.
	 */
	public function holdersOf($slug)
	{
		return $this->services->holdersOf((string) $slug);
	}

	/**
	 * The services some users are assigned themselves.
	 *
	 * @param array<int, string> $extensions The users.
	 *
	 * @return array<int, string> Slugs.
	 */
	public function assignedTo(array $extensions)
	{
		return $this->services->assignedTo($extensions);
	}

	/**
	 * One job, with its counts: Jobs::jobRow().
	 *
	 * @param mixed $id Job id.
	 *
	 * @return array<string, mixed>|null The row, or null when there is none.
	 */
	public function jobRow($id)
	{
		return $this->jobs->jobRow($id);
	}

	/**
	 * The newest jobs in a scope, for a navigator level: Jobs::jobChoices().
	 *
	 * @param array<string, array<int, string>>|null $narrow Navigator::scope()'s `jobs`.
	 * @param int                                    $limit  At most this many.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function jobChoices($narrow, $limit)
	{
		return $this->jobs->jobChoices($narrow, $limit);
	}

	/**
	 * What each job state, reason and source is called.
	 *
	 * @return array<string, array<string, string>> Jobs::labels().
	 */
	public function jobLabels()
	{
		return Jobs::labels();
	}

	/**
	 * A job's title: what changed for whom.
	 *
	 * @param array<string, mixed> $job A jobs row.
	 *
	 * @return string The title.
	 */
	public function jobTitle(array $job)
	{
		return Jobs::title($job);
	}

	/**
	 * The Services list's filters, as a request has them: Services::filters().
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed> The filters.
	 */
	public function serviceFilters(array $request)
	{
		return Services::filters($request);
	}

	/**
	 * The Jobs list's filters, as a request has them: Jobs::filters().
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed> The filters.
	 */
	public function jobFilters(array $request)
	{
		return Jobs::filters($request);
	}
}
