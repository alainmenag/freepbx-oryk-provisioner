<?php

// src/AsteriskConfig.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * One Asterisk configuration file, changed without disturbing anything this
 * module did not write.
 *
 * The files under /etc/asterisk ending in `_custom` and `_custom_post` are
 * shared ground. FreePBX generates around them and never rewrites them, so
 * every module that wants a setting applied after the generated
 * configuration writes into the same file, and none of them owns it.
 *
 * Reading one with parse_ini_file() and writing it back out would be right
 * about the settings and wrong about everything else: comments, ordering,
 * spacing and every section this module has never heard of would come back
 * rearranged, or not come back at all. The next module to look at the file
 * would not recognise what it left there.
 *
 * So this does not rebuild the file. It holds the file as the lines it
 * actually contains, changes only the lines it was asked about, and leaves
 * the rest byte for byte. A value that is already right is not rewritten at
 * all, which means a save that changes nothing touches nothing.
 *
 * What it understands of the format is what those files use:
 *
 *   sections, with the flags Asterisk allows on them -- `[1001](+)` adds to
 *   a section defined elsewhere, `(!)` declares a template, `(tpl)`
 *   inherits one;
 *
 *   `key = value` lines, and the `key => value` spelling older files use;
 *
 *   `;` comments to the end of a line, and `;-- --;` comment blocks, which
 *   have to be understood rather than ignored: a section header inside one
 *   is not a section, and a key inside one is not set.
 *
 * The same section name may legitimately appear more than once -- that is
 * what `(+)` is for. Reading takes the last value, which is the one
 * Asterisk ends up applying; writing rewrites the first occurrence in place
 * and drops any later duplicate of that same key, so what the file says
 * afterwards is what Asterisk will do either way.
 *
 * Nothing here knows what a setting means. What this module pins on a
 * device is EndpointSettings; this is the file underneath it.
 */
class AsteriskConfig extends Service
{
	/**
	 * Mode a newly created file is left in, when there is no existing file
	 * to copy one from.
	 */
	const NEW_FILE_MODE = 0664;

	/**
	 * Path of the file being edited.
	 *
	 * @var string
	 */
	private $path;

	/**
	 * The file as its lines, without terminators.
	 *
	 * @var string[]
	 */
	private $lines = [];

	/**
	 * Whether the file has been read yet.
	 *
	 * @var bool
	 */
	private $loaded = false;

	/**
	 * Whether the file ended in a newline, so it can end in one again.
	 *
	 * @var bool
	 */
	private $trailingNewline = true;

	/**
	 * Whether anything has actually been changed since it was read.
	 *
	 * @var bool
	 */
	private $dirty = false;

	/**
	 * @param object $freepbx FreePBX application instance.
	 * @param string $path    Absolute path of the configuration file.
	 */
	public function __construct($freepbx, $path)
	{
		parent::__construct($freepbx);

		$this->path = (string) $path;
	}

	/**
	 * The file this instance edits.
	 *
	 * @return string Absolute path.
	 */
	public function path()
	{
		return $this->path;
	}

	/**
	 * Whether the file is there yet.
	 *
	 * A missing file is a normal state: nothing has needed to write to it.
	 *
	 * @return bool True when the file exists.
	 */
	public function exists()
	{
		return is_file($this->path);
	}

	/**
	 * Whether anything has been changed and not yet written.
	 *
	 * @return bool True when there is something to save.
	 */
	public function changed()
	{
		return $this->dirty;
	}

	/**
	 * Read the file in.
	 *
	 * A file that is not there reads as empty rather than as an error, so a
	 * first write creates it.
	 *
	 * @param bool $force Read again even if it has already been read.
	 *
	 * @return bool True when there was a file to read.
	 *
	 * @throws \RuntimeException When the file exists but cannot be read.
	 */
	public function load($force = false)
	{
		if ($this->loaded && !$force) {
			return $this->exists();
		}

		$this->lines = [];
		$this->trailingNewline = true;
		$this->dirty = false;
		$this->loaded = true;

		if (!is_file($this->path)) {
			return false;
		}

		$text = @file_get_contents($this->path);

		if ($text === false) {
			$this->loaded = false;

			throw new \RuntimeException('unable to read ' . $this->path);
		}

		if ($text === '') {
			return true;
		}

		$this->lines = explode("\n", $text);
		$this->trailingNewline = substr($text, -1) === "\n";

		// explode() on a file that ends in a newline leaves an empty last
		// element, which is the end of the file rather than a line in it
		if ($this->trailingNewline) {
			array_pop($this->lines);
		}

		return true;
	}

	/**
	 * The file as it now stands.
	 *
	 * @return string File contents.
	 */
	public function text()
	{
		$this->load();

		if (!$this->lines) {
			return '';
		}

		return implode("\n", $this->lines) . ($this->trailingNewline ? "\n" : '');
	}

	/**
	 * Every section in the file, in the order they appear.
	 *
	 * A name that appears more than once is listed once.
	 *
	 * @return string[] Section names.
	 */
	public function sections()
	{
		$names = [];

		// Collected as values rather than as keys: a section named after an
		// extension is a name made of digits, and PHP would hand it back as
		// a number, which is not what anything comparing names is holding.
		foreach ($this->blocks() as $block) {
			if (!in_array($block['name'], $names, true)) {
				$names[] = $block['name'];
			}
		}

		return $names;
	}

	/**
	 * Whether a section is in the file at all.
	 *
	 * @param string $section Section name.
	 *
	 * @return bool True when at least one block carries the name.
	 */
	public function has($section)
	{
		return in_array((string) $section, $this->sections(), true);
	}

	/**
	 * Read one setting.
	 *
	 * @param string $section Section name.
	 * @param string $key     Setting name.
	 * @param mixed  $default What to answer when it is not set.
	 *
	 * @return mixed The value, or the default.
	 */
	public function get($section, $key, $default = null)
	{
		$values = $this->values($section);

		return array_key_exists((string) $key, $values) ? $values[(string) $key] : $default;
	}

	/**
	 * Read a whole section.
	 *
	 * Blocks sharing the name are read in file order, so where a setting
	 * appears twice the answer is the one Asterisk applies last.
	 *
	 * @param string $section Section name.
	 *
	 * @return array<string, string> Settings, keyed by name.
	 */
	public function values($section)
	{
		$section = (string) $section;
		$values = [];

		foreach ($this->blocks() as $block) {
			if ($block['name'] !== $section) {
				continue;
			}

			foreach ($block['keys'] as $entry) {
				$values[$entry['key']] = $entry['value'];
			}
		}

		return $values;
	}

	/**
	 * Write settings into a section, leaving everything else alone.
	 *
	 * A setting already in the section is rewritten where it stands, keeping
	 * its indentation, its separator and any comment after it. One that is
	 * not there is added after the last setting in the section. A section
	 * that is not there is added at the end of the file, with the flags
	 * given.
	 *
	 * The flags are only used for a section being created. A section already
	 * in the file is left with the header it has, because that header may be
	 * the one another module wrote.
	 *
	 * @param string               $section Section name.
	 * @param array<string, mixed> $values  Settings to write.
	 * @param string               $flags   Header flags for a new section, such as '(+)'.
	 *
	 * @return bool True when anything actually changed.
	 *
	 * @throws \InvalidArgumentException When a name or value cannot go in the file.
	 */
	public function set($section, array $values, $flags = '')
	{
		$section = $this->assertSection($section);
		$changed = false;

		foreach ($values as $key => $value) {
			$changed = $this->put($section, $key, $value, $flags) || $changed;
		}

		return $changed;
	}

	/**
	 * Take settings out of a section, leaving the section and the rest of
	 * its settings where they are.
	 *
	 * @param string   $section Section name.
	 * @param string[] $keys    Settings to remove.
	 *
	 * @return bool True when anything was removed.
	 */
	public function remove($section, array $keys)
	{
		$section = $this->assertSection($section);
		$wanted = array_flip(array_map('strval', $keys));
		$drop = [];

		foreach ($this->blocks() as $block) {
			if ($block['name'] !== $section) {
				continue;
			}

			foreach ($block['keys'] as $entry) {
				if (isset($wanted[$entry['key']])) {
					$drop[] = $entry['line'];
				}
			}
		}

		return $this->dropLines($drop);
	}

	/**
	 * Take a whole section out of the file.
	 *
	 * Every block carrying the name goes, header and all. What is left is
	 * the file without it: the blank line a removed block was separated by
	 * is not left doubled up, and nothing else moves.
	 *
	 * @param string $section Section name.
	 *
	 * @return bool True when anything was removed.
	 */
	public function removeSection($section)
	{
		$section = $this->assertSection($section);
		$drop = [];

		foreach ($this->blocks() as $block) {
			if ($block['name'] !== $section) {
				continue;
			}

			for ($line = $block['start']; $line <= $block['end']; $line++) {
				$drop[] = $line;
			}
		}

		if (!$this->dropLines($drop)) {
			return false;
		}

		$at = min($drop);

		$this->closeGap($at);

		// Nothing follows what was removed, so the blank line that separated
		// it from what came before is now trailing whitespace
		if ($at >= count($this->lines)) {
			while ($this->lines && trim(end($this->lines)) === '') {
				array_pop($this->lines);
			}
		}

		return true;
	}

	/**
	 * Read the file, change it and write it back, with nobody else in it.
	 *
	 * This is how the file is meant to be edited. The read and the write are
	 * one step from the outside: the file is locked, read fresh, handed to
	 * the callback, and written only if the callback changed something.
	 *
	 * Two admins saving two devices at the same moment is the case this is
	 * for. Without the lock, both would read the file, and the second to
	 * finish would write back a copy that never had the first one's change
	 * in it.
	 *
	 * @param callable $mutator Given this instance; whatever it returns is returned.
	 *
	 * @return mixed Whatever the callback returned.
	 *
	 * @throws \RuntimeException When the file cannot be read or written.
	 */
	public function edit(callable $mutator)
	{
		$lock = $this->acquire();

		try {
			$this->load(true);

			$result = $mutator($this);

			if ($this->dirty) {
				$this->save();
			}
		} finally {
			$this->release($lock);
		}

		return $result;
	}

	/**
	 * Write the file out.
	 *
	 * The new contents go to a temporary file in the same directory and are
	 * renamed over the original, so Asterisk and every other module reading
	 * the file see either what was there before or the whole of what is
	 * there now, and never a half-written file. Ownership and mode are
	 * carried over from the file being replaced: this runs as the web user,
	 * and a configuration file Asterisk can no longer read is a worse
	 * outcome than a setting that did not get written.
	 *
	 * @return bool True when the file was written.
	 *
	 * @throws \RuntimeException When it could not be.
	 */
	public function save()
	{
		$this->load();

		$directory = dirname($this->path);

		if (!is_dir($directory)) {
			throw new \RuntimeException('no such directory: ' . $directory);
		}

		$temporary = @tempnam($directory, '.oryk-');

		if ($temporary === false) {
			throw new \RuntimeException('unable to write in ' . $directory);
		}

		if (@file_put_contents($temporary, $this->text()) === false) {
			@unlink($temporary);

			throw new \RuntimeException('unable to write ' . $temporary);
		}

		$this->inheritOwnership($temporary);

		if (!@rename($temporary, $this->path)) {
			@unlink($temporary);

			throw new \RuntimeException('unable to replace ' . $this->path);
		}

		$this->dirty = false;

		return true;
	}

	/**
	 * Write one setting into a section.
	 *
	 * @param string $section Section name, already checked.
	 * @param string $key     Setting name.
	 * @param mixed  $value   Value to write.
	 * @param string $flags   Header flags for a section being created.
	 *
	 * @return bool True when the file changed.
	 */
	private function put($section, $key, $value, $flags)
	{
		$key = $this->assertKey($key);
		$value = $this->assertValue($value);

		$blocks = [];

		foreach ($this->blocks() as $block) {
			if ($block['name'] === $section) {
				$blocks[] = $block;
			}
		}

		// Nothing carries the name yet, so the section is added at the end
		if (!$blocks) {
			$this->appendSection($section, $flags, $key, $value);

			return true;
		}

		$found = null;
		$duplicates = [];

		foreach ($blocks as $block) {
			foreach ($block['keys'] as $entry) {
				if ($entry['key'] !== $key) {
					continue;
				}

				if ($found === null) {
					$found = $entry;
				} else {
					$duplicates[] = $entry['line'];
				}
			}
		}

		// Not set anywhere: it goes after the last setting in the last block
		// carrying the name, or straight after that block's header when it
		// has no settings in it yet
		if ($found === null) {
			$last = end($blocks);
			$after = $last['keys'] ? end($last['keys']) : null;

			$this->insertLine(
				$after ? $after['line'] + 1 : $last['start'] + 1,
				$this->compose(
					$after ? $after['indent'] : '',
					$key,
					$after ? $after['separator'] : '=',
					$value,
					'',
					$after ? $after['ending'] : ''
				)
			);

			return true;
		}

		$changed = $this->dropLines($duplicates);

		if ($found['value'] === $value && !$changed) {
			return false; // already says exactly this
		}

		// dropLines() only ever removes lines after this one, since the
		// first occurrence is the one being kept, so the line still stands
		// where it did
		$this->lines[$found['line']] = $this->compose(
			$found['indent'],
			$key,
			$found['separator'],
			$value,
			$found['comment'],
			$found['ending']
		);

		$this->dirty = true;

		return true;
	}

	/**
	 * Add a section to the end of the file, with one setting in it.
	 *
	 * @param string $section Section name.
	 * @param string $flags   Header flags, such as '(+)'.
	 * @param string $key     Setting name.
	 * @param string $value   Value.
	 *
	 * @return void
	 */
	private function appendSection($section, $flags, $key, $value)
	{
		$flags = trim((string) $flags);

		if ($flags !== '' && strpos($flags, '(') !== 0) {
			$flags = '(' . $flags . ')';
		}

		if ($this->lines && trim(end($this->lines)) !== '') {
			$this->lines[] = '';
		}

		$this->lines[] = '[' . $section . ']' . $flags;
		$this->lines[] = $this->compose('', $key, '=', $value, '', '');

		$this->trailingNewline = true;
		$this->dirty = true;
	}

	/**
	 * Put a line together the way the lines around it are written.
	 *
	 * @param string $indent    Leading whitespace to keep.
	 * @param string $key       Setting name.
	 * @param string $separator Either '=' or '=>'.
	 * @param string $value     Value.
	 * @param string $comment   Anything that followed it on the line.
	 * @param string $ending    The line's own ending, when it has one of its own.
	 *
	 * @return string The line.
	 */
	private function compose($indent, $key, $separator, $value, $comment, $ending = '')
	{
		$line = $indent . $key . $separator . $value;

		if ($comment !== '' && $comment !== null) {
			$line .= ' ' . ltrim($comment);
		}

		return $line . $ending;
	}

	/**
	 * Insert a line, keeping everything after it where it was.
	 *
	 * @param int    $at   Index to insert at.
	 * @param string $line The line.
	 *
	 * @return void
	 */
	private function insertLine($at, $line)
	{
		array_splice($this->lines, $at, 0, [$line]);

		$this->trailingNewline = true;
		$this->dirty = true;
	}

	/**
	 * Take lines out by index.
	 *
	 * @param int[] $indexes Line indexes to remove.
	 *
	 * @return bool True when anything was removed.
	 */
	private function dropLines(array $indexes)
	{
		if (!$indexes) {
			return false;
		}

		// Highest first, so removing one does not move the next
		$indexes = array_unique($indexes);
		rsort($indexes);

		foreach ($indexes as $index) {
			array_splice($this->lines, $index, 1);
		}

		$this->dirty = true;

		return true;
	}

	/**
	 * Close up the blank lines a removed section left facing each other.
	 *
	 * @param int $at Index the section used to start at.
	 *
	 * @return void
	 */
	private function closeGap($at)
	{
		while ($at > 0
			&& isset($this->lines[$at])
			&& trim($this->lines[$at - 1]) === ''
			&& trim($this->lines[$at]) === ''
		) {
			array_splice($this->lines, $at, 1);
		}
	}

	/**
	 * The file as sections, worked out from the lines it currently has.
	 *
	 * Re-read on every call rather than kept, because every change moves the
	 * line numbers underneath it and an index that has quietly gone stale is
	 * the way this kind of class corrupts a file.
	 *
	 * @return array<int, array<string, mixed>> Blocks, in file order.
	 */
	private function blocks()
	{
		$this->load();

		$blocks = [];
		$open = null;
		$commented = false;

		foreach ($this->lines as $index => $line) {
			list($code, $commented) = $this->uncomment($line, $commented);

			$bare = trim($code);

			if ($bare !== '' && preg_match('/^\[([^\[\]]+)\]\s*(\([^)]*\))?$/', $bare, $match)) {
				if ($open !== null) {
					$blocks[] = $open;
				}

				$open = [
					'name' => trim($match[1]),
					'flags' => isset($match[2]) ? $match[2] : '',
					'start' => $index,
					'end' => $index,
					'keys' => [],
				];

				continue;
			}

			if ($open === null) {
				continue; // anything before the first header belongs to nobody
			}

			$open['end'] = $index;

			if ($bare === '' || !preg_match('/^([^=\s]+)\s*(=>|=)\s*(.*)$/', $bare, $match)) {
				continue;
			}

			// The comment is kept only when it is a plain tail on the line.
			// A line with a `;-- --;` block in the middle of it is rewritten
			// whole rather than half-preserved.
			$comment = strpos($line, $code) === 0 ? substr($line, strlen($code)) : '';

			$open['keys'][] = [
				'line' => $index,
				'key' => $match[1],
				'separator' => $match[2],
				'value' => rtrim($match[3]),
				'indent' => substr($line, 0, strlen($line) - strlen(ltrim($line))),
				'comment' => rtrim($comment, "\r"),
				// A file written on Windows, or fetched and put back by an
				// editor over SFTP, ends its lines with a carriage return.
				// A rewritten line keeps the ending the line had, so one
				// changed setting does not leave the file in two minds.
				'ending' => substr($line, -1) === "\r" ? "\r" : '',
			];
		}

		if ($open !== null) {
			$blocks[] = $open;
		}

		return $blocks;
	}

	/**
	 * The part of a line Asterisk reads as configuration.
	 *
	 * Everything from an unescaped `;` to the end of the line is a comment,
	 * except `;--`, which opens a block that runs until `--;` and may run
	 * over lines. A header or a setting inside such a block is not one, so
	 * this has to be understood rather than skipped.
	 *
	 * @param string $line      The line as it stands.
	 * @param bool   $commented Whether a comment block was open before it.
	 *
	 * @return array{0: string, 1: bool} The code on the line, and whether a
	 *                                   block is still open after it.
	 */
	private function uncomment($line, $commented)
	{
		$code = '';
		$length = strlen($line);
		$at = 0;

		while ($at < $length) {
			if ($commented) {
				$close = strpos($line, '--;', $at);

				if ($close === false) {
					break;
				}

				$commented = false;
				$at = $close + 3;

				continue;
			}

			$semicolon = strpos($line, ';', $at);

			if ($semicolon === false) {
				$code .= substr($line, $at);

				break;
			}

			// An escaped semicolon is a semicolon
			if ($semicolon > 0 && $line[$semicolon - 1] === '\\') {
				$code .= substr($line, $at, $semicolon - $at + 1);
				$at = $semicolon + 1;

				continue;
			}

			$code .= substr($line, $at, $semicolon - $at);

			if (substr($line, $semicolon, 3) === ';--') {
				$commented = true;
				$at = $semicolon + 3;

				continue;
			}

			break; // the rest of the line is a comment
		}

		return [$code, $commented];
	}

	/**
	 * Leave a written file owned and readable the way the old one was.
	 *
	 * @param string $temporary Path of the file about to be renamed into place.
	 *
	 * @return void
	 */
	private function inheritOwnership($temporary)
	{
		$from = $this->exists() ? $this->path : dirname($this->path);
		$stat = @stat($from);

		if ($stat === false) {
			@chmod($temporary, self::NEW_FILE_MODE);

			return;
		}

		// A directory's mode is not a file's, so only a file's is copied
		@chmod($temporary, $this->exists() ? ($stat['mode'] & 0777) : self::NEW_FILE_MODE);

		// These only do anything as root, and failing to change owner is not
		// a reason to lose the write
		@chgrp($temporary, $stat['gid']);
		@chown($temporary, $stat['uid']);
	}

	/**
	 * Take the lock that makes a read, a change and a write one step.
	 *
	 * The lock is its own file rather than the configuration file, because
	 * the write replaces the configuration file with a different one: a lock
	 * held on the file being replaced stops meaning anything the moment it
	 * is.
	 *
	 * A lock that cannot be taken is logged and stepped over. Losing a
	 * setting to a collision that may not happen is better than refusing to
	 * save a device on a system where the lock directory is not writable.
	 *
	 * @return resource|null The lock handle, or null when there is none.
	 */
	private function acquire()
	{
		$file = sys_get_temp_dir() . '/oryk-connect-' . md5($this->path) . '.lock';
		$handle = @fopen($file, 'c');

		if ($handle === false) {
			$this->logWarning('unable to lock ' . $this->path . ': carrying on without the lock');

			return null;
		}

		if (!@flock($handle, LOCK_EX)) {
			fclose($handle);

			$this->logWarning('unable to lock ' . $this->path . ': carrying on without the lock');

			return null;
		}

		return $handle;
	}

	/**
	 * Give the lock back.
	 *
	 * @param resource|null $handle Whatever acquire() returned.
	 *
	 * @return void
	 */
	private function release($handle)
	{
		if ($handle) {
			@flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * Check a section name is one that can be written and read back.
	 *
	 * Section names reach this from a form, so a name carrying a bracket, a
	 * comment or a newline is refused rather than written: it would come
	 * back as something else, or as another section entirely.
	 *
	 * @param mixed $section Proposed name.
	 *
	 * @return string The name.
	 *
	 * @throws \InvalidArgumentException When it cannot be used.
	 */
	private function assertSection($section)
	{
		$section = trim((string) $section);

		if ($section === '' || preg_match('/[\[\];\r\n]/', $section)) {
			throw new \InvalidArgumentException('not a usable section name: ' . $section);
		}

		return $section;
	}

	/**
	 * Check a setting name.
	 *
	 * @param mixed $key Proposed name.
	 *
	 * @return string The name.
	 *
	 * @throws \InvalidArgumentException When it cannot be used.
	 */
	private function assertKey($key)
	{
		$key = trim((string) $key);

		if ($key === '' || !preg_match('/^[A-Za-z0-9_.\-]+$/', $key)) {
			throw new \InvalidArgumentException('not a usable setting name: ' . $key);
		}

		return $key;
	}

	/**
	 * Check a value.
	 *
	 * A value carrying a newline would be read back as a second setting, or
	 * as a section, so it is refused here rather than written.
	 *
	 * @param mixed $value Proposed value.
	 *
	 * @return string The value.
	 *
	 * @throws \InvalidArgumentException When it cannot be used.
	 */
	private function assertValue($value)
	{
		if (is_bool($value)) {
			$value = $value ? 'yes' : 'no';
		}

		if ($value === null || is_scalar($value)) {
			$value = trim((string) $value);
		} else {
			throw new \InvalidArgumentException('not a usable value');
		}

		if (preg_match('/[\r\n]/', $value)) {
			throw new \InvalidArgumentException('not a usable value: ' . $value);
		}

		return $value;
	}
}
