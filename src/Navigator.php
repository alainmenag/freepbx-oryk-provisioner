<?php

// src/Navigator.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Where you are, and everywhere you can go from here.
 *
 * The module's pages are a tree -- Clients has clients under it, Profiles has
 * profiles and every profile has its files -- and this builds the path through
 * it that views/partials/navigator.php draws. One level per step, each
 * carrying what it is now and every sibling it could be instead.
 *
 * Two rules hold it together, and they are why the view needs to know nothing
 * about the module at all:
 *
 *   - a level lists its siblings, never its children;
 *   - choosing one goes to *that* level's own page, and everything under it is
 *     rebuilt from what is there.
 *
 * The second is what makes it a navigator rather than a record of where you
 * have been. Pick another profile and you land on that profile -- not on
 * whichever of its files happens to sit where the last one did, which is a
 * page you did not ask for and cannot predict.
 *
 * Growing it is adding a branch to levels(). Nothing else has to know: every
 * level is the same six things, and the view draws them all the same way.
 *
 * The sixth is 'add': where a *new* one of these is written, or null where
 * that is not a thing you can do. Creating is not navigating, so it is not one
 * of the options -- the view pins it under them, past a rule, out of the
 * filter's way. But it belongs to the level rather than to the page, because
 * only the level knows what a new one of itself costs: a resource's is the
 * profile it would hang off, and a section has none at all.
 */
class Navigator extends Service
{
	/**
	 * @var Clients
	 */
	private $clients;

	/**
	 * @var Profiles
	 */
	private $profiles;

	/**
	 * @var Resources
	 */
	private $resources;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Clients $clients, Profiles $profiles, Resources $resources)
	{
		parent::__construct($freepbx);

		$this->clients = $clients;
		$this->profiles = $profiles;
		$this->resources = $resources;
	}

	/**
	 * The levels one page is reached through.
	 *
	 * `$at` says which row is open at each level below the section: an id, or
	 * the string 'new' on a page writing a row that does not exist yet. A
	 * level nothing is open at still draws -- it is how you get to one.
	 *
	 * @param string                    $section clients|profiles|logs.
	 * @param array<string, mixed>      $at      Row open at each level below it.
	 *
	 * @return array<int, array<string, mixed>> Levels, outermost first.
	 */
	public function levels($section, array $at = [])
	{
		$section = in_array($section, ['clients', 'profiles', 'logs'], true) ? $section : 'clients';

		$levels = [$this->sectionLevel($section)];

		if ($section === 'clients') {
			$levels[] = $this->clientLevel(isset($at['client']) ? $at['client'] : null);
		}

		if ($section === 'profiles') {
			$profile = isset($at['profile']) ? $at['profile'] : null;

			$levels[] = $this->profileLevel($profile);

			// A resource hangs off a profile that has been written, so on a
			// new profile this level is absent rather than empty: the page
			// under this one does not exist yet, which is a different thing
			// from existing with nothing on it.
			if (ctype_digit((string) $profile) && (int) $profile) {
				$levels[] = $this->resourceLevel((int) $profile, isset($at['resource']) ? $at['resource'] : null);
			}
		}

		return $levels;
	}

	/**
	 * The module's own sections, which are the list page's three tabs.
	 *
	 * This level is never empty and never unchosen: every page in the module
	 * is in one of them.
	 *
	 * @param string $section Section this page is in.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function sectionLevel($section)
	{
		$sections = [
			'clients' => _('Clients'),
			'profiles' => _('Profiles'),
			'logs' => _('Logs'),
		];

		$options = [];

		foreach ($sections as $key => $label) {
			$options[] = [
				'text' => $label,
				'note' => '',
				'href' => '?display=oryk_provisioner&tab=' . $key,
				'active' => $key === $section,
			];
		}

		return [
			'key' => 'section',
			'text' => $sections[$section],
			'mono' => false,
			'prompt' => _('Select a section'),
			'search' => _('Search sections'),
			'options' => $options,
			// The module's sections are the module. There is no writing a
			// fourth one, so this level is the one that draws no add row.
			'add' => null,
		];
	}

	/**
	 * Every client, by the MAC it is and the description it is known by.
	 *
	 * Twelve hex digits are exact and unreadable; a device description is
	 * readable and not unique. The option carries both, and the filter reads
	 * across the pair, so a phone is found by whichever of the two its owner
	 * has in mind.
	 *
	 * @param mixed $at Client id open here, 'new', or null.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function clientLevel($at)
	{
		$options = [];
		$text = '';

		foreach ($this->clients->clientChoices() as $row) {
			$id = (int) $row['id'];
			$active = (string) $at === (string) $id;

			$options[] = [
				'text' => (string) $row['mac'],
				'note' => (string) (isset($row['description']) ? $row['description'] : ''),
				'href' => '?display=oryk_provisioner&client=' . $id,
				'active' => $active,
			];

			if ($active) {
				$text = (string) $row['mac'];
			}
		}

		return [
			'key' => 'client',
			'text' => $at === 'new' ? _('New client') : $text,
			'mono' => true,
			'prompt' => _('Select a client'),
			'search' => _('Search clients'),
			'options' => $options,
			'add' => [
				'text' => _('New client'),
				'href' => '?display=oryk_provisioner&client=',
				// On the page writing one, the add row is where you are --
				// so the level still has exactly one thing marked active.
				'active' => $at === 'new',
			],
		];
	}

	/**
	 * Every profile.
	 *
	 * @param mixed $at Profile id open here, 'new', or null.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function profileLevel($at)
	{
		$options = [];
		$text = '';

		foreach ($this->profiles->profileChoices() as $row) {
			$id = (int) $row['id'];
			$active = (string) $at === (string) $id;

			$options[] = [
				'text' => (string) $row['name'],
				'note' => '',
				'href' => '?display=oryk_provisioner&profile=' . $id,
				'active' => $active,
			];

			if ($active) {
				$text = (string) $row['name'];
			}
		}

		return [
			'key' => 'profile',
			'text' => $at === 'new' ? _('New profile') : $text,
			'mono' => false,
			'prompt' => _('Select a profile'),
			'search' => _('Search profiles'),
			'options' => $options,
			'add' => [
				'text' => _('New profile'),
				'href' => '?display=oryk_provisioner&profile=',
				'active' => $at === 'new',
			],
		];
	}

	/**
	 * The files one profile serves.
	 *
	 * Narrowed to the profile above it, the way everything about a resource
	 * is: an id belonging to another profile names nothing at this URL.
	 *
	 * @param int   $profileId Profile whose files these are.
	 * @param mixed $at        Resource id open here, 'new', or null.
	 *
	 * @return array<string, mixed> One level.
	 */
	private function resourceLevel($profileId, $at)
	{
		$options = [];
		$text = '';

		foreach ($this->resources->resourceChoices($profileId) as $row) {
			$id = (int) $row['id'];
			$active = (string) $at === (string) $id;

			$options[] = [
				'text' => (string) $row['name'],
				'note' => '',
				'href' => '?display=oryk_provisioner&profile=' . (int) $profileId . '&resource=' . $id,
				'active' => $active,
			];

			if ($active) {
				$text = (string) $row['name'];
			}
		}

		return [
			'key' => 'resource',
			'text' => $at === 'new' ? _('New resource') : $text,
			'mono' => true,
			'prompt' => _('Select a resource'),
			'search' => _('Search resources'),
			'options' => $options,
			// Narrowed to its profile like everything else here: a file is
			// written to the profile above it or to nothing at all.
			'add' => [
				'text' => _('New resource'),
				'href' => '?display=oryk_provisioner&profile=' . (int) $profileId . '&resource=',
				'active' => $at === 'new',
			],
		];
	}
}
