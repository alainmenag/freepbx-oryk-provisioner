<?php

namespace Oryk\Provisioner\Seed;

use Oryk\Provisioner\Model\Template;
use Oryk\Provisioner\Repository\TemplateRepository;
use Oryk\Provisioner\Support\Json;

/**
 * Loads the templates bundled with the module (seed/*.json).
 *
 * Seeding never overwrites a template an administrator has edited: an existing
 * slug is skipped unless $force is used, which is how "Restore bundled
 * templates" in the admin interface works.
 */
class TemplateSeeder
{
	/** @var TemplateRepository */
	private $templates;

	/** @var string */
	private $directory;

	public function __construct(TemplateRepository $templates, $directory = null)
	{
		$this->templates = $templates;
		$this->directory = $directory === null ? dirname(dirname(__DIR__)) . '/seed' : rtrim($directory, '/');
	}

	/**
	 * @param bool $force Replace bundled templates that already exist.
	 * @return array created / updated / skipped slugs
	 */
	public function seed($force = false)
	{
		$result = array('created' => array(), 'updated' => array(), 'skipped' => array());

		foreach ($this->definitions() as $definition) {
			$slug = isset($definition['slug']) ? $definition['slug'] : '';

			if ($slug === '') {
				continue;
			}

			$existing = $this->templates->findBySlug($slug);

			if ($existing !== null && !$force) {
				$result['skipped'][] = $slug;
				continue;
			}

			$template = Template::fromArray($definition);
			$template->builtin = true;

			if ($existing !== null) {
				$template->id = $existing->id;

				// Reuse the existing output rows so device references stay put.
				foreach ($template->outputs as $index => $output) {
					if (isset($existing->outputs[$index])) {
						$output->id = $existing->outputs[$index]->id;
					}

					$output->templateId = $existing->id;
				}
			}

			$this->templates->save($template);

			if ($existing === null) {
				$result['created'][] = $slug;
			} else {
				$result['updated'][] = $slug;
			}
		}

		return $result;
	}

	/**
	 * Bundled template definitions, read from seed/*.json.
	 *
	 * @return array
	 */
	public function definitions()
	{
		$definitions = array();

		if (!is_dir($this->directory)) {
			return $definitions;
		}

		$files = glob($this->directory . '/*.json');

		if ($files === false) {
			return $definitions;
		}

		sort($files);

		foreach ($files as $file) {
			$contents = file_get_contents($file);

			if ($contents === false) {
				continue;
			}

			$definition = Json::decode($contents);

			if (!empty($definition['slug'])) {
				$definitions[] = $definition;
			}
		}

		return $definitions;
	}
}
