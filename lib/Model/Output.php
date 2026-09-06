<?php

namespace Oryk\Provisioner\Model;

use Oryk\Provisioner\Support\Str;

/**
 * One file produced by a template.
 *
 * The filename is itself a template ({{device.mac}}.cfg) so vendor specific
 * naming rules never have to be hardcoded in the engine.
 */
class Output
{
	/** Content types offered by the admin interface. */
	const CONTENT_TYPES = array(
		'application/json' => 'JSON (application/json)',
		'application/xml'  => 'XML (application/xml)',
		'text/xml'         => 'XML (text/xml)',
		'text/plain'       => 'Plain text / CFG / INI (text/plain)',
		'text/html'        => 'HTML (text/html)',
		'application/octet-stream' => 'Binary (application/octet-stream)',
	);

	/** @var int */
	public $id = 0;

	/** @var int */
	public $templateId = 0;

	/** @var string Template expression producing the filename. */
	public $filename = '';

	/** @var string */
	public $contentType = 'text/plain';

	/** @var string Template body. */
	public $body = '';

	/** @var int */
	public $sortOrder = 0;

	/**
	 * Build from a database row.
	 *
	 * @param array $row
	 * @return self
	 */
	public static function fromRow(array $row)
	{
		$output = new self();
		$output->id = isset($row['id']) ? (int) $row['id'] : 0;
		$output->templateId = isset($row['template_id']) ? (int) $row['template_id'] : 0;
		$output->filename = isset($row['filename']) ? (string) $row['filename'] : '';
		$output->contentType = isset($row['content_type']) ? (string) $row['content_type'] : 'text/plain';
		$output->body = isset($row['body']) ? (string) $row['body'] : '';
		$output->sortOrder = isset($row['sort_order']) ? (int) $row['sort_order'] : 0;

		return $output;
	}

	/**
	 * Build from admin/API input or from a template export.
	 *
	 * @param array $input
	 * @return self
	 */
	public static function fromArray(array $input)
	{
		$output = new self();
		$output->id = isset($input['id']) ? (int) $input['id'] : 0;
		$output->filename = isset($input['filename']) ? trim((string) $input['filename']) : '';
		$output->contentType = isset($input['contentType'])
			? (string) $input['contentType']
			: (isset($input['content_type']) ? (string) $input['content_type'] : 'text/plain');
		$output->body = isset($input['template'])
			? (string) $input['template']
			: (isset($input['body']) ? (string) $input['body'] : '');
		$output->sortOrder = isset($input['sortOrder'])
			? (int) $input['sortOrder']
			: (isset($input['sort_order']) ? (int) $input['sort_order'] : 0);

		if ($output->contentType === '') {
			$output->contentType = 'text/plain';
		}

		return $output;
	}

	/**
	 * Export shape, matching the documented template JSON.
	 *
	 * @return array
	 */
	public function toArray()
	{
		return array(
			'id'          => $this->id,
			'filename'    => $this->filename,
			'contentType' => $this->contentType,
			'template'    => $this->body,
			'sortOrder'   => $this->sortOrder,
		);
	}

	/**
	 * @return array field => error
	 */
	public function validate()
	{
		$errors = array();

		if ($this->filename === '') {
			$errors['filename'] = 'An output filename is required.';
		} elseif (strlen($this->filename) > 190) {
			$errors['filename'] = 'Output filenames are limited to 190 characters.';
		}

		return $errors;
	}

	/**
	 * Does this output produce a static (non templated) filename?
	 */
	public function hasStaticFilename()
	{
		return !Str::contains($this->filename, '{{');
	}
}
