<?php

namespace Oryk\Provisioner\Provisioning;

/**
 * One rendered configuration file, ready to be returned to a device or shown
 * in the admin preview.
 */
class RenderedFile
{
	/** @var int Output id it came from. */
	public $outputId = 0;

	/** @var string Resolved filename (template variables already expanded). */
	public $filename = '';

	/** @var string Filename template as authored, e.g. {{device.mac}}.cfg */
	public $filenameTemplate = '';

	/** @var string */
	public $contentType = 'text/plain';

	/** @var string */
	public $content = '';

	/** @var array Parameters the template referenced but that had no value. */
	public $missing = array();

	public function __construct($filename = '', $contentType = 'text/plain', $content = '')
	{
		$this->filename = $filename;
		$this->contentType = $contentType;
		$this->content = $content;
	}

	/**
	 * @return int
	 */
	public function size()
	{
		return strlen($this->content);
	}

	/**
	 * @return array
	 */
	public function toArray()
	{
		return array(
			'outputId'         => $this->outputId,
			'filename'         => $this->filename,
			'filenameTemplate' => $this->filenameTemplate,
			'contentType'      => $this->contentType,
			'content'          => $this->content,
			'size'             => $this->size(),
			'missing'          => $this->missing,
		);
	}
}
