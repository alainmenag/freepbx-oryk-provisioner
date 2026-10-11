<?php

// src/ServicesLink.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The provisioner's one way to the services, which are a module of their own
 * (oryk_services): the only file that asks anything of it. See
 * ARCHITECTURE.md, "Services".
 *
 * It holds that module's API, `\FreePBX::Oryk_services()->api()`, while the
 * module is installed and enabled. Without it every method answers as if
 * there were no services -- no slugs, no rows, nothing done -- so a caller
 * asks available() only to decide whether to show something, never to stay safe.
 */
class ServicesLink extends Service
{
	/** The services module's pages: every link to a service, a job or a user's services starts here. */
	const PAGE = '?display=oryk_services';

	/**
	 * Its AJAX commands this module's own pages post (Overview's Services and
	 * Jobs tables): only these are passed on, so one of its names that is also
	 * one of ours -- listUsers -- stays ours.
	 */
	const ROUTED = ['listServices', 'listJobs', 'retryJob', 'deleteJob', 'setUserServices', 'userServicesImpact'];

	/** @var object|null The services module's API, or null without it. */
	private $module;

	/**
	 * @param object           $freepbx FreePBX application instance.
	 * @param object|bool|null $api     The API to use; false for none; null to ask
	 *                                  FreePBX for the module's. The tests pass one.
	 */
	public function __construct($freepbx, $api = null)
	{
		parent::__construct($freepbx);

		if ($api === null) {
			$api = $this->discover();
		}

		$this->module = is_object($api) ? $api : null;
	}

	/**
	 * The services module's API, when it is installed and enabled.
	 *
	 * @return object|null The API, or null.
	 */
	private function discover()
	{
		if (!$this->moduleActive('oryk_services')) {
			return null;
		}

		try {
			$services = \FreePBX::Oryk_services();

			return is_object($services) && method_exists($services, 'api') ? $services->api() : null;
		} catch (\Throwable $e) {
			$this->logError('the services module could not be reached: ' . $e->getMessage());

			return null;
		}
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
	 * The AJAX commands this module passes on to the services: ROUTED, as far
	 * as they answer them; none without them.
	 *
	 * @return array<int, string> Command names.
	 */
	public function commands()
	{
		return $this->module ? array_values(array_intersect(self::ROUTED, (array) constant(get_class($this->module) . '::COMMANDS'))) : [];
	}

	/**
	 * The commands() that only read.
	 *
	 * @return array<int, string> Command names.
	 */
	public function reads()
	{
		return $this->module ? array_values(array_intersect($this->commands(), (array) constant(get_class($this->module) . '::READS'))) : [];
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
			return ['status' => false, 'message' => _('Oryk Services is not installed.')];
		}

		return $this->module->ajax((string) $command, $request, $scope);
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
	 * Every service, for a picker.
	 *
	 * @return array<int, array<string, mixed>> Rows with slug and name.
	 */
	public function serviceChoices()
	{
		return $this->module ? $this->module->serviceChoices() : [];
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
}
