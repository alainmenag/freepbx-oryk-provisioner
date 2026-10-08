<?php

// src/Jobs/FindMeFollow.php

namespace FreePBX\Modules\Oryk_Provisioner\Jobs;

/**
 * Find Me Follow: Find Me/Follow Me switched on -- made with that module's
 * own defaults where the user has none -- or off, its list and settings kept.
 */
class FindMeFollow extends Job
{
	const SERVICES = ['find-me-follow'];

	/**
	 * Switch it on, making it first if the user has none.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function granted(array $event)
	{
		$extension = (string) $event['extension'];
		$fm = $this->findmefollow();

		if (!empty($fm->get($extension))) {
			$fm->setDDial($extension, true);

			return;
		}

		// add() calls findmefollow_allusers(), from the module's functions.inc.
		$this->FreePBX->Modules->loadFunctionsInc('findmefollow');
		$fm->add($extension, ['ddial' => '']);
		self::pending();
	}

	/**
	 * Switch it off, keeping what it was set to.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function revoked(array $event)
	{
		$extension = (string) $event['extension'];
		$fm = $this->findmefollow();

		if (!empty($fm->get($extension))) {
			$fm->setDDial($extension, false);
		}
	}

	/**
	 * FreePBX's Find Me/Follow Me module, or a refusal.
	 *
	 * @return object The Findmefollow BMO.
	 */
	private function findmefollow()
	{
		$this->requireModule('findmefollow', _('Find Me/Follow Me'));
		$this->requireAstman();

		return \FreePBX::Findmefollow();
	}
}
