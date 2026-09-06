<?php

namespace Oryk\Provisioner\Template;

use Oryk\Provisioner\Exception\RenderException;

/**
 * Parses a template body into a node tree.
 *
 * Supported syntax
 *   {{ sip.username }}                  escaped for the output content type
 *   {{{ sip.username }}}                raw, never escaped
 *   {{ sip.port | default:5060 }}       filters, optionally with arguments
 *   {{# if device.mac }} ... {{ else }} ... {{/ if }}
 *   {{# unless sip.secure }} ... {{/ unless }}
 *   {{# each directory }} {{ this.name }} {{/ each }}
 *   {{! a comment }}
 *
 * Whitespace inside the braces is irrelevant, so both {{#if x}} and {{ # if x }}
 * parse identically.
 */
class Parser
{
	const T_TEXT   = 'text';
	const T_VAR    = 'var';
	const T_IF     = 'if';
	const T_UNLESS = 'unless';
	const T_EACH   = 'each';

	/** Parsed trees keyed by the md5 of the source. */
	private static $cache = array();

	/**
	 * @param string $source
	 * @return array Node tree.
	 * @throws RenderException
	 */
	public function parse($source)
	{
		$source = (string) $source;
		$key = md5($source);

		if (isset(self::$cache[$key])) {
			return self::$cache[$key];
		}

		$tokens = $this->tokenize($source);
		$index = 0;
		$block = $this->buildBlock($tokens, $index, null, '');

		if (count(self::$cache) > 100) {
			self::$cache = array();
		}

		self::$cache[$key] = $block['children'];

		return $block['children'];
	}

	/**
	 * Split the source into literal text and tag tokens.
	 *
	 * @return array
	 */
	private function tokenize($source)
	{
		$tokens = array();
		$pattern = '/\{\{\{(.*?)\}\}\}|\{\{(.*?)\}\}/s';
		$offset = 0;

		if (!preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
			return $source === ''
				? array()
				: array(array('type' => self::T_TEXT, 'value' => $source));
		}

		foreach ($matches as $match) {
			$full = $match[0][0];
			$position = $match[0][1];

			if ($position > $offset) {
				$tokens[] = array(
					'type'  => self::T_TEXT,
					'value' => substr($source, $offset, $position - $offset),
				);
			}

			$offset = $position + strlen($full);

			// Triple brace = raw output.
			if (strncmp($full, '{{{', 3) === 0) {
				$tokens[] = array('type' => 'tag', 'raw' => true, 'value' => trim($match[1][0]));
				continue;
			}

			$tokens[] = array('type' => 'tag', 'raw' => false, 'value' => trim($match[2][0]));
		}

		if ($offset < strlen($source)) {
			$tokens[] = array('type' => self::T_TEXT, 'value' => substr($source, $offset));
		}

		return $tokens;
	}

	/**
	 * Recursively consume tokens until the block opened by $openType is closed.
	 *
	 * @param array       $tokens
	 * @param int         $index    Cursor, advanced in place.
	 * @param string|null $openType Null at the root.
	 * @param string      $openExpr Only used for error messages.
	 * @return array {children, else}
	 * @throws RenderException
	 */
	private function buildBlock(array $tokens, &$index, $openType, $openExpr)
	{
		$children = array();
		$else = array();
		$inElse = false;
		$total = count($tokens);

		while ($index < $total) {
			$token = $tokens[$index];
			$index++;

			if ($token['type'] === self::T_TEXT) {
				if ($token['value'] !== '') {
					$node = array('type' => self::T_TEXT, 'value' => $token['value']);

					if ($inElse) {
						$else[] = $node;
					} else {
						$children[] = $node;
					}
				}

				continue;
			}

			$body = $token['value'];

			// Comment or empty tag.
			if ($body === '' || $body[0] === '!') {
				continue;
			}

			// Block open: #if / #unless / #each
			if ($body[0] === '#') {
				$inner = trim(substr($body, 1));
				$parts = preg_split('/\s+/', $inner, 2);
				$type = strtolower($parts[0]);
				$expression = isset($parts[1]) ? trim($parts[1]) : '';

				if (!in_array($type, array(self::T_IF, self::T_UNLESS, self::T_EACH), true)) {
					throw new RenderException(sprintf('Unknown block "%s".', $type));
				}

				if ($expression === '') {
					throw new RenderException(sprintf('Block "%s" needs a parameter.', $type));
				}

				$sub = $this->buildBlock($tokens, $index, $type, $expression);
				$node = array(
					'type'     => $type,
					'expr'     => $expression,
					'children' => $sub['children'],
					'else'     => $sub['else'],
				);

				if ($inElse) {
					$else[] = $node;
				} else {
					$children[] = $node;
				}

				continue;
			}

			// Block close: /if /unless /each
			if ($body[0] === '/') {
				$type = strtolower(trim(substr($body, 1)));

				if ($openType === null) {
					throw new RenderException(sprintf('Unexpected {{/%s}} - no block is open.', $type));
				}

				if ($type !== $openType) {
					throw new RenderException(sprintf(
						'Mismatched block: {{#%s}} closed by {{/%s}}.',
						$openType,
						$type
					));
				}

				return array('children' => $children, 'else' => $else);
			}

			// else branch
			if (strtolower($body) === 'else') {
				if ($openType === null) {
					throw new RenderException('Unexpected {{else}} - no block is open.');
				}

				$inElse = true;

				continue;
			}

			$node = $this->parseExpression($body, $token['raw']);

			if ($inElse) {
				$else[] = $node;
			} else {
				$children[] = $node;
			}
		}

		if ($openType !== null) {
			throw new RenderException(sprintf('Unclosed block {{#%s %s}}.', $openType, $openExpr));
		}

		return array('children' => $children, 'else' => $else);
	}

	/**
	 * Parse "sip.port | default:5060 | upper" into a variable node.
	 *
	 * @return array
	 */
	private function parseExpression($body, $raw)
	{
		$segments = $this->splitFilters($body);
		$path = trim(array_shift($segments));
		$filters = array();

		foreach ($segments as $segment) {
			$segment = trim($segment);

			if ($segment === '') {
				continue;
			}

			$colon = strpos($segment, ':');

			if ($colon === false) {
				$filters[] = array('name' => strtolower($segment), 'args' => array());
				continue;
			}

			$name = strtolower(trim(substr($segment, 0, $colon)));
			$arguments = $this->splitArguments(substr($segment, $colon + 1));
			$filters[] = array('name' => $name, 'args' => $arguments);
		}

		return array(
			'type'    => self::T_VAR,
			'expr'    => $path,
			'filters' => $filters,
			'raw'     => (bool) $raw,
		);
	}

	/**
	 * Split on pipes that are not inside quotes.
	 *
	 * @return array
	 */
	private function splitFilters($body)
	{
		$segments = array();
		$buffer = '';
		$quote = '';
		$length = strlen($body);

		for ($i = 0; $i < $length; $i++) {
			$char = $body[$i];

			if ($quote !== '') {
				if ($char === $quote) {
					$quote = '';
				}

				$buffer .= $char;
				continue;
			}

			if ($char === '"' || $char === "'") {
				$quote = $char;
				$buffer .= $char;
				continue;
			}

			if ($char === '|') {
				$segments[] = $buffer;
				$buffer = '';
				continue;
			}

			$buffer .= $char;
		}

		$segments[] = $buffer;

		return $segments;
	}

	/**
	 * Split filter arguments on commas outside quotes, then unquote them.
	 *
	 * @return array
	 */
	private function splitArguments($argumentString)
	{
		$arguments = array();
		$buffer = '';
		$quote = '';
		$length = strlen($argumentString);

		for ($i = 0; $i < $length; $i++) {
			$char = $argumentString[$i];

			if ($quote !== '') {
				if ($char === $quote) {
					$quote = '';
					continue;
				}

				$buffer .= $char;
				continue;
			}

			if ($char === '"' || $char === "'") {
				$quote = $char;
				continue;
			}

			if ($char === ',') {
				$arguments[] = trim($buffer);
				$buffer = '';
				continue;
			}

			$buffer .= $char;
		}

		$arguments[] = trim($buffer);

		return $arguments;
	}
}
