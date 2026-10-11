<?php

// src/CalendarManager.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * A user's own calendar: one local calendar in FreePBX's Calendar module,
 * named after the extension, that its Find Me/Follow Me is linked to.
 *
 * **The link of record is the findmefollow row's `calendar_id`; the name is
 * for people, and for finding the calendar again when that row is gone.**
 * find() asks the row first and the name second. What makes a calendar
 * this module's is its description, which starts with MARK: remove() never
 * deletes one without it, however the row came to point at it, and nothing
 * here touches a calendar an admin picked on the Follow Me page themselves.
 *
 * Linked, Follow Me matches the calendar the other way round from FreePBX's
 * default (`calendar_match` no): it rings the list except during an event,
 * so a calendar with nothing in it yet changes nothing.
 */
class CalendarManager extends Service
{
	/** What a calendar's description starts with when it is this module's. */
	const MARK = 'Managed by Oryk Provisioner';

	/** The description written: MARK, then the user. */
	const DESCRIPTION = self::MARK . ': the calendar of user %s';

	/**
	 * The user's calendar, if any: by the Follow Me row's link, else by name.
	 *
	 * @param mixed $extension The user.
	 *
	 * @return array<string, mixed>|null The calendar as Calendar lists it, with `id`; null for none.
	 */
	public function find($extension)
	{
		$extension = (string) $extension;

		if (!$this->moduleActive('calendar')) {
			return null;
		}

		$calendars = $this->calendars();
		$row = $this->followMeRow($extension);
		$linked = $row ? (string) ($row['calendar_id'] ?? '') : '';

		if ($linked !== '' && isset($calendars[$linked])) {
			return $calendars[$linked] + ['id' => $linked];
		}

		foreach ($calendars as $id => $calendar) {
			if (trim((string) ($calendar['name'] ?? '')) === $extension && self::isOurs($calendar)) {
				return $calendar + ['id' => (string) $id];
			}
		}

		return null;
	}

	/**
	 * Whether a calendar is one this module made.
	 *
	 * @param array<string, mixed> $calendar As Calendar lists it.
	 *
	 * @return bool True when its description carries MARK.
	 */
	public static function isOurs(array $calendar)
	{
		return strpos((string) ($calendar['description'] ?? ''), self::MARK) === 0;
	}

	/**
	 * Make the user's calendar where it has none, and link Follow Me to it.
	 *
	 * Running it twice is running it once: a calendar found is kept, a Follow
	 * Me row found keeps its list and settings and gets only the calendar
	 * columns, and one missing is made with the module's defaults and Follow
	 * Me itself switched off -- whether it rings is the Find Me Follow job's.
	 *
	 * @param mixed $extension The user.
	 *
	 * @return string The calendar's id.
	 *
	 * @throws \RuntimeException When a module it needs is missing, or the calendar cannot be made.
	 */
	public function ensure($extension)
	{
		$extension = (string) $extension;
		$this->requireModules();

		$calendar = $this->find($extension);

		if ($calendar === null) {
			\FreePBX::Calendar()->getDriverByAdd('local', [
				'name' => $extension,
				'description' => sprintf(self::DESCRIPTION, $extension),
				'timezone' => $this->timezone(),
			]);

			$calendar = $this->find($extension);

			if ($calendar === null) {
				throw new \RuntimeException(sprintf(_('the calendar for %s could not be created'), $extension));
			}
		}

		$fm = \FreePBX::Findmefollow();

		if (!$this->followMeRow($extension)) {
			// add() calls findmefollow_allusers(), from the module's functions.inc.
			$this->FreePBX->Modules->loadFunctionsInc('findmefollow');
			$fm->add($extension, ['ddial' => 'CHECKED']);
		}

		$fm->addSettingsById($extension, [
			'calendar_enable' => 1,
			'calendar_id' => (string) $calendar['id'],
			'calendar_group_id' => '',
			'calendar_match' => 'no',
		]);

		return (string) $calendar['id'];
	}

	/**
	 * Unlink Follow Me from the user's calendar, and delete the calendar
	 * when it is this module's -- its events with it.
	 *
	 * @param mixed $extension The user.
	 *
	 * @return bool True when a calendar was deleted.
	 */
	public function remove($extension)
	{
		$extension = (string) $extension;

		if (!$this->moduleActive('calendar')) {
			return false;
		}

		$calendar = $this->find($extension);

		if ($this->moduleActive('findmefollow') && $this->followMeRow($extension)) {
			\FreePBX::Findmefollow()->addSettingsById($extension, ['calendar_enable' => 0, 'calendar_id' => '']);
		}

		if ($calendar === null || !self::isOurs($calendar)) {
			return false;
		}

		$calendars = \FreePBX::Calendar();
		$calendars->delCalendarByID((string) $calendar['id']);

		if (method_exists($calendars, 'delCalendarSettingByID')) {
			$calendars->delCalendarSettingByID((string) $calendar['id']);
		}

		return true;
	}

	/**
	 * Whether the user has a calendar of this module's: for Overview's Delete All.
	 *
	 * @param mixed $extension The user.
	 *
	 * @return bool True when it does.
	 */
	public function has($extension)
	{
		$calendar = $this->find($extension);

		return $calendar !== null && self::isOurs($calendar);
	}

	/**
	 * Name the calendar after a renumbered user. The link follows the
	 * Follow Me row, which the renumbering moves; only the words change.
	 *
	 * @param mixed $old The number it had.
	 * @param mixed $new The number it has.
	 *
	 * @return bool True when a calendar was renamed.
	 */
	public function rename($old, $new)
	{
		if (!$this->moduleActive('calendar')) {
			return false;
		}

		$calendar = $this->find($old);

		if ($calendar === null || !self::isOurs($calendar)) {
			return false;
		}

		try {
			\FreePBX::Calendar()->getDriverById((string) $calendar['id'])->updateCalendar([
				'name' => (string) $new,
				'description' => sprintf(self::DESCRIPTION, (string) $new),
				'timezone' => (string) ($calendar['timezone'] ?? $this->timezone()),
			]);
		} catch (\Exception $e) {
			$this->logError('the calendar of ' . $old . ' was not renamed to ' . $new . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Every calendar, by id.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function calendars()
	{
		try {
			$calendars = \FreePBX::Calendar()->listCalendars();
		} catch (\Exception $e) {
			return [];
		}

		return is_array($calendars) ? $calendars : [];
	}

	/**
	 * The user's findmefollow row, or null.
	 *
	 * @param string $extension The user.
	 *
	 * @return array<string, mixed>|null
	 */
	private function followMeRow($extension)
	{
		if (!$this->moduleActive('findmefollow')) {
			return null;
		}

		try {
			$row = \FreePBX::Findmefollow()->get($extension);
		} catch (\Exception $e) {
			return null;
		}

		return is_array($row) && $row ? $row : null;
	}

	/**
	 * The timezone a new calendar is given: the PBX's.
	 *
	 * @return string
	 */
	private function timezone()
	{
		try {
			$timezone = (string) \FreePBX::View()->getTimezone();
		} catch (\Exception $e) {
			$timezone = '';
		}

		return $timezone !== '' ? $timezone : (string) date_default_timezone_get();
	}

	/**
	 * Refuse while the Calendar or Find Me/Follow Me module is missing.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException
	 */
	private function requireModules()
	{
		foreach (['calendar' => _('Calendar'), 'findmefollow' => _('Find Me/Follow Me')] as $module => $label) {
			if (!$this->moduleActive($module)) {
				throw new \RuntimeException(sprintf(_('the %s module is not installed and enabled'), $label));
			}
		}
	}
}
