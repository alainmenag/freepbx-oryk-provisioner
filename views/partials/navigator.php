<?php
/**
 * views/partials/navigator.php -- the trail and the related row under the section bar.
 *
 * Users, Devices, Clients, Profiles, Resources, Services, Logs, Bans, Jobs, each scoped by the row the
 * page is viewing: on a profile, Clients lists that profile's clients. What is in
 * scope is Navigator's business (see src/Navigator.php); this only draws it.
 *
 * The nine levels are drawn as two lines, by one test: whether the level has
 * a row chosen. The ones that do are the trail -- the viewed row and its
 * parents, outermost first, read like any breadcrumb -- and the ones that do
 * not are the related line under it, each a kind, how many are in scope and
 * a chevron. The viewed row's crumb is last, its parents before it in level
 * order. A page viewing nothing has no trail, and the related line is
 * the whole navigator. The last crumb closes: its x goes up to the crumb
 * before it, or to its own list when it has no parent. Only the viewed row
 * can be closed -- a parent is a fact of the row, not a filter on it.
 *
 * Every level is the same control either way: what it is now, and a
 * searchable list of what it could be instead. Uniform on purpose -- there is
 * one menu, one filter, one set of keys to learn, and a level added later
 * works the moment Navigator returns it, with nothing written here.
 *
 * An option is an ordinary link. Choosing one is a page load, so every level
 * is re-scoped by the page that answers rather than patched here, and a
 * middle-click opens it in a tab like any other link on the page.
 *
 * It is deliberately not the tab strip's business and does not touch it. A
 * level moves between *rows*; a tab moves between views of the one row.
 *
 * A menu is placed by CSS, under its toggle (`data-display="static"`), not
 * by Popper, which flips a tall menu up over the strip when it decides the
 * room below is short.
 *
 * Nothing here is a link but the x after the last crumb: a crumb and a
 * related entry are each one target, the box that opens the menu, and the
 * row you are on is the menu's active row. The list a level is drawn from
 * -- its title's href -- is not drawn; the section bar is where lists are.
 *
 * A related level with nothing to list and nothing to write is inert: dim,
 * no chevron, no menu. A chevron that opens "None" is a click wasted.
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
 *                                       Each: key, one (a row of it, singular), viewed (whether its
 *                                       row is the page's), switch, title of text and href,
 *                                       text (the chosen row, or ''), href
 *                                       (that row's page, or ''), mono, search,
 *                                       options[] of text, note, badge, href, active,
 *                                       empty (what a menu with no options
 *                                       says), count (the options the level
 *                                       lists), add of text, href,
 *                                       active (or null).
 */

$navigator = isset($navigator) && is_array($navigator) ? $navigator : [];

$e = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>

<?php
// Prefixed: this is included into every view, over whatever locals it holds.
$navTrail = [];
$navRelated = [];

foreach ($navigator as $level) {
	if ((string) $level['text'] === '') {
		$navRelated[] = $level;
	} elseif (empty($level['viewed'])) {
		$navTrail[] = $level;
	}
}

// The viewed row ends the trail, whatever order its level comes in: a
// client's profile is listed after it, but is over it.
foreach ($navigator as $level) {
	if (!empty($level['viewed']) && (string) $level['text'] !== '') {
		$navTrail[] = $level;
	}
}

/**
 * One level's menu: the filter, the options, and the add row, the same under
 * a crumb and under a related entry. The options are the level's last
 * touched few (Navigator::MENU_LIMIT); a level with more says so under the
 * filter, and typing there asks the server (`navOptions`) rather than
 * filtering what is loaded.
 *
 * @param array<string, mixed> $level  One of $navigator.
 * @param string               $mono   ' oryk-nav-mono' or ''.
 * @param string               $prompt The filter's placeholder: "Switch user"
 *                                     under a crumb, whose menu changes row,
 *                                     "Search users" under a related entry.
 */
$navMenu = function (array $level, $mono, $prompt) use ($e) {
	?>
	<ul class="dropdown-menu oryk-nav-menu" data-oryk-nav-level="<?php echo $e($level['key']); ?>" data-oryk-nav-scope="<?php echo $e($level['scope']); ?>" data-oryk-nav-section="<?php echo $e($level['section']); ?>" data-oryk-nav-mono="<?php echo $mono === '' ? '0' : '1'; ?>" data-oryk-nav-more="<?php echo !empty($level['more']) ? '1' : '0'; ?>">

		<li class="oryk-nav-filter">
			<?php // The browser's own address and contact suggestions would cover the list; `search` is a type Chrome leaves alone. ?>
			<input type="search" class="form-control input-sm" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="<?php echo $e($prompt); ?>" aria-label="<?php echo $e($prompt); ?>">
		</li>

		<?php // The menu holds the last touched few; the rest are reached by typing, which asks the server. ?>
		<li class="oryk-nav-more<?php echo !empty($level['more']) ? '' : ' hidden'; ?>"><?php echo $e(sprintf(_('%1$d of %2$d, type to search'), count($level['options']), (int) $level['total'])); ?></li>

		<?php if (!$level['options']): ?>
			<li class="oryk-nav-empty"><?php echo $e(isset($level['empty']) ? $level['empty'] : _('Nothing here yet')); ?></li>
		<?php endif; ?>

		<li class="oryk-nav-empty oryk-nav-none hidden"><?php echo $e(_('No matches')); ?></li>
		<li class="oryk-nav-empty oryk-nav-searching hidden"><?php echo $e(_('Searching...')); ?></li>

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
					<span class="oryk-nav-label<?php echo $mono; ?>"><?php echo $e($option['text']); ?><?php if ((string) ($option['badge'] ?? '') !== ''): ?> <span class="badge oryk-nav-badge"><?php echo $e($option['badge']); ?></span><?php endif; ?></span>
					<?php if ((string) $option['note'] !== ''): ?>
						<span class="oryk-nav-note"><?php echo $e($option['note']); ?></span>
					<?php endif; ?>
				</a>
			</li>
		<?php endforeach; ?>

	</ul>
	<?php
};
?>

<nav class="oryk-nav" aria-label="<?php echo $e(_('Breadcrumb')); ?>">

	<?php if ($navTrail): ?>
		<?php
		// Where closing the viewed row lands: the crumb before it, else the
		// list its own crumb is drawn from. The trail pops one level.
		$last = count($navTrail) - 1;
		$navUp = $last > 0 && !empty($navTrail[$last - 1]['href']) ? (string) $navTrail[$last - 1]['href'] : (string) ($navTrail[$last]['title']['href'] ?? '');
		?>
		<ol class="oryk-nav-trail">
			<?php foreach ($navTrail as $i => $level): ?>
				<?php
				$key = (string) $level['key'];
				$mono = !empty($level['mono']) ? ' oryk-nav-mono' : '';
				$title = isset($level['title']) && is_array($level['title']) ? $level['title'] : null;
				?>
				<li class="dropdown oryk-nav-level oryk-nav-crumb">
					<div class="oryk-nav-toggle" data-toggle="dropdown" data-display="static" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false">
						<span class="oryk-nav-kind"><?php echo $e($level['one']); ?></span>
						<span class="oryk-nav-name"><span class="oryk-nav-text<?php echo $mono; ?>" data-oryk-nav-text="<?php echo $e($key); ?>" data-oryk-nav-mono="<?php echo $mono === '' ? '0' : '1'; ?>"><?php echo $e($level['text']); ?></span></span>
						<?php echo $icon('chevron-down', 'oryk-nav-caret'); ?>
					</div>
					<?php $navMenu($level, $mono, $level['switch']); ?>
					<?php if ($i === $last && $navUp !== ''): ?>
						<a class="oryk-nav-eject" href="<?php echo $e($navUp); ?>" title="<?php echo $e(sprintf(_('Close %s'), $level['one'])); ?>"><?php echo $icon('close', 'oryk-nav-eject-icon'); ?></a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<ul class="oryk-nav-related">
		<?php if ($navTrail): ?>
			<li class="oryk-nav-related-label"><?php echo $e(_('Related')); ?></li>
		<?php endif; ?>

		<?php foreach ($navRelated as $level): ?>
			<?php
			$key = (string) $level['key'];
			$mono = !empty($level['mono']) ? ' oryk-nav-mono' : '';
			$title = isset($level['title']) && is_array($level['title']) ? $level['title'] : null;
			$titleText = ($title && isset($title['text'])) ? (string) $title['text'] : $key;
			$inert = !$level['options'] && empty($level['add']);
			$count = '<span class="badge oryk-nav-count">' . (int) $level['count'] . '</span>';
			?>

			<?php if ($key === 'log'): ?>
				<?php // The section bar's two groups, marked the same way here: Logs starts the second. ?>
				<li class="oryk-nav-gap" aria-hidden="true"></li>
			<?php endif; ?>

			<li class="dropdown oryk-nav-level oryk-nav-entry<?php echo $inert ? ' oryk-nav-inert' : ''; ?>">
				<?php if ($inert): ?>
					<div class="oryk-nav-toggle">
						<span class="oryk-nav-kind"><?php echo $e($titleText); ?></span>
						<?php echo $count; ?>
					</div>
				<?php else: ?>
					<div class="oryk-nav-toggle" data-toggle="dropdown" data-display="static" role="button" tabindex="0" aria-haspopup="true" aria-expanded="false">
						<span class="oryk-nav-kind"><?php echo $e($titleText); ?></span>
						<?php echo $count; ?>
						<?php echo $icon('chevron-down', 'oryk-nav-caret'); ?>
					</div>
					<?php $navMenu($level, $mono, $level['search']); ?>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>

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
			.toggleClass('oryk-nav-mono', crumb.data('oryk-nav-mono') === 1)
			.text(text);
	}

	// One handler per behaviour, for every level on the page and every level
	// there will ever be: they are delegated off the document and keyed on the
	// classes the markup above gives all of them.

	// Opening a level puts the cursor in its filter and clears what was typed
	// last time -- the list you are shown is the whole list, every time.
	$(document).on('shown.bs.dropdown', '.oryk-nav-level', function () {
		var menu = $(this).find('.oryk-nav-menu');

		// A menu hangs from its entry's left edge; one near the right of the
		// page would run past the viewport and widen the page, so it hangs
		// from the right edge instead. Decided when it opens, at this width.
		menu.removeClass('oryk-nav-menu-right');
		menu.toggleClass('oryk-nav-menu-right', menu[0].getBoundingClientRect().right > $(window).width() - 8);

		menu.find('.oryk-nav-filter input').val('').trigger('input').focus({ preventScroll: true });
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

	/**
	 * Draw a menu's options from what the server answered.
	 *
	 * @param jQuery menu    The .oryk-nav-menu.
	 * @param Array  options As Navigator lists them: text, note, badge, href, active.
	 */
	function orykNavDraw(menu, options) {
		var mono = menu.data('oryk-nav-mono') === 1 ? ' oryk-nav-mono' : '';

		menu.find('.oryk-nav-option').remove();

		$.each(options, function (i, option) {
			var label = $('<span class="oryk-nav-label' + mono + '"></span>').text(option.text);

			if (option.badge) {
				label.append(' ').append($('<span class="badge oryk-nav-badge"></span>').text(option.badge));
			}

			var link = $('<a></a>').attr('href', option.href).append(label);

			if (option.note) {
				link.append($('<span class="oryk-nav-note"></span>').text(option.note));
			}

			menu.append($('<li class="oryk-nav-option"></li>').toggleClass('active', !!option.active).append(link));
		});
	}

	// The filter reads the whole option -- a client's MAC and its description
	// are one row, and either is a reasonable thing to have in mind. A menu
	// holding only the last touched few asks the server for the rest; the
	// loaded rows are filtered at once meanwhile, so typing never waits.
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

		if (menu.data('oryk-nav-more') !== 1) {
			return;
		}

		// What the page loaded is the answer to an empty search, kept so
		// clearing the box never has to ask again.
		if (!menu.data('oryk-nav-first')) {
			menu.data('oryk-nav-first', menu.find('.oryk-nav-option').clone().removeClass('hidden oryk-nav-here'));
			menu.data('oryk-nav-first-more', menu.find('.oryk-nav-more').text());
		}

		clearTimeout(menu.data('oryk-nav-timer'));

		if (needle === '') {
			menu.find('.oryk-nav-option').remove();
			menu.append(menu.data('oryk-nav-first').clone());
			menu.find('.oryk-nav-more').text(menu.data('oryk-nav-first-more')).removeClass('hidden');
			menu.find('.oryk-nav-none, .oryk-nav-searching').addClass('hidden');

			return;
		}

		var asked = (menu.data('oryk-nav-asked') || 0) + 1;
		menu.data('oryk-nav-asked', asked);

		// Until the server answers, what is shown is only what was loaded,
		// and the menu says so where "No matches" would otherwise stand.
		menu.find('.oryk-nav-none').addClass('hidden');
		menu.find('.oryk-nav-searching').removeClass('hidden');

		menu.data('oryk-nav-timer', setTimeout(function () {
			$.getJSON('ajax.php', {
				module: 'oryk_provisioner',
				command: 'navOptions',
				level: menu.data('oryk-nav-level'),
				scope: menu.data('oryk-nav-scope'),
				section: menu.data('oryk-nav-section'),
				q: needle
			}).done(function (answer) {
				// A slower answer to an older search must not overwrite a newer one.
				if (menu.data('oryk-nav-asked') !== asked || !answer || !answer.status) {
					return;
				}

				menu.find('.oryk-nav-searching').addClass('hidden');
				orykNavDraw(menu, answer.options);
				menu.find('.oryk-nav-none').toggleClass('hidden', answer.options.length > 0);
				menu.find('.oryk-nav-more')
					.text(answer.more ? <?php echo json_encode(_('%1$d of %2$d, keep typing')); ?>.replace('%1$d', answer.options.length).replace('%2$d', answer.total) : '')
					.toggleClass('hidden', !answer.more);
			}).fail(function () {
				if (menu.data('oryk-nav-asked') === asked) {
					menu.find('.oryk-nav-searching').addClass('hidden');
				}
			});
		}, 200));
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
