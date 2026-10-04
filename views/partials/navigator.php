<?php
/**
 * views/partials/navigator.php -- the row of dropdowns under the section bar.
 *
 * Users, Clients, Profiles, Resources, Logs, Bans, each scoped by the row the
 * page is viewing: on a profile, Clients lists that profile's clients. What is in
 * scope is Navigator's business (see src/Navigator.php); this only draws it.
 *
 * Every level is the same control: what it is now, and a searchable list of
 * what it could be instead. Uniform on purpose -- there is one bit of markup,
 * one filter, one set of keys to learn, and a level added later works the
 * moment Navigator returns it, with nothing written here.
 *
 * An option is an ordinary link. Choosing one is a page load, so every level
 * is re-scoped by the page that answers rather than patched here, and a
 * middle-click opens it in a tab like any other link on the page.
 *
 * It is deliberately not the tab strip's business and does not touch it. A
 * level moves between *rows*; a tab moves between views of the one row.
 *
 * Over each crumb is the level's own title -- what these are, plural -- and it
 * is a link to the table of what its badge counts: `resources` over a filename
 * goes to that profile's Resources tab, `profiles` on a client to the Profiles
 * list narrowed to that client's. So a level names two places rather than one,
 * the row that is open and the list it came out of.
 * Both the word and the URL come from Navigator, because the view knows
 * nothing about the module -- it cannot pluralise `resource` into a heading in
 * a language it was not written in, and it certainly cannot know that a
 * profile's files are listed on the profile rather than on the list page.
 *
 * Included by every view, under partials/sections.php. What it needs is one
 * variable, which is the whole contract with src/Navigator.php.
 *
 * Creating is the one thing here that is not navigating, and it is drawn like
 * it: pinned under the options, past a rule, out of the filter and out of the
 * arrow keys, so the list you walk is still only places that exist. A level
 * that says you cannot write a new one here simply has no row.
 *
 * @var array<int, array<string, mixed>> $navigator Levels, outermost first.
 *                                       Each: key, title of text and href,
 *                                       text, href (the chosen row's
 *                                       page, or ''), mono, prompt, search,
 *                                       options[] of text, note, href, active,
 *                                       empty (what a menu with no options
 *                                       says), count (the title's badge, or
 *                                       null for none), add of text, href,
 *                                       active (or null).
 */

$navigator = isset($navigator) && is_array($navigator) ? $navigator : [];

$e = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>

<nav class="oryk-nav" aria-label="<?php echo $e(_('Breadcrumb')); ?>" style="margin-bottom: 25px;">
	<ol class="breadcrumb">

		<?php foreach ($navigator as $level): ?>
			<?php
			$key = (string) $level['key'];
			$mono = !empty($level['mono']) ? ' oryk-nav-mono' : '';
			$chosen = (string) $level['text'] !== '';

			// A level says what it is; whether that has a list page to point
			// at is the level's business, and one that names none draws the
			// word alone rather than a link that goes nowhere.
			$title = isset($level['title']) && is_array($level['title']) ? $level['title'] : null;
			$titleText = ($title && isset($title['text'])) ? (string) $title['text'] : '';
			$titleHref = ($title && isset($title['href'])) ? (string) $title['href'] : '';
			$count = isset($level['count']) ? '<span class="badge">' . (int) $level['count'] . '</span>' : '';
			?>
			<li class="dropdown oryk-nav-level">

				<?php if ($titleText !== '' && $titleHref !== ''): ?>
					<a class="oryk-nav-title" href="<?php echo $e($titleHref); ?>" title="<?php echo $e(sprintf(_('Go to %s'), $titleText)); ?>"><?php echo $e($titleText); ?><?php echo $count; ?></a>
				<?php elseif ($titleText !== ''): ?>
					<span class="oryk-nav-title"><?php echo $e($titleText); ?><?php echo $count; ?></span>
				<?php endif; ?>

				<?php $link = $chosen && !empty($level['href']) ? (string) $level['href'] : ''; ?>
				<div class="oryk-nav-toggle" data-toggle="dropdown" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false">
					<?php
					// One line, so the link's underline is the name and nothing either side of it.
					$crumb = '<span class="oryk-nav-text' . ($chosen ? $mono : ' oryk-nav-prompt') . '" data-oryk-nav-text="' . $e($key) . '" data-oryk-nav-mono="' . ($mono === '' ? '0' : '1') . '">'
						. $e($chosen ? $level['text'] : $level['prompt']) . '</span>';
					echo $link !== '' ? '<a class="oryk-nav-link" href="' . $e($link) . '">' . $crumb . '</a>' : $crumb;
					?>
					<?php echo $icon('chevron-down', 'oryk-nav-caret'); ?>
				</div>

				<ul class="dropdown-menu oryk-nav-menu">

					<li class="oryk-nav-filter">
						<input type="text" class="form-control input-sm" placeholder="<?php echo $e($level['search']); ?>" aria-label="<?php echo $e($level['search']); ?>">
					</li>

					<?php if (!$level['options']): ?>
						<li class="oryk-nav-empty"><?php echo $e(isset($level['empty']) ? $level['empty'] : _('Nothing here yet')); ?></li>
					<?php endif; ?>

					<li class="oryk-nav-empty oryk-nav-none hidden"><?php echo $e(_('No matches')); ?></li>

					<?php if (!empty($level['add'])): ?>
						<li class="oryk-nav-add<?php echo !empty($level['add']['active']) ? ' active' : ''; ?>">
							<a href="<?php echo $e($level['add']['href']); ?>">
								<span class="oryk-nav-plus" aria-hidden="true">+</span><span class="oryk-nav-label"
									style="display: inline;"><?php echo $e($level['add']['text']); ?></span>
							</a>
						</li>
					<?php endif; ?>

					<?php foreach ($level['options'] as $option): ?>
						<li class="oryk-nav-option<?php echo !empty($option['active']) ? ' active' : ''; ?>">
							<a href="<?php echo $e($option['href']); ?>">
								<span class="oryk-nav-label<?php echo $mono; ?>"><?php echo $e($option['text']); ?></span>
								<?php if ((string) $option['note'] !== ''): ?>
									<span class="oryk-nav-note"><?php echo $e($option['note']); ?></span>
								<?php endif; ?>
							</a>
						</li>
					<?php endforeach; ?>

				</ul>
			</li>
		<?php endforeach; ?>

	</ol>
</nav>

<script>

	/**
	 * Say what a level is now, without a page load behind it.
	 *
	 * A save that stays on the page can rename the thing the crumb names --
	 * the resource editor's file actions do -- and a crumb still saying the
	 * old name is the page contradicting itself.
	 *
	 * @param string key  Level to write: user, client, profile, resource, log, ban.
	 * @param string text What it is called now.
	 */
	function orykNavText(key, text) {
		var crumb = $('[data-oryk-nav-text="' + key + '"]');

		crumb
			.removeClass('oryk-nav-prompt')
			.toggleClass('oryk-nav-mono', crumb.data('oryk-nav-mono') === 1)
			.text(text);
	}

	// One handler per behaviour, for every level on the page and every level
	// there will ever be: they are delegated off the document and keyed on the
	// classes the markup above gives all of them.

	// Opening a level puts the cursor in its filter and clears what was typed
	// last time -- the list you are shown is the whole list, every time.
	$(document).on('shown.bs.dropdown', '.oryk-nav-level', function () {
		$(this).find('.oryk-nav-filter input').val('').trigger('input').focus();
	});

	// The chosen row's name is a link to it, inside the box that opens the
	// menu: following it must not also toggle the dropdown, whose handler
	// cancels the navigation. Bound on the link itself, not delegated --
	// Bootstrap 4 binds its toggle handler on the box once a dropdown has
	// opened, and only a handler below the box runs before that one.
	$('.oryk-nav-link').on('click', function (event) {
		event.stopPropagation();
	});

	// The box is a div, so Enter and Space are what open it from the keyboard.
	$(document).on('keydown', '.oryk-nav-toggle', function (event) {
		if (event.target === this && (event.which === 13 || event.which === 32)) {
			event.preventDefault();
			$(this).dropdown('toggle');
		}
	});

	// Clicking into the filter must not count as clicking away from the menu,
	// which is what Bootstrap would otherwise make of it.
	$(document).on('click', '.oryk-nav-filter', function (event) {
		event.stopPropagation();
	});

	// The filter reads the whole option -- a client's MAC and its description
	// are one row, and either is a reasonable thing to have in mind.
	$(document).on('input', '.oryk-nav-filter input', function () {
		var needle = $.trim($(this).val()).toLowerCase();
		var menu = $(this).closest('.oryk-nav-menu');
		var showing = 0;

		menu.find('.oryk-nav-option').each(function () {
			var match = needle === '' || $(this).text().toLowerCase().indexOf(needle) !== -1;

			$(this).toggleClass('hidden', !match).removeClass('oryk-nav-here');

			if (match) {
				showing++;
			}
		});

		menu.find('.oryk-nav-none').toggleClass('hidden', showing > 0 || !menu.find('.oryk-nav-option').length);
	});

	// Arrows walk what the filter left, Enter follows it. Typing a name and
	// pressing Enter is the whole interaction, and it never needs the mouse.
	$(document).on('keydown', '.oryk-nav-filter input', function (event) {
		var menu = $(this).closest('.oryk-nav-menu');
		var visible = menu.find('.oryk-nav-option').not('.hidden');

		if (!visible.length) {
			return;
		}

		// Enter with nothing walked to takes the first match, which is what
		// the list is showing you and what you were typing towards.
		if (event.which === 13) {
			var here = visible.filter('.oryk-nav-here');
			var href = (here.length ? here : visible.first()).find('a').attr('href');

			event.preventDefault();

			if (href) {
				window.location = href;
			}

			return;
		}

		if (event.which !== 38 && event.which !== 40) {
			return;
		}

		event.preventDefault();

		var at = visible.index(visible.filter('.oryk-nav-here'));
		at = event.which === 40 ? at + 1 : at - 1;

		// Round, rather than stop: a list you have walked off the end of is a
		// list you wanted the other end of.
		if (at < 0) {
			at = visible.length - 1;
		}

		if (at >= visible.length) {
			at = 0;
		}

		var landed = visible.removeClass('oryk-nav-here').eq(at).addClass('oryk-nav-here');

		if (landed[0] && landed[0].scrollIntoView) {
			landed[0].scrollIntoView({ block: 'nearest' });
		}
	});

</script>
