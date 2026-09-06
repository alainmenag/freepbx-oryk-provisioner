<?php

namespace Oryk\Provisioner\Template;

use Oryk\Provisioner\Exception\RenderException;
use Oryk\Provisioner\Support\Arr;

/**
 * Renders a parsed template against a resolved parameter context.
 *
 * The same renderer is used by the admin preview, the GraphQL API and both
 * provisioning endpoints, so a template always produces identical output no
 * matter how the configuration was requested.
 */
class Renderer
{
	/** @var Parser */
	private $parser;

	/** @var bool Throw when a referenced parameter has no value. */
	private $strict = false;

	/** @var array Keys referenced by the last render that had no value. */
	private $missing = array();

	public function __construct(?Parser $parser = null)
	{
		$this->parser = $parser === null ? new Parser() : $parser;
	}

	/**
	 * @param bool $strict
	 * @return $this
	 */
	public function setStrict($strict)
	{
		$this->strict = (bool) $strict;

		return $this;
	}

	/**
	 * Parameters referenced by the last render for which no value existed.
	 *
	 * @return array
	 */
	public function missing()
	{
		return array_values(array_unique($this->missing));
	}

	/**
	 * Render a template body.
	 *
	 * @param string $source
	 * @param array  $context     Flat dotted parameter map.
	 * @param string $contentType Drives automatic escaping.
	 * @return string
	 * @throws RenderException
	 */
	public function render($source, array $context, $contentType = 'text/plain')
	{
		$this->missing = array();
		$nodes = $this->parser->parse($source);
		$mode = Escaper::modeFor($contentType);

		return $this->renderNodes($nodes, array($context), $context, $mode);
	}

	/**
	 * Render a value that must never be escaped, such as an output filename.
	 *
	 * @return string
	 */
	public function renderRaw($source, array $context)
	{
		$this->missing = array();
		$nodes = $this->parser->parse($source);

		return $this->renderNodes($nodes, array($context), $context, Escaper::MODE_NONE);
	}

	/**
	 * @param array $nodes
	 * @param array $scopes  Stack of scopes; the last entry is the innermost.
	 * @param array $context Root context.
	 * @param string $mode
	 * @return string
	 */
	private function renderNodes(array $nodes, array $scopes, array $context, $mode)
	{
		$out = '';

		foreach ($nodes as $node) {
			switch ($node['type']) {
				case Parser::T_TEXT:
					$out .= $node['value'];
					break;

				case Parser::T_VAR:
					$value = $this->evaluate($node, $scopes, $context);
					$out .= $node['raw']
						? Escaper::stringify($value)
						: Escaper::escape($value, $mode);
					break;

				case Parser::T_IF:
					$value = $this->lookup($node['expr'], $scopes, $context);
					$branch = Filters::truthy($value) ? $node['children'] : $node['else'];
					$out .= $this->renderNodes($branch, $scopes, $context, $mode);
					break;

				case Parser::T_UNLESS:
					$value = $this->lookup($node['expr'], $scopes, $context);
					$branch = Filters::truthy($value) ? $node['else'] : $node['children'];
					$out .= $this->renderNodes($branch, $scopes, $context, $mode);
					break;

				case Parser::T_EACH:
					$out .= $this->renderEach($node, $scopes, $context, $mode);
					break;
			}
		}

		return $out;
	}

	/**
	 * Render an {{#each}} block.
	 */
	private function renderEach(array $node, array $scopes, array $context, $mode)
	{
		$list = $this->lookup($node['expr'], $scopes, $context);

		if (!is_array($list) || empty($list)) {
			return $this->renderNodes($node['else'], $scopes, $context, $mode);
		}

		$out = '';
		$index = 0;
		$total = count($list);

		foreach ($list as $key => $item) {
			$scope = array(
				'__value' => $item,
				'__meta'  => array(
					'index'  => $index,
					'number' => $index + 1,
					'key'    => $key,
					'first'  => $index === 0,
					'last'   => $index === $total - 1,
					'count'  => $total,
				),
			);

			$out .= $this->renderNodes($node['children'], array_merge($scopes, array($scope)), $context, $mode);
			$index++;
		}

		return $out;
	}

	/**
	 * Resolve a variable node: look the path up, then pipe it through filters.
	 *
	 * @return mixed
	 */
	private function evaluate(array $node, array $scopes, array $context)
	{
		$value = $this->lookup($node['expr'], $scopes, $context);

		foreach ($node['filters'] as $filter) {
			$value = Filters::apply($filter['name'], $value, $filter['args']);
		}

		return $value;
	}

	/**
	 * Look up a dotted path.
	 *
	 * Order: loop metadata (@index), the current loop item (this.x / bare key),
	 * then the root parameter context.
	 *
	 * @return mixed
	 * @throws RenderException when strict rendering is enabled.
	 */
	private function lookup($path, array $scopes, array $context)
	{
		$path = trim((string) $path);

		if ($path === '') {
			return '';
		}

		// Quoted literal.
		$first = $path[0];

		if (($first === '"' || $first === "'") && substr($path, -1) === $first) {
			return substr($path, 1, -1);
		}

		if (is_numeric($path)) {
			return $path + 0;
		}

		$scope = end($scopes);
		$hasScope = is_array($scope) && array_key_exists('__value', $scope);

		// Loop metadata: @index, @number, @first, @last, @key, @count
		if ($first === '@') {
			$meta = $hasScope ? $scope['__meta'] : array();
			$key = substr($path, 1);

			return isset($meta[$key]) ? $meta[$key] : '';
		}

		if ($hasScope) {
			$item = $scope['__value'];

			if ($path === 'this' || $path === '.') {
				return $item;
			}

			if (strncmp($path, 'this.', 5) === 0) {
				$inner = substr($path, 5);

				return is_array($item) ? Arr::get($item, $inner, '') : '';
			}

			// Bare keys resolve against the current item first.
			if (is_array($item) && Arr::has($item, $path)) {
				return Arr::get($item, $path);
			}
		}

		if (Arr::has($context, $path)) {
			return Arr::get($context, $path);
		}

		$this->missing[] = $path;

		if ($this->strict) {
			throw new RenderException(sprintf('Template referenced unknown parameter "%s".', $path));
		}

		return '';
	}
}
