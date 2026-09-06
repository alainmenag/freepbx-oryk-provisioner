<?php

namespace Oryk\Provisioner\Admin;

/**
 * Minimal view renderer for the admin interface.
 *
 * Views are plain PHP files under views/. They receive the data array as local
 * variables plus $view (this renderer) so they can compose partials:
 *
 *   echo $view->render('partials/repeater', array('rows' => $rows));
 */
class View
{
	/** @var string */
	private $directory;

	/** @var array Data shared with every view. */
	private $shared = array();

	public function __construct($directory = null)
	{
		$this->directory = $directory === null
			? dirname(dirname(__DIR__)) . '/views'
			: rtrim($directory, '/');
	}

	/**
	 * Make a value available to every view.
	 *
	 * @return $this
	 */
	public function share($key, $value)
	{
		$this->shared[$key] = $value;

		return $this;
	}

	/**
	 * Render a view file and return its output.
	 *
	 * @param string $name views/<name>.php
	 * @param array  $data
	 * @return string
	 */
	public function render($name, array $data = array())
	{
		$file = $this->directory . '/' . ltrim(str_replace('..', '', $name), '/') . '.php';

		if (!is_readable($file)) {
			return '<div class="alert alert-danger">' . self::esc('Missing view: ' . $name) . '</div>';
		}

		$data = array_merge($this->shared, $data);
		$data['view'] = $this;

		extract($data, EXTR_SKIP);
		ob_start();

		try {
			include $file;
		} catch (\Exception $e) {
			ob_end_clean();

			return '<div class="alert alert-danger">' . self::esc($e->getMessage()) . '</div>';
		}

		return (string) ob_get_clean();
	}

	/**
	 * HTML escape.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function esc($value)
	{
		if (is_array($value) || is_object($value)) {
			$value = json_encode($value);
		}

		if (is_bool($value)) {
			$value = $value ? '1' : '0';
		}

		return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
	}

	/**
	 * Translate through FreePBX when it is available.
	 *
	 * @param string $text
	 * @return string
	 */
	public static function t($text)
	{
		return function_exists('_') ? _($text) : $text;
	}

	/**
	 * Render a "selected"/"checked" attribute.
	 *
	 * @return string
	 */
	public static function flag($condition, $attribute = 'selected')
	{
		return $condition ? ' ' . $attribute . '="' . $attribute . '"' : '';
	}
}
