<?php

// src/Library.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The profiles the module ships, and making one of the operator's from one.
 *
 * An entry is a directory under library/ -- `<vendor>/<set>` -- holding a
 * manifest and the files it names. It is read, never served: a profile made
 * from one is a copy, and the operator's from then on. See ARCHITECTURE.md,
 * "The library".
 */
class Library extends Service
{
	/** What an entry's id may be. **The only thing that turns a request's value into a path.** */
	const ID_PATTERN = '/^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/';

	/** What a manifest may name as a file beside it: one segment, never a path. */
	const SOURCE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

	/** The file in an entry's directory that says what the entry is. */
	const MANIFEST = 'manifest.json';

	/** @var Profiles */
	private $profiles;

	/** @var Resources */
	private $resources;

	/** @var string */
	private $path;

	/**
	 * @param object      $freepbx   FreePBX application instance.
	 * @param Profiles    $profiles  The profiles table.
	 * @param Resources   $resources The resources table.
	 * @param string|null $path      Directory the entries are under; the module's library/ when null.
	 */
	public function __construct($freepbx, Profiles $profiles, Resources $resources, $path = null)
	{
		parent::__construct($freepbx);

		$this->profiles = $profiles;
		$this->resources = $resources;
		$this->path = rtrim($path ?? dirname(__DIR__) . '/library', '/');
	}

	/**
	 * Every entry that can be read, by name.
	 *
	 * @return array<string, array<string, mixed>> Entries as entry() gives them, by id.
	 */
	public function entries()
	{
		$entries = [];

		foreach (glob($this->path . '/*/*/' . self::MANIFEST) ?: [] as $manifest) {
			$id = basename(dirname($manifest, 2)) . '/' . basename(dirname($manifest));
			$entry = $this->entry($id);

			if ($entry) {
				$entries[$id] = $entry;
			}
		}

		uasort($entries, function ($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});

		return $entries;
	}

	/**
	 * One entry.
	 *
	 * All or nothing: a manifest with one resource wrong is no entry, so a
	 * profile is never made with part of what it was meant to serve.
	 *
	 * @param mixed $id `<vendor>/<set>`, as a request may send it.
	 *
	 * @return array<string, mixed>|null id, name, vendor, version, skus and
	 *                                   resources (name, type, source as an
	 *                                   absolute path or null, note); null
	 *                                   when there is none or it is malformed.
	 */
	public function entry($id)
	{
		$id = (string) $id;

		if (!preg_match(self::ID_PATTERN, $id)) {
			return null;
		}

		$dir = $this->path . '/' . $id;
		$manifest = json_decode((string) @file_get_contents($dir . '/' . self::MANIFEST), true);

		if (!is_array($manifest)) {
			return null;
		}

		$name = trim((string) ($manifest['name'] ?? ''));
		$version = (int) ($manifest['version'] ?? 0);
		$resources = [];

		foreach ((array) ($manifest['resources'] ?? []) as $declared) {
			$resource = $this->resource($dir, $declared);

			if (!$resource || isset($resources[$resource['name']])) {
				$this->logWarning('library entry ' . $id . ' has a resource that cannot be used, and is left out');

				return null;
			}

			$resources[$resource['name']] = $resource;
		}

		if ($name === '' || $version < 1 || !$resources) {
			return null;
		}

		return [
			'id' => $id,
			'name' => $name,
			'vendor' => trim((string) ($manifest['vendor'] ?? '')),
			'version' => $version,
			'skus' => array_values(array_filter(array_map('strval', (array) ($manifest['skus'] ?? [])), 'strlen')),
			'resources' => array_values($resources),
		];
	}

	/**
	 * One resource a manifest declares.
	 *
	 * A template has a source, a log has none, and a file may have either: one
	 * with none is made with nothing uploaded, for what cannot be shipped.
	 *
	 * @param string $dir      The entry's directory.
	 * @param mixed  $declared The manifest's own account of it.
	 *
	 * @return array<string, mixed>|null name, type, source, note; null when it cannot be used.
	 */
	private function resource($dir, $declared)
	{
		if (!is_array($declared)) {
			return null;
		}

		$name = trim((string) ($declared['name'] ?? ''));
		$type = (string) ($declared['type'] ?? '');
		$source = (string) ($declared['source'] ?? '');

		if ($name === '' || !in_array($type, Resources::TYPES, true)) {
			return null;
		}

		if (($type === 'template') !== ($source !== '') && $type !== 'file') {
			return null;
		}

		if ($source !== '') {
			if (!preg_match(self::SOURCE_PATTERN, $source) || $source === self::MANIFEST || !is_file($dir . '/' . $source)) {
				return null;
			}

			$source = $dir . '/' . $source;
		}

		return [
			'name' => $name,
			'type' => $type,
			'source' => $source !== '' ? $source : null,
			'note' => trim((string) ($declared['note'] ?? '')),
		];
	}

	/**
	 * Save a profile, making it from an entry when a new one names one.
	 *
	 * Everything else is Profiles::saveProfile() untouched. A profile whose
	 * resources could not all be made is deleted again, so a refusal leaves
	 * nothing behind.
	 *
	 * @param array<string, mixed> $request Submitted form values; `library` is an entry's id.
	 *
	 * @return array<string, mixed> Status, and a message when it was refused.
	 */
	public function saveProfile($request)
	{
		$wanted = trim((string) ($request['library'] ?? ''));

		if ((int) ($request['id'] ?? 0) || $wanted === '') {
			return $this->profiles->saveProfile($request);
		}

		$entry = $this->entry($wanted);

		if (!$entry) {
			return ['status' => false, 'message' => _('That is not in the library.')];
		}

		$saved = $this->profiles->saveProfile($request);

		if (empty($saved['status'])) {
			return $saved;
		}

		foreach ($entry['resources'] as $resource) {
			$refused = $this->copy((int) $saved['id'], $resource);

			if ($refused !== null) {
				$this->profiles->deleteProfile($saved['id']);

				return [
					'status' => false,
					'message' => sprintf(_('%1$s could not be made: %2$s'), $resource['name'], $refused),
				];
			}
		}

		$this->profiles->setLibrary($saved['id'], $entry['id'], $entry['version']);

		return $saved;
	}

	/**
	 * Make one of an entry's resources on a profile.
	 *
	 * @param int                  $profileId Profile just written.
	 * @param array<string, mixed> $resource  A resource from entry().
	 *
	 * @return string|null Why it could not be made, or null when it was.
	 */
	private function copy($profileId, array $resource)
	{
		$template = '';

		if ($resource['type'] === 'template') {
			$template = @file_get_contents($resource['source']);

			if ($template === false) {
				return _('its template could not be read.');
			}
		}

		$made = $this->resources->saveResource([
			'profile_id' => $profileId,
			'name' => $resource['name'],
			'type' => $resource['type'],
			'template' => $template,
		]);

		if (empty($made['status'])) {
			return (string) ($made['message'] ?? '');
		}

		if ($resource['type'] === 'file' && $resource['source'] !== null
			&& !$this->resources->storeResourceFile($made['id'], $profileId, $resource['source'])) {
			return _('its file could not be stored.');
		}

		return null;
	}

	/**
	 * What an entry says about one of its resources -- what to upload to a
	 * file it could not ship.
	 *
	 * Found by the resource's name, so a renamed resource has none.
	 *
	 * @param mixed  $id   Entry id, as a profile recorded it.
	 * @param string $name Resource name.
	 *
	 * @return string The note, or '' when there is none.
	 */
	public function note($id, $name)
	{
		foreach ($this->entry($id)['resources'] ?? [] as $resource) {
			if ($resource['name'] === (string) $name) {
				return $resource['note'];
			}
		}

		return '';
	}
}
