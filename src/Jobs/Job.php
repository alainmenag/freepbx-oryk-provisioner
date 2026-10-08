<?php

// src/Jobs/Job.php

namespace FreePBX\Modules\Oryk_Provisioner\Jobs;

use FreePBX\Modules\Oryk_Provisioner\ExtensionManager;
use FreePBX\Modules\Oryk_Provisioner\Service;

/**
 * One of the module's own jobs: what it does when a user gains or loses the
 * services it names. Run through this module's own hook on serviceGranted and
 * serviceRevoked, like any hooked module's handler, and at priority 100 first
 * of them -- see ARCHITECTURE.md, "Jobs".
 *
 * **A job is a file here and nothing else**: a class in src/Jobs/ extending
 * this one is found by Reactions, and its SERVICES are the slugs it is run
 * for. Throwing fails the step, which is retried; so granted() and revoked()
 * each set a state rather than flip one, and running one twice is running it
 * once. None reloads: one that changes what Apply Config writes raises it.
 */
abstract class Job extends Service
{
	/** The service slugs this job is run for. */
	const SERVICES = [];

	/** @var ExtensionManager */
	protected $extensions;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, ExtensionManager $extensions)
	{
		parent::__construct($freepbx);

		$this->extensions = $extensions;
	}

	/**
	 * A user has gained one of SERVICES.
	 *
	 * @param array<string, mixed> $event The step's event; see docs/hooks.md.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When it cannot be done.
	 */
	abstract public function granted(array $event);

	/**
	 * A user has lost one of SERVICES.
	 *
	 * @param array<string, mixed> $event The step's event; see docs/hooks.md.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When it cannot be done.
	 */
	abstract public function revoked(array $event);

	/**
	 * Refuse while a module this job needs is missing: the step fails and says so.
	 *
	 * @param string $module Rawname.
	 * @param string $label  What it is called, for the message.
	 *
	 * @return void
	 */
	protected function requireModule($module, $label)
	{
		if (!$this->moduleActive($module)) {
			throw new \RuntimeException(sprintf(_('the %s module is not installed and enabled'), $label));
		}
	}

	/**
	 * Refuse while Asterisk cannot be reached.
	 *
	 * @return void
	 */
	protected function requireAstman()
	{
		if (!$this->astmanReady()) {
			throw new \RuntimeException(_('Asterisk is not reachable'));
		}
	}

	/**
	 * Write one AMPUSER key.
	 *
	 * @param string $key   Under AMPUSER/.
	 * @param string $value What it holds.
	 *
	 * @return void
	 */
	protected function astdbPut($key, $value)
	{
		$this->requireAstman();
		$this->astman->database_put('AMPUSER', $key, $value);
	}

	/**
	 * Raise Apply Config.
	 *
	 * @return void
	 */
	protected static function pending()
	{
		if (function_exists('needreload')) {
			needreload();
		}
	}
}
