<?php

// src/ServicesLink.php

namespace FreePBX\Modules\Oryk_Provisioner;

use FreePBX\Modules\Oryk_Services\Module as ServicesModule;

/**
 * The provisioner's one way to the services: the only file outside services/
 * that names anything in it. See ARCHITECTURE.md, "The services boundary".
 *
 * Without the services every method answers as if there were none -- no
 * slugs, no rows, nothing to do -- so a caller asks available() only to
 * decide whether to show something, never to stay safe.
 */
class ServicesLink extends Service
{
	/** Where the services' classes are, by the namespace prefix they are loaded by. */
	const PREFIX = 'FreePBX\\Modules\\Oryk_Services\\';

	/** @var ServicesModule|null Null when there are no services to reach. */
	private $module;

	/**
	 * @param object $freepbx FreePBX application instance.
	 * @param bool   $enabled False to stand in for a PBX without the services: the tests.
	 */
	public function __construct($freepbx, $enabled = true)
	{
		parent::__construct($freepbx);

		$this->module = $enabled && self::load() ? new ServicesModule($freepbx) : null;
	}

	/**
	 * Register the services' class loader, once.
	 *
	 * @return bool True when the services are there to load.
	 */
	private static function load()
	{
		static $loaded = null;

		if ($loaded !== null) {
			return $loaded;
		}

		$root = dirname(__DIR__) . '/services/src/';
		$loaded = is_file($root . 'Module.php');

		if ($loaded) {
			spl_autoload_register(function ($class) use ($root) {
				if (strpos($class, self::PREFIX) !== 0) {
					return;
				}

				$file = $root . str_replace('\\', '/', substr($class, strlen(self::PREFIX))) . '.php';

				if (is_file($file)) {
					require_once $file;
				}
			});
		}

		return $loaded;
	}

	/**
	 * Whether there are services: what shows a section, a tab, a level or a table of them.
	 *
	 * @return bool True when there are.
	 */
	public function available()
	{
		return $this->module !== null;
	}

	/**
	 * The AJAX commands the services answer, none without them.
	 *
	 * @return array<int, string> Command names.
	 */
	public function commands()
	{
		return $this->module ? ServicesModule::COMMANDS : [];
	}

	/**
	 * The commands() that only read.
	 *
	 * @return array<int, string> Command names.
	 */
	public function reads()
	{
		return $this->module ? ServicesModule::READS : [];
	}

	/**
	 * Answer one of commands().
	 *
	 * @param string                    $command The command.
	 * @param array<string, mixed>      $request The request's fields.
	 * @param array<string, mixed>|null $scope   Navigator::scope(), for a list.
	 *
	 * @return array<string, mixed> AJAX response data.
	 */
	public function ajax($command, array $request, ?array $scope = null)
	{
		if (!$this->module) {
			return ['status' => false, 'message' => _('Services are not installed.')];
		}

		return $this->module->ajax((string) $command, $request, $scope);
	}

	/**
	 * Make and update the services' tables and defaults.
	 *
	 * @return array<int, string> What an operator should be told.
	 */
	public function install()
	{
		return $this->module ? $this->module->install() : [];
	}

	/**
	 * One run of the job worker.
	 *
	 * @param string|null $extension One user, or null for every user with a queue.
	 *
	 * @return array<string, int> users, done, failed, purged.
	 */
	public function runJobs($extension = null)
	{
		return $this->module ? $this->module->runJobs($extension) : ['users' => 0, 'done' => 0, 'failed' => 0, 'purged' => 0];
	}

	/**
	 * Run every handler of an event, outside any job.
	 *
	 * @param string               $event   granted|revoked.
	 * @param array<string, mixed> $payload extension and service at least.
	 *
	 * @return void
	 */
	public function dispatch($event, array $payload)
	{
		if ($this->module) {
			$this->module->dispatch((string) $event, $payload);
		}
	}

	/**
	 * Run the services' own job for an event's service.
	 *
	 * @param string               $event   granted|revoked.
	 * @param array<string, mixed> $payload See docs/hooks.md.
	 *
	 * @return void
	 */
	public function ownJob($event, array $payload)
	{
		if ($this->module) {
			$this->module->ownJob((string) $event, $payload);
		}
	}

	/**
	 * Every service a user holds.
	 *
	 * @param string $extension The user.
	 *
	 * @return array<int, string> Slugs, sorted; none without the services.
	 */
	public function userSlugs($extension)
	{
		return $this->module ? $this->module->userSlugs((string) $extension) : [];
	}

	/**
	 * Whether a user holds a service.
	 *
	 * @param string $extension The user.
	 * @param string $slug      The service.
	 *
	 * @return bool True when it does.
	 */
	public function hasService($extension, $slug)
	{
		return $this->module ? $this->module->hasService((string) $extension, (string) $slug) : false;
	}

	/**
	 * A user is about to be deleted. **Call before Core deletes it.**
	 *
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	public function userDeleting($extension)
	{
		if ($this->module) {
			$this->module->userDeleting((string) $extension);
		}
	}

	/**
	 * A user has been deleted.
	 *
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	public function userDeleted($extension)
	{
		if ($this->module) {
			$this->module->userDeleted((string) $extension);
		}
	}

	/**
	 * A user has moved to another number. **Call before the old number is reused.**
	 *
	 * @param string $old The extension it had.
	 * @param string $new The extension it has.
	 *
	 * @return void
	 */
	public function userRenumbered($old, $new)
	{
		if ($this->module) {
			$this->module->userRenumbered((string) $old, (string) $new);
		}
	}

	/**
	 * Whether deleting a user removes a calendar the services made for it.
	 *
	 * @param string $extension The user.
	 *
	 * @return bool True when it does.
	 */
	public function hasCalendar($extension)
	{
		return $this->module ? $this->module->hasCalendar((string) $extension) : false;
	}

	/**
	 * Delete every job of a user's.
	 *
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	public function clearJobs($extension)
	{
		if ($this->module) {
			$this->module->clearJobs((string) $extension);
		}
	}

	/**
	 * Every service, as one user has it.
	 *
	 * @param string $extension The user.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function userServices($extension)
	{
		return $this->module ? $this->module->userServices((string) $extension) : [];
	}

	/**
	 * Where a user's services stand while jobs run.
	 *
	 * @param string $extension The user.
	 *
	 * @return array<string, mixed> By service slug.
	 */
	public function jobStatus($extension)
	{
		return $this->module ? $this->module->jobStatus((string) $extension) : [];
	}

	/**
	 * One service, by its slug.
	 *
	 * @param string $slug The service.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	public function serviceBySlug($slug)
	{
		return $this->module ? $this->module->serviceBySlug((string) $slug) : null;
	}

	/**
	 * Every service, for a picker.
	 *
	 * @return array<int, array<string, mixed>> Rows with slug and name.
	 */
	public function serviceChoices()
	{
		return $this->module ? $this->module->serviceChoices() : [];
	}

	/**
	 * A service's parents, children and requirements, for its page.
	 *
	 * @param string $slug The service, or '' for a new one.
	 *
	 * @return array<string, mixed> See Services::related().
	 */
	public function related($slug)
	{
		return $this->module ? $this->module->related((string) $slug) : [];
	}

	/**
	 * The slugs of every pack.
	 *
	 * @return array<int, string> Slugs.
	 */
	public function packs()
	{
		return $this->module ? $this->module->packs() : [];
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
		return $this->module ? $this->module->usersOf((string) $slug) : [];
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
		return $this->module ? $this->module->holdersOf((string) $slug) : [];
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
		return $this->module ? $this->module->assignedTo($extensions) : [];
	}

	/**
	 * One job.
	 *
	 * @param mixed $id Job id.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	public function jobRow($id)
	{
		return $this->module ? $this->module->jobRow($id) : null;
	}

	/**
	 * The newest jobs in a scope.
	 *
	 * @param array<string, array<int, string>>|null $narrow Navigator::scope()'s `jobs`.
	 * @param int                                    $limit  At most this many.
	 *
	 * @return array<int, array<string, mixed>> Rows.
	 */
	public function jobChoices($narrow, $limit)
	{
		return $this->module ? $this->module->jobChoices($narrow, $limit) : [];
	}

	/**
	 * What each job state, reason and source is called.
	 *
	 * @return array<string, array<string, string>> Labels by kind.
	 */
	public function jobLabels()
	{
		return $this->module ? $this->module->jobLabels() : [];
	}

	/**
	 * A job's title.
	 *
	 * @param array<string, mixed> $job A jobs row.
	 *
	 * @return string The title.
	 */
	public function jobTitle(array $job)
	{
		return $this->module ? $this->module->jobTitle($job) : '';
	}

	/**
	 * The Services list's filters, as a request has them.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed> The filters.
	 */
	public function serviceFilters(array $request)
	{
		return $this->module ? $this->module->serviceFilters($request) : [];
	}

	/**
	 * The Jobs list's filters, as a request has them.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return array<string, mixed> The filters.
	 */
	public function jobFilters(array $request)
	{
		return $this->module ? $this->module->jobFilters($request) : [];
	}
}
