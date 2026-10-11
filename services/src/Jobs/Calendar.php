<?php

// services/src/Jobs/Calendar.php

namespace FreePBX\Modules\Oryk_Services\Jobs;

use FreePBX\Modules\Oryk_Services\CalendarManager;

/**
 * Calendar: a local calendar named after the user, with Find Me/Follow Me
 * linked to it -- made and linked when granted, unlinked and deleted when
 * revoked. All of it is CalendarManager's; this is the service's hook on it.
 */
class Calendar extends Job
{
	const SERVICES = ['calendar'];

	/** @var CalendarManager|null Made on first use. */
	private $calendars;

	/**
	 * @return array<string, string> granted, revoked: a phrase each.
	 */
	public static function effects($slug = '')
	{
		return [
			'granted' => _('a calendar named after the user is created and Find Me/Follow Me is linked to it'),
			'revoked' => _('Find Me/Follow Me is unlinked and that calendar is deleted, events included'),
		];
	}

	/**
	 * Make the calendar where there is none, and link Follow Me to it.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function granted(array $event)
	{
		$this->requireAstman();
		$this->calendars()->ensure((string) $event['extension']);
		self::pending();
	}

	/**
	 * Unlink Follow Me and delete the calendar, when it is the module's.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function revoked(array $event)
	{
		$this->requireModule('calendar', _('Calendar'));
		$this->calendars()->remove((string) $event['extension']);
		self::pending();
	}

	/**
	 * @return CalendarManager
	 */
	private function calendars()
	{
		return $this->calendars ?: ($this->calendars = new CalendarManager($this->FreePBX));
	}
}
