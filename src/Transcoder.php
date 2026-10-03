<?php

// src/Transcoder.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * A served config rewritten into the format a request's Accept header names.
 *
 * Every format is read into one tree -- ordered arrays of name => string, tree
 * or list -- and written back out of it. ARCHITECTURE.md ("Asking for another
 * format") has the mapping and what it loses. Static because nothing here needs
 * anything.
 */
class Transcoder
{
	/** `key=value` lines. */
	const PLAIN = 'plain';

	/** A JSON object. */
	const JSON = 'json';

	/** An XML document. */
	const XML = 'xml';

	/** Largest stored file read for transcoding; firmware is far past it. */
	const MAX_BYTES = 2097152;

	/** Media type => format, the only types a request can ask to transcode to. */
	const TYPES = [
		'application/json' => self::JSON,
		'application/xml' => self::XML,
		'text/xml' => self::XML,
		'text/plain' => self::PLAIN,
	];

	/**
	 * The format an Accept header explicitly asks for.
	 *
	 * Any wildcard in the header means "anything will do", which is never a
	 * reason to transcode. Otherwise the highest-q type in TYPES wins, ties to
	 * the one written first.
	 *
	 * @param string|null $accept The Accept header as sent.
	 *
	 * @return array{format: string, type: string}|null The format and the exact
	 *                                                  media type asked for, or
	 *                                                  null to send as stored.
	 */
	public static function target($accept)
	{
		$best = null;
		$bestQ = 0.0;

		foreach (explode(',', (string) $accept) as $range) {
			$parts = explode(';', $range);
			$type = strtolower(trim(array_shift($parts)));

			if ($type === '') {
				continue;
			}

			if (strpos($type, '*') !== false) {
				return null;
			}

			$q = 1.0;

			foreach ($parts as $param) {
				$pair = explode('=', $param, 2);

				if (strtolower(trim($pair[0])) === 'q' && isset($pair[1])) {
					$q = (float) trim($pair[1]);
				}
			}

			if ($q > $bestQ && isset(self::TYPES[$type])) {
				$best = ['format' => self::TYPES[$type], 'type' => $type];
				$bestQ = $q;
			}
		}

		return $best;
	}

	/**
	 * The format a config is written in.
	 *
	 * Asked of the rendered text: a template with `{{ }}` in it is not JSON or
	 * XML until it has been filled in.
	 *
	 * @param string $text The config.
	 *
	 * @return string|null PLAIN, JSON or XML, or null when it is none of them.
	 */
	public static function detect($text)
	{
		$text = trim(self::stripBom((string) $text));

		if ($text === '') {
			return null;
		}

		if (($text[0] === '{' || $text[0] === '[') && self::fromJson($text) !== null) {
			return self::JSON;
		}

		if ($text[0] === '<') {
			return self::loadXml($text) !== null ? self::XML : null;
		}

		return self::fromPlain($text) !== null ? self::PLAIN : null;
	}

	/**
	 * A config rewritten from one format to another.
	 *
	 * @param string $text The config.
	 * @param string $from Format it is in -- see detect().
	 * @param string $to   Format wanted.
	 *
	 * @return string|null The rewritten config, or null when it cannot be.
	 */
	public static function transcode($text, $from, $to)
	{
		$text = trim(self::stripBom((string) $text));

		if ($from === $to) {
			return $text;
		}

		switch ($from) {
			case self::JSON:
				$tree = self::fromJson($text);
				break;

			case self::XML:
				$tree = self::fromXml($text);
				break;

			case self::PLAIN:
				$tree = self::fromPlain($text);
				break;

			default:
				return null;
		}

		if ($tree === null) {
			return null;
		}

		switch ($to) {
			case self::JSON:
				return self::toJson($tree);

			case self::XML:
				return self::toXml($tree);

			case self::PLAIN:
				return self::toPlain($tree);
		}

		return null;
	}

	/**
	 * A stored file's text, when it is small enough and is text at all.
	 *
	 * @param string $path Absolute path to the file.
	 *
	 * @return string|null Its contents, or null for a binary or oversized file.
	 */
	public static function readText($path)
	{
		$size = @filesize($path);

		if ($size === false || $size > self::MAX_BYTES) {
			return null;
		}

		$text = @file_get_contents($path);

		return $text === false || strpos($text, "\0") !== false ? null : $text;
	}

	/**
	 * @param string $text Text that may start with a UTF-8 byte order mark.
	 *
	 * @return string The text without it.
	 */
	private static function stripBom($text)
	{
		return strncmp($text, "\xEF\xBB\xBF", 3) === 0 ? substr($text, 3) : $text;
	}

	/**
	 * @param string $text JSON.
	 *
	 * @return array|null The tree, or null when it is not a JSON object or array.
	 */
	private static function fromJson($text)
	{
		$data = json_decode($text, true);

		return is_array($data) ? self::stringify($data) : null;
	}

	/**
	 * Every scalar in a decoded JSON value as the string XML and plain will write.
	 *
	 * @param mixed $value Decoded JSON.
	 *
	 * @return mixed The same shape, every leaf a string.
	 */
	private static function stringify($value)
	{
		if (is_array($value)) {
			return array_map([self::class, 'stringify'], $value);
		}

		if (is_bool($value)) {
			return $value ? 'true' : 'false';
		}

		return $value === null ? '' : (string) $value;
	}

	/**
	 * Parse XML without touching the network or expanding entities.
	 *
	 * @param string $text XML.
	 *
	 * @return \DOMDocument|null The document, or null when it is not well formed.
	 */
	private static function loadXml($text)
	{
		if (!class_exists('DOMDocument')) {
			return null;
		}

		$doc = new \DOMDocument();
		$errors = libxml_use_internal_errors(true);
		$ok = $doc->loadXML($text, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($errors);

		return $ok && $doc->documentElement ? $doc : null;
	}

	/**
	 * @param string $text XML.
	 *
	 * @return array|null `[root name => node]`, or null when it cannot be read.
	 *                    A DOCTYPE is refused: it is how entity expansion gets in.
	 */
	private static function fromXml($text)
	{
		$doc = self::loadXml($text);

		if ($doc === null || $doc->doctype !== null) {
			return null;
		}

		return [$doc->documentElement->nodeName => self::fromNode($doc->documentElement)];
	}

	/**
	 * One element as a tree node.
	 *
	 * Attributes are `@name`, child elements are keyed by tag (repeats become a
	 * list), text is `#text` -- or the node is just its text when it has no
	 * attributes or children.
	 *
	 * @param \DOMElement $element The element.
	 *
	 * @return array|string The node.
	 */
	private static function fromNode(\DOMElement $element)
	{
		$node = [];
		$text = '';

		foreach ($element->attributes as $attribute) {
			$node['@' . $attribute->nodeName] = $attribute->nodeValue;
		}

		foreach ($element->childNodes as $child) {
			if ($child instanceof \DOMElement) {
				$name = $child->nodeName;
				$value = self::fromNode($child);

				if (!array_key_exists($name, $node)) {
					$node[$name] = $value;
				} elseif (is_array($node[$name]) && self::isList($node[$name])) {
					$node[$name][] = $value;
				} else {
					$node[$name] = [$node[$name], $value];
				}
			} elseif ($child instanceof \DOMText) {
				$text .= $child->nodeValue;
			}
		}

		$text = trim($text);

		if (!$node) {
			return $text;
		}

		if ($text !== '') {
			$node['#text'] = $text;
		}

		return $node;
	}

	/**
	 * `key=value` lines as a flat tree.
	 *
	 * Keys are kept whole -- `account.1.enable` is one key, not three levels.
	 * Accepts `#`, `;` and `//` comments, `export KEY=value`, quoted values, and
	 * `[section]`, which prefixes the keys under it with `section.`.
	 *
	 * @param string $text The config.
	 *
	 * @return array|null The tree, or null when any line is not one of those.
	 */
	private static function fromPlain($text)
	{
		$tree = [];
		$section = '';

		foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
			$line = trim($line);

			if ($line === '' || $line[0] === '#' || $line[0] === ';' || strncmp($line, '//', 2) === 0) {
				continue;
			}

			if (preg_match('/^\[([^\]]+)\]$/', $line, $match)) {
				$section = trim($match[1]) . '.';
				continue;
			}

			if (!preg_match('/^(?:export\s+)?([^\s=]+)\s*=\s*(.*)$/', $line, $match)) {
				return null;
			}

			$value = $match[2];

			if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
				$value = substr($value, 1, -1);
			}

			$tree[$section . $match[1]] = $value;
		}

		return $tree ?: null;
	}

	/**
	 * @param array $tree The tree.
	 *
	 * @return string|null Pretty-printed JSON, an object even when empty.
	 */
	private static function toJson(array $tree)
	{
		if (!$tree) {
			return '{}';
		}

		$json = json_encode($tree, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

		return $json === false ? null : $json;
	}

	/**
	 * The tree as an XML document.
	 *
	 * A tree with one key that is not a list is the root; anything else goes
	 * inside `<config>`.
	 *
	 * @param array $tree The tree.
	 *
	 * @return string|null The document.
	 */
	private static function toXml(array $tree)
	{
		if (!class_exists('DOMDocument')) {
			return null;
		}

		$doc = new \DOMDocument('1.0', 'UTF-8');
		$doc->formatOutput = true;

		$keys = array_keys($tree);
		$single = count($tree) === 1 && is_string($keys[0]) && $keys[0][0] !== '@'
			&& !(is_array($tree[$keys[0]]) && $tree[$keys[0]] && self::isList($tree[$keys[0]]));

		if ($single) {
			self::appendNode($doc, $doc, $keys[0], $tree[$keys[0]]);
		} else {
			$root = $doc->appendChild($doc->createElement('config'));

			if (self::isList($tree)) {
				self::appendNode($doc, $root, 'item', $tree);
			} else {
				foreach ($tree as $key => $value) {
					self::appendNode($doc, $root, (string) $key, $value);
				}
			}
		}

		$xml = $doc->saveXML();

		return $xml === false ? null : rtrim($xml);
	}

	/**
	 * Append one tree node to a parent as an element -- several, for a list.
	 *
	 * @param \DOMDocument $doc    The document being built.
	 * @param \DOMNode     $parent Where the element goes.
	 * @param string       $name   The node's key.
	 * @param mixed        $value  The node.
	 *
	 * @return void
	 */
	private static function appendNode(\DOMDocument $doc, \DOMNode $parent, $name, $value)
	{
		if (is_array($value) && $value && self::isList($value)) {
			foreach ($value as $item) {
				self::appendNode($doc, $parent, $name, $item);
			}

			return;
		}

		$element = self::element($doc, $name);
		$parent->appendChild($element);

		if (!is_array($value)) {
			$element->appendChild($doc->createTextNode((string) $value));

			return;
		}

		foreach ($value as $key => $child) {
			$key = (string) $key;

			if ($key === '#text') {
				$element->appendChild($doc->createTextNode((string) $child));
			} elseif ($key !== '' && $key[0] === '@' && !is_array($child) && self::isName(substr($key, 1))) {
				$element->setAttribute(substr($key, 1), (string) $child);
			} else {
				self::appendNode($doc, $element, $key, $child);
			}
		}
	}

	/**
	 * An element named for a key; a key that is not an XML name becomes
	 * `<item key="...">`.
	 *
	 * @param \DOMDocument $doc  The document being built.
	 * @param string       $name The key.
	 *
	 * @return \DOMElement The element.
	 */
	private static function element(\DOMDocument $doc, $name)
	{
		if (self::isName($name)) {
			return $doc->createElement($name);
		}

		$element = $doc->createElement('item');
		$element->setAttribute('key', $name);

		return $element;
	}

	/**
	 * Whether a key can be written as an element or attribute name as it is.
	 * Colons are excluded so nothing is read as a namespace prefix.
	 *
	 * @param string $name The key.
	 *
	 * @return bool
	 */
	private static function isName($name)
	{
		return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9._\-]*$/', (string) $name)
			&& strncasecmp($name, 'xml', 3) !== 0;
	}

	/**
	 * The tree flattened to `key=value` lines, the path joined with dots.
	 *
	 * The `@` and `#text` markers are dropped, so this is a reading of the tree,
	 * not a lossless copy of it.
	 *
	 * @param array $tree The tree.
	 *
	 * @return string The lines.
	 */
	private static function toPlain(array $tree)
	{
		$lines = [];
		self::flatten($tree, '', $lines);

		$out = [];

		foreach ($lines as $key => $value) {
			$out[] = $key . '=' . str_replace(["\r\n", "\r", "\n"], '\n', $value);
		}

		return implode("\n", $out);
	}

	/**
	 * @param mixed                 $value  A tree node.
	 * @param string                $prefix Path to it so far.
	 * @param array<string, string> $lines  Path => value, added to.
	 *
	 * @return void
	 */
	private static function flatten($value, $prefix, array &$lines)
	{
		if (!is_array($value)) {
			if ($prefix !== '') {
				$lines[$prefix] = (string) $value;
			}

			return;
		}

		if (!$value && $prefix !== '') {
			$lines[$prefix] = '';
		}

		foreach ($value as $key => $child) {
			$key = (string) $key;

			if ($key === '#text') {
				self::flatten($child, $prefix, $lines);
				continue;
			}

			if ($key !== '' && $key[0] === '@') {
				$key = substr($key, 1);
			}

			self::flatten($child, $prefix === '' ? $key : $prefix . '.' . $key, $lines);
		}
	}

	/**
	 * @param array $value A tree node.
	 *
	 * @return bool Whether it is a list (keys 0..n-1) rather than named keys.
	 */
	private static function isList(array $value)
	{
		return array_keys($value) === range(0, count($value) - 1);
	}
}
