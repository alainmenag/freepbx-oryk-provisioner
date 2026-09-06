<?php

namespace Oryk\Provisioner\Repository;

use Oryk\Provisioner\Database\Schema;
use Oryk\Provisioner\Exception\ValidationException;
use Oryk\Provisioner\Model\Output;
use Oryk\Provisioner\Model\Template;
use Oryk\Provisioner\Support\Json;
use Oryk\Provisioner\Support\Str;

/**
 * Persistence for templates and their outputs.
 */
class TemplateRepository extends BaseRepository
{
	/**
	 * @param array $filters vendor, enabled, search
	 * @return Template[]
	 */
	public function all(array $filters = array())
	{
		$sql = 'SELECT t.*, (
					SELECT COUNT(*) FROM `' . Schema::TABLE_DEVICES . '` d WHERE d.template_id = t.id
				) AS device_count
				FROM `' . Schema::TABLE_TEMPLATES . '` t';

		$where = array();
		$bindings = array();

		if (isset($filters['vendor']) && $filters['vendor'] !== '') {
			$where[] = 't.vendor = :vendor';
			$bindings[':vendor'] = $filters['vendor'];
		}

		if (isset($filters['enabled']) && $filters['enabled'] !== '' && $filters['enabled'] !== null) {
			$where[] = 't.enabled = :enabled';
			$bindings[':enabled'] = $filters['enabled'] ? 1 : 0;
		}

		if (isset($filters['search']) && $filters['search'] !== '') {
			$where[] = '(t.name LIKE :search OR t.slug LIKE :search OR t.vendor LIKE :search)';
			$bindings[':search'] = '%' . $filters['search'] . '%';
		}

		if (!empty($where)) {
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}

		$sql .= ' ORDER BY t.vendor ASC, t.name ASC';

		$rows = $this->select($sql, $bindings);

		if (empty($rows)) {
			return array();
		}

		$ids = array();

		foreach ($rows as $row) {
			$ids[] = (int) $row['id'];
		}

		$outputs = $this->outputsFor($ids);
		$templates = array();

		foreach ($rows as $row) {
			$id = (int) $row['id'];
			$templates[] = Template::fromRow($row, isset($outputs[$id]) ? $outputs[$id] : array());
		}

		return $templates;
	}

	/**
	 * @return Template|null
	 */
	public function find($id)
	{
		$row = $this->selectOne(
			'SELECT * FROM `' . Schema::TABLE_TEMPLATES . '` WHERE id = :id',
			array(':id' => (int) $id)
		);

		if ($row === null) {
			return null;
		}

		$outputs = $this->outputsFor(array((int) $row['id']));

		return Template::fromRow($row, isset($outputs[(int) $row['id']]) ? $outputs[(int) $row['id']] : array());
	}

	/**
	 * @return Template|null
	 */
	public function findBySlug($slug)
	{
		$row = $this->selectOne(
			'SELECT * FROM `' . Schema::TABLE_TEMPLATES . '` WHERE slug = :slug',
			array(':slug' => (string) $slug)
		);

		if ($row === null) {
			return null;
		}

		$outputs = $this->outputsFor(array((int) $row['id']));

		return Template::fromRow($row, isset($outputs[(int) $row['id']]) ? $outputs[(int) $row['id']] : array());
	}

	/**
	 * Insert or update a template together with its outputs.
	 *
	 * @throws ValidationException
	 * @return Template The stored template, re-read from the database.
	 */
	public function save(Template $template)
	{
		$errors = $template->validate();

		if (!empty($errors)) {
			throw ValidationException::withErrors($errors);
		}

		$template->slug = $this->uniqueSlug($template->slug, $template->id);
		$now = $this->now();

		$bindings = array(
			':slug'        => $template->slug,
			':name'        => $template->name,
			':vendor'      => $template->vendor,
			':family'      => $template->family,
			':description' => $template->description,
			':defaults'    => Json::encode($template->defaults),
			':parameters'  => Json::encode($template->schema->toArray()),
			':enabled'     => $template->enabled ? 1 : 0,
			':builtin'     => $template->builtin ? 1 : 0,
			':updated_at'  => $now,
		);

		if ($template->id > 0) {
			$bindings[':id'] = $template->id;
			$this->execute(
				'UPDATE `' . Schema::TABLE_TEMPLATES . '` SET
					slug = :slug, name = :name, vendor = :vendor, family = :family,
					description = :description, defaults = :defaults, parameters = :parameters,
					enabled = :enabled, builtin = :builtin, updated_at = :updated_at
				 WHERE id = :id',
				$bindings
			);
		} else {
			$bindings[':created_at'] = $now;
			$this->execute(
				'INSERT INTO `' . Schema::TABLE_TEMPLATES . '`
					(slug, name, vendor, family, description, defaults, parameters, enabled, builtin, created_at, updated_at)
				 VALUES
					(:slug, :name, :vendor, :family, :description, :defaults, :parameters, :enabled, :builtin, :created_at, :updated_at)',
				$bindings
			);
			$template->id = (int) $this->pdo->lastInsertId();
		}

		$this->replaceOutputs($template->id, $template->outputs);

		return $this->find($template->id);
	}

	/**
	 * @param bool $force Delete even when devices still reference the template.
	 * @throws ValidationException
	 */
	public function delete($id, $force = false)
	{
		$id = (int) $id;
		$inUse = $this->deviceCount($id);

		if ($inUse > 0 && !$force) {
			throw new ValidationException(sprintf(
				'This template is still assigned to %d device(s). Reassign or delete them first.',
				$inUse
			));
		}

		$this->execute('DELETE FROM `' . Schema::TABLE_OUTPUTS . '` WHERE template_id = :id', array(':id' => $id));
		$this->execute('DELETE FROM `' . Schema::TABLE_TEMPLATES . '` WHERE id = :id', array(':id' => $id));

		return true;
	}

	/**
	 * Copy a template, including outputs and schema.
	 *
	 * @return Template
	 */
	public function duplicate($id)
	{
		$template = $this->find($id);

		if ($template === null) {
			throw new ValidationException('That template no longer exists.');
		}

		$copy = Template::fromArray($template->toExportArray());
		$copy->id = 0;
		$copy->builtin = false;
		$copy->name = $template->name . ' (copy)';
		$copy->slug = $this->uniqueSlug($template->slug . '-copy', 0);

		foreach ($copy->outputs as $output) {
			$output->id = 0;
		}

		return $this->save($copy);
	}

	/**
	 * How many devices use this template?
	 */
	public function deviceCount($templateId)
	{
		$row = $this->selectOne(
			'SELECT COUNT(*) AS total FROM `' . Schema::TABLE_DEVICES . '` WHERE template_id = :id',
			array(':id' => (int) $templateId)
		);

		return $row === null ? 0 : (int) $row['total'];
	}

	/**
	 * Distinct vendors, for filter dropdowns.
	 *
	 * @return array
	 */
	public function vendors()
	{
		$rows = $this->select('SELECT DISTINCT vendor FROM `' . Schema::TABLE_TEMPLATES . '` ORDER BY vendor ASC');
		$vendors = array();

		foreach ($rows as $row) {
			if ($row['vendor'] !== '') {
				$vendors[] = $row['vendor'];
			}
		}

		return $vendors;
	}

	/**
	 * Produce a slug that is not taken by another template.
	 */
	public function uniqueSlug($slug, $ignoreId = 0)
	{
		$slug = Str::slug($slug, 'template');
		$candidate = $slug;
		$suffix = 2;

		while ($this->slugTaken($candidate, $ignoreId)) {
			$candidate = $slug . '-' . $suffix;
			$suffix++;
		}

		return $candidate;
	}

	private function slugTaken($slug, $ignoreId)
	{
		$row = $this->selectOne(
			'SELECT id FROM `' . Schema::TABLE_TEMPLATES . '` WHERE slug = :slug AND id <> :id',
			array(':slug' => $slug, ':id' => (int) $ignoreId)
		);

		return $row !== null;
	}

	/**
	 * Replace the output set of a template in one pass.
	 *
	 * @param Output[] $outputs
	 */
	private function replaceOutputs($templateId, array $outputs)
	{
		$templateId = (int) $templateId;
		$keep = array();
		$order = 0;

		foreach ($outputs as $output) {
			$bindings = array(
				':template_id'  => $templateId,
				':filename'     => $output->filename,
				':content_type' => $output->contentType,
				':body'         => $output->body,
				':sort_order'   => $order++,
			);

			if ($output->id > 0) {
				$bindings[':id'] = $output->id;
				$this->execute(
					'UPDATE `' . Schema::TABLE_OUTPUTS . '` SET
						filename = :filename, content_type = :content_type,
						body = :body, sort_order = :sort_order, template_id = :template_id
					 WHERE id = :id',
					$bindings
				);
				$keep[] = $output->id;
				continue;
			}

			$this->execute(
				'INSERT INTO `' . Schema::TABLE_OUTPUTS . '`
					(template_id, filename, content_type, body, sort_order)
				 VALUES (:template_id, :filename, :content_type, :body, :sort_order)',
				$bindings
			);
			$output->id = (int) $this->pdo->lastInsertId();
			$keep[] = $output->id;
		}

		if (empty($keep)) {
			$this->execute(
				'DELETE FROM `' . Schema::TABLE_OUTPUTS . '` WHERE template_id = :id',
				array(':id' => $templateId)
			);

			return;
		}

		$placeholders = implode(',', array_fill(0, count($keep), '?'));
		$bindings = $keep;
		array_unshift($bindings, $templateId);

		$statement = $this->pdo->prepare(
			'DELETE FROM `' . Schema::TABLE_OUTPUTS . '` WHERE template_id = ? AND id NOT IN (' . $placeholders . ')'
		);
		$statement->execute($bindings);
	}

	/**
	 * Load outputs for a set of templates.
	 *
	 * @param int[] $templateIds
	 * @return array templateId => Output[]
	 */
	private function outputsFor(array $templateIds)
	{
		$templateIds = array_values(array_unique(array_map('intval', $templateIds)));

		if (empty($templateIds)) {
			return array();
		}

		$placeholders = implode(',', array_fill(0, count($templateIds), '?'));
		$statement = $this->pdo->prepare(
			'SELECT * FROM `' . Schema::TABLE_OUTPUTS . '`
			 WHERE template_id IN (' . $placeholders . ')
			 ORDER BY sort_order ASC, id ASC'
		);
		$statement->execute($templateIds);

		$grouped = array();

		foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
			$grouped[(int) $row['template_id']][] = Output::fromRow($row);
		}

		return $grouped;
	}
}
