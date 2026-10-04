<?php

// src/Icons.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The module's icons: one SVG per name in assets/icons/, drawn inline.
 *
 * Inline rather than linked, so an icon takes the colour of the text around
 * it and is drawn before the admin/assets symlink exists. Pages::view() hands
 * every view `$icon($name)` and puts orykIcon(name) on the page for what is
 * built in JavaScript; both read the same files. See ARCHITECTURE.md,
 * "Conventions that hold everywhere".
 */
final class Icons
{
	/** @var array<string, string>|null Every icon by name, read once per request. */
	private static $all = null;

	/**
	 * One icon as markup, or '' when there is no such icon.
	 *
	 * @param string $name  File name under assets/icons/, without `.svg`.
	 * @param string $class More classes for the <svg>; never from a request.
	 *
	 * @return string An <svg> element.
	 */
	public static function svg($name, $class = '')
	{
		$all = self::all();

		if (!isset($all[$name])) {
			return '';
		}

		return $class === '' ? $all[$name] : str_replace('class="oryk-icon ', 'class="oryk-icon ' . $class . ' ', $all[$name]);
	}

	/**
	 * Every icon, ready to print: classed `oryk-icon oryk-icon-<name>` and hidden from screen readers.
	 *
	 * @return array<string, string> Markup by name.
	 */
	public static function all()
	{
		if (self::$all !== null) {
			return self::$all;
		}

		self::$all = [];

		foreach (glob(dirname(__DIR__) . '/assets/icons/*.svg') ?: [] as $file) {
			$name = basename($file, '.svg');

			// The name becomes a class and a JS key, so it is held to what both take as is.
			if (!preg_match('/^[a-z0-9-]+$/', $name)) {
				continue;
			}

			self::$all[$name] = preg_replace(
				'/^<svg\b/',
				'<svg class="oryk-icon oryk-icon-' . $name . '" aria-hidden="true" focusable="false"',
				trim((string) file_get_contents($file))
			);
		}

		return self::$all;
	}

	/**
	 * The page's half: orykIcon(name), and the fill for the buttons bootstrap-table draws itself.
	 *
	 * bootstrap-table writes its own toolbar buttons as an empty
	 * `<i class="<iconsPrefix> <icon>">`. The tables name ours
	 * (`data-icons-prefix="oryk-icon"`, `data-icons='{"refresh":"oryk-icon-refresh"}'`),
	 * and every such <i> is swapped for the SVG once its table is drawn.
	 *
	 * @return string A <script>, printed before the view so its own scripts can call orykIcon().
	 */
	public static function script()
	{
		$json = json_encode(self::all(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);

		return '<script>'
			. 'var orykIcons = ' . ($json ?: '{}') . ';'
			. 'function orykIcon(name) { return orykIcons[name] || \'\'; }'
			. 'function orykFillIcons() {'
			. ' $(\'i[class*="oryk-icon-"]:empty\').each(function () {'
			. ' var name = /\boryk-icon-([a-z0-9-]+)/.exec(this.className);'
			. ' if (name && orykIcons[name[1]]) { $(this).replaceWith(orykIcons[name[1]]); }'
			. ' });'
			. '}'
			. '$(orykFillIcons);'
			. '$(document).on(\'post-header.bs.table post-body.bs.table\', orykFillIcons);'
			. '</script>';
	}
}
