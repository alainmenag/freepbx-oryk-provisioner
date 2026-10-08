<?php

// src/Reactions.php

namespace FreePBX\Modules\Oryk_Provisioner;

use FreePBX\Modules\Oryk_Provisioner\Jobs\Job;

/**
 * The module's own jobs, found in src/Jobs/, and which one a step runs.
 *
 * A step for a service some job names in its SERVICES runs that job, from
 * this module's own hook (ServiceEngine::ownJob()); a service no job names -- the packs, Support,
 * Guest User, Lobby User -- is an event for other modules alone. A guest's or
 * lobby user's context is changed in Extensions, never here.
 */
class Reactions extends Service
{
	/** @var ExtensionManager */
	private $extensions;

	/** @var array<string, Job> Jobs made so far, by class. */
	private $made = [];

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, ExtensionManager $extensions)
	{
		parent::__construct($freepbx);

		$this->extensions = $extensions;
	}

	/**
	 * Every job, by the service slugs it is run for.
	 *
	 * Each file in src/Jobs/ holding a Job is one: adding a job is adding a
	 * file. Two jobs naming one slug is a mistake, and the first by file name wins.
	 *
	 * @return array<string, string> Job class names, by slug.
	 */
	public static function jobs()
	{
		static $jobs = null;

		if ($jobs !== null) {
			return $jobs;
		}

		$jobs = [];
		$files = glob(__DIR__ . '/Jobs/*.php') ?: [];
		sort($files);

		foreach ($files as $file) {
			$class = __NAMESPACE__ . '\\Jobs\\' . basename($file, '.php');

			if (!class_exists($class) || !is_subclass_of($class, Job::class) || (new \ReflectionClass($class))->isAbstract()) {
				continue;
			}

			foreach ($class::SERVICES as $slug) {
				$jobs += [(string) $slug => $class];
			}
		}

		return $jobs;
	}

	/**
	 * Whether a service has a job of the module's own.
	 *
	 * @param string $slug The service.
	 *
	 * @return bool True when handle() does something for it.
	 */
	public static function handles($slug)
	{
		return isset(self::jobs()[(string) $slug]);
	}

	/**
	 * What the module's own job does when a user gains or loses a service.
	 *
	 * @param string $slug  The service.
	 * @param string $event granted|revoked.
	 *
	 * @return string Job::effects()' phrase, or '' when it has no job or says nothing.
	 */
	public static function effect($slug, $event)
	{
		$class = self::jobs()[(string) $slug] ?? null;

		return $class ? (string) ($class::effects()[(string) $event] ?? '') : '';
	}

	/**
	 * Run the job for one step, if its service has one.
	 *
	 * @param string               $event   granted|revoked.
	 * @param array<string, mixed> $payload ServiceEngine's event: extension, service.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When it cannot be done: the step fails, and is retried.
	 */
	public function handle($event, array $payload)
	{
		$class = self::jobs()[(string) ($payload['service'] ?? '')] ?? null;

		if ($class === null) {
			return;
		}

		$job = $this->made[$class] ?? ($this->made[$class] = new $class($this->FreePBX, $this->extensions));

		if ($event === 'granted') {
			$job->granted($payload);
		} else {
			$job->revoked($payload);
		}
	}
}
