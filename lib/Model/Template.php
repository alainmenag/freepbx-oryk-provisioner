<?php

namespace Oryk\Provisioner\Model;

use Oryk\Provisioner\Support\Arr;
use Oryk\Provisioner\Support\Json;
use Oryk\Provisioner\Support\Str;

/**
 * A provisioning template: the description of how a device family, softphone or
 * other SIP endpoint is provisioned. One template produces one or more outputs
 * and is reusable across many devices.
 */
class Template
{
	/** @var int */
	public $id = 0;

	/** @var string */
	public $slug = '';

	/** @var string */
	public $name = '';

	/** @var string */
	public $vendor = 'generic';

	/** @var string */
	public $family = '';

	/** @var string */
	public $description = '';

	/** @var array Flat dotted defaults. */
	public $defaults = array();

	/** @var ParameterSchema */
	public $schema;

	/** @var Output[] */
	public $outputs = array();

	/** @var bool */
	public $enabled = true;

	/** @var bool Shipped with the module. */
	public $builtin = false;

	/** @var string|null */
	public $createdAt = null;

	/** @var string|null */
	public $updatedAt = null;

	/** @var int Populated by the repository for list views. */
	public $deviceCount = 0;

	public function __construct()
	{
		$this->schema = new ParameterSchema();
	}

	/**
	 * @param array    $row     Database row.
	 * @param Output[] $outputs
	 * @return self
	 */
	public static function fromRow(array $row, array $outputs = array())
	{
		$template = new self();
		$template->id = isset($row['id']) ? (int) $row['id'] : 0;
		$template->slug = isset($row['slug']) ? (string) $row['slug'] : '';
		$template->name = isset($row['name']) ? (string) $row['name'] : '';
		$template->vendor = isset($row['vendor']) ? (string) $row['vendor'] : 'generic';
		$template->family = isset($row['family']) ? (string) $row['family'] : '';
		$template->description = isset($row['description']) ? (string) $row['description'] : '';
		$template->defaults = Arr::flatten(Json::decode(isset($row['defaults']) ? $row['defaults'] : ''));
		$template->schema = ParameterSchema::fromArray(isset($row['parameters']) ? $row['parameters'] : '');
		$template->enabled = !isset($row['enabled']) || !empty($row['enabled']);
		$template->builtin = !empty($row['builtin']);
		$template->createdAt = isset($row['created_at']) ? $row['created_at'] : null;
		$template->updatedAt = isset($row['updated_at']) ? $row['updated_at'] : null;
		$template->outputs = $outputs;

		if (isset($row['device_count'])) {
			$template->deviceCount = (int) $row['device_count'];
		}

		return $template;
	}

	/**
	 * Build from admin input, the API, or an imported/seeded template file.
	 *
	 * @param array $input
	 * @return self
	 */
	public static function fromArray(array $input)
	{
		$template = new self();
		$template->id = isset($input['id']) ? (int) $input['id'] : 0;
		$template->name = isset($input['name']) ? trim((string) $input['name']) : '';
		$template->slug = isset($input['slug']) ? Str::slug($input['slug']) : '';
		$template->vendor = isset($input['vendor']) ? Str::slug($input['vendor'], 'generic') : 'generic';
		$template->family = isset($input['family']) ? trim((string) $input['family']) : '';
		$template->description = isset($input['description']) ? trim((string) $input['description']) : '';
		$template->defaults = Arr::flatten(Arr::keyValue(isset($input['defaults']) ? $input['defaults'] : array()));
		$template->schema = ParameterSchema::fromArray(isset($input['parameters']) ? $input['parameters'] : array());
		$template->enabled = !array_key_exists('enabled', $input) || !empty($input['enabled']);
		$template->builtin = !empty($input['builtin']);

		if ($template->slug === '') {
			$template->slug = Str::slug($template->name);
		}

		$outputs = isset($input['outputs']) ? $input['outputs'] : array();

		if (is_string($outputs)) {
			$outputs = Json::decode($outputs);
		}

		if (is_array($outputs)) {
			$order = 0;

			foreach ($outputs as $outputInput) {
				if (!is_array($outputInput)) {
					continue;
				}

				$output = Output::fromArray($outputInput);
				$output->sortOrder = $order++;
				$output->templateId = $template->id;
				$template->outputs[] = $output;
			}
		}

		return $template;
	}

	/**
	 * Export shape, matching the documented template JSON. Importable as-is.
	 *
	 * @return array
	 */
	public function toArray()
	{
		$outputs = array();

		foreach ($this->outputs as $output) {
			$outputs[] = $output->toArray();
		}

		return array(
			'id'          => $this->id,
			'name'        => $this->name,
			'slug'        => $this->slug,
			'vendor'      => $this->vendor,
			'family'      => $this->family,
			'description' => $this->description,
			'enabled'     => $this->enabled,
			'builtin'     => $this->builtin,
			'defaults'    => $this->defaults,
			'parameters'  => $this->schema->toArray(),
			'outputs'     => $outputs,
		);
	}

	/**
	 * Portable export: no ids, no internal flags.
	 *
	 * @return array
	 */
	public function toExportArray()
	{
		$export = $this->toArray();
		unset($export['id'], $export['builtin'], $export['enabled']);

		foreach ($export['outputs'] as $index => $output) {
			unset($export['outputs'][$index]['id'], $export['outputs'][$index]['sortOrder']);
		}

		return $export;
	}

	/**
	 * Summary used by list views.
	 *
	 * @return array
	 */
	public function toSummaryArray()
	{
		return array(
			'id'          => $this->id,
			'name'        => $this->name,
			'slug'        => $this->slug,
			'vendor'      => $this->vendor,
			'family'      => $this->family,
			'enabled'     => $this->enabled,
			'builtin'     => $this->builtin,
			'outputs'     => count($this->outputs),
			'parameters'  => count($this->schema),
			'deviceCount' => $this->deviceCount,
			'updatedAt'   => $this->updatedAt,
		);
	}

	/**
	 * @return array field => error
	 */
	public function validate()
	{
		$errors = array();

		if ($this->name === '') {
			$errors['name'] = 'A template name is required.';
		}

		if ($this->slug === '') {
			$errors['slug'] = 'A template slug is required.';
		} elseif (!preg_match('/^[a-z0-9][a-z0-9\-]{0,99}$/', $this->slug)) {
			$errors['slug'] = 'Slugs may only contain lowercase letters, numbers and dashes.';
		}

		if (empty($this->outputs)) {
			$errors['outputs'] = 'A template needs at least one output file.';
		}

		foreach ($this->outputs as $index => $output) {
			foreach ($output->validate() as $field => $message) {
				$errors['outputs.' . $index . '.' . $field] = $message;
			}
		}

		return $errors;
	}

	/**
	 * All values the template contributes before FreePBX and device data:
	 * schema declared defaults first, then explicit template defaults.
	 *
	 * @return array
	 */
	public function resolvedDefaults()
	{
		return array_merge($this->schema->defaults(), $this->defaults);
	}

	/**
	 * @param int $id
	 * @return Output|null
	 */
	public function output($id)
	{
		foreach ($this->outputs as $output) {
			if ($output->id === (int) $id) {
				return $output;
			}
		}

		return null;
	}
}
