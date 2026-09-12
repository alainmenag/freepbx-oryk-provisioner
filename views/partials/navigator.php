<?php
/**
 * views/partials/navigator.php -- the breadcrumb every page is topped with.
 *
 * It replaced four hand-written section titles that could only go up. A title
 * said where you were and linked back; this says where you are and lets you
 * go anywhere at the same depth or below, which is most of the moving around
 * anybody does in a module whose whole shape is a tree.
 *
 * Every level is the same control: what it is now, and a searchable list of
 * every sibling it could be instead. Uniform on purpose -- there is one bit of
 * markup, one filter, one set of keys to learn, and a level added later works
 * the moment Navigator returns it, with nothing written here.
 *
 * An option is an ordinary link. Choosing one is a page load, so the levels
 * under it are rebuilt by the page that answers rather than patched here, and
 * a middle-click opens it in a tab like any other link on the page.
 *
 * It is deliberately not the tab strip's business and does not touch it. A
 * level moves between *rows*; a tab moves between views of the one row.
 *
 * Over each crumb is the level's own title -- what these are, plural -- and it
 * is a link to where they are all listed: `resources` over a filename goes to
 * that profile's Resources tab. So a level names two places rather than one,
 * the row that is open and the list it came out of, and getting back to the
 * list no longer means going up to the profile and picking the tab again.
 * Both the word and the URL come from Navigator, because the view knows
 * nothing about the module -- it cannot pluralise `resource` into a heading in
 * a language it was not written in, and it certainly cannot know that a
 * profile's files are listed on the profile rather than on the list page.
 *
 * Included by every view, in place of its section title. What it needs is one
 * variable, which is the whole contract with src/Navigator.php.
 *
 * Creating is the one thing here that is not navigating, and it is drawn like
 * it: pinned under the options, past a rule, out of the filter and out of the
 * arrow keys, so the list you walk is still only places that exist. A level
 * that says you cannot write a new one -- the sections -- simply has no row.
 *
 * @var array<int, array<string, mixed>> $navigator Levels, outermost first.
 *                                       Each: key, title of text and href,
 *                                       text, mono, prompt, search,
 *                                       options[] of text, note, href, active,
 *                                       add of text, href, active (or null).
 */

$navigator = isset($navigator) && is_array($navigator) ? $navigator : [];

$e = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<style>
	/*
	 * A crumb has to read as a crumb rather than as a form: no border, no
	 * background, the page's own heading type -- and then all three on hover,
	 * which is what says it can be clicked at all.
	 *
	 * The heading type is set on the crumb links and never on the <ol> or the
	 * <li>, because a dropdown menu is a child of its crumb and inherits
	 * whatever either of those carries. An 18px/28px heading inherited into a
	 * list of options is what made the first cut of this look stretched.
	 */
	.oryk-nav .breadcrumb {
		margin: 0;
		padding: 0;
		background: none;
		gap: 10px;
	}
	.oryk-nav .breadcrumb > li {
		display: flex;
		flex-direction: column;
	}
	.oryk-nav .breadcrumb > li > a {
		display: inline-block;
		padding: 1px 6px;
		border: 1px solid transparent;
		border-radius: 3px;
		font-size: 18px;
		line-height: 26px;
		text-decoration: none;
	}
	.oryk-nav .breadcrumb > li > a.oryk-nav-toggle:hover,
	.oryk-nav .breadcrumb > li > a.oryk-nav-toggle:focus,
	.oryk-nav .breadcrumb > li.open > a.oryk-nav-toggle {
		border-color: #ddd;
		background: #f7f7f7;
	}
	.oryk-nav .oryk-nav-toggle .caret {
		margin-left: 5px;
		color: #999;
	}

	/*
	 * The level's title, over the crumb: small, muted, and a link to the list
	 * it names. Its own font-size rule, because the heading type above is set
	 * on every direct link of a crumb and this is not the heading.
	 *
	 * `align-self` because the <li> is a flex column, which would otherwise
	 * stretch the link -- and its underline -- the full width of the crumb
	 * under it. What it points at is one word, so one word is what it is.
	 */
	.oryk-nav .breadcrumb > li > .oryk-nav-title {
		align-self: flex-start;
		padding: 0 7px;
		border: 0;
		font-size: 12px;
		line-height: 17px;
		color: #999;
	}
	.oryk-nav .breadcrumb > li > a.oryk-nav-title:hover,
	.oryk-nav .breadcrumb > li > a.oryk-nav-title:focus {
		color: #333;
		text-decoration: underline;
	}

	/* A name that is typed exactly -- a filename, twelve hex digits -- is
	   shown as it is spelled, here as everywhere else in the module. */
	.oryk-nav-mono {
		font-family: monospace;
	}

	/* Nothing chosen at this level yet. An invitation, not a value. */
	.oryk-nav-prompt {
		color: #999;
		font-style: italic;
	}

	/*
	 * The menu states all of its own type, padding and spacing rather than
	 * taking any of it from somewhere else. Every rule is specific enough to
	 * beat both Bootstrap's defaults and whatever the admin theme has to say
	 * about a link sitting inside a page heading -- which is what the first
	 * cut lost its left padding to.
	 */
	.oryk-nav .oryk-nav-menu {
		min-width: 240px;
		max-width: 380px;
		max-height: 320px;
		overflow-y: auto;
		padding: 0;
		font-size: 13px;
		line-height: 1.4;
	}
	.oryk-nav .oryk-nav-menu > li > a {
		display: block;
		padding: 5px 14px;
		font-size: 13px;
		line-height: 17px;
		white-space: normal;
	}
	.oryk-nav .oryk-nav-menu > li > a:hover,
	.oryk-nav .oryk-nav-menu > li > a:focus,
	.oryk-nav .oryk-nav-menu > li.oryk-nav-here > a {
		background: #f5f5f5;
		text-decoration: none;
	}

	/* The one you are already on. Bootstrap draws this white-on-blue, which
	   its own background rule here would leave white-on-grey, so the colour
	   is stated too. */
	.oryk-nav .oryk-nav-menu > li.active > a {
		background: #eef2f0;
		color: #333;
		font-weight: 600;
	}
	.oryk-nav .oryk-nav-menu .oryk-nav-label {
		display: block;
	}

	/* The second line of an option -- a client's device description under its
	   MAC. Tight to the line above it, so the pair reads as one row. */
	.oryk-nav .oryk-nav-menu .oryk-nav-note {
		display: block;
		margin-top: 1px;
		color: #999;
		font-size: 12px;
		line-height: 15px;
	}

	/* The filter stays put while the list scrolls under it: a search box that
	   scrolls away is one you have to find again to fix a typo. */
	.oryk-nav .oryk-nav-filter {
		position: sticky;
		top: 0;
		z-index: 1;
		padding: 6px 8px;
		background: #fff;
		border-bottom: 1px solid #eee;
	}
	.oryk-nav .oryk-nav-filter input {
		height: 28px;
		padding: 3px 8px;
		font-size: 13px;
		line-height: 20px;
		box-shadow: none;
	}
	.oryk-nav .oryk-nav-menu > li.oryk-nav-empty {
		padding: 6px 14px;
		color: #999;
		font-size: 13px;
		line-height: 17px;
	}

	/*
	 * Where a new one is written. Pinned to the foot of the menu the way the
	 * filter is pinned to its head -- a list long enough to scroll is exactly
	 * the list where you may give up on finding one and write it instead, and
	 * having to scroll to the end to do that is the wrong way round.
	 *
	 * The rule above it is the whole distinction being drawn: everything over
	 * the line is somewhere that exists.
	 */
	.oryk-nav .oryk-nav-menu > li.oryk-nav-add {
		position: sticky;
		bottom: 0;
		z-index: 1;
		background: #fff;
		border-bottom: 1px solid #eee;
	}
	.oryk-nav .oryk-nav-menu > li.oryk-nav-add > a {
		color: #555;
	}
	.oryk-nav .oryk-nav-menu > li.oryk-nav-add .oryk-nav-plus {
		display: inline-block;
		width: 12px;
		margin-right: 4px;
		font-weight: 700;
		color: #999;
	}
	.oryk-nav .oryk-nav-menu > li.oryk-nav-add > a:hover .oryk-nav-plus,
	.oryk-nav .oryk-nav-menu > li.oryk-nav-add > a:focus .oryk-nav-plus,
	.oryk-nav .oryk-nav-menu > li.oryk-nav-add.active .oryk-nav-plus {
		color: inherit;
	}
</style>

<nav class="oryk-nav" aria-label="<?php echo $e(_('Breadcrumb')); ?>" style="margin-bottom: 25px;">
	<ol class="breadcrumb">

		<li>
			<a href="?display=oryk_provisioner" style="font-weight: bolder;"><?php echo $e(_('Provisioner')); ?></a>
		</li>

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
			?>
			<li class="dropdown oryk-nav-level">

				<?php if ($titleText !== '' && $titleHref !== ''): ?>
					<a class="oryk-nav-title" href="<?php echo $e($titleHref); ?>" title="<?php echo $e(sprintf(_('Go to %s'), $titleText)); ?>"><?php echo $e($titleText); ?></a>
				<?php elseif ($titleText !== ''): ?>
					<span class="oryk-nav-title"><?php echo $e($titleText); ?></span>
				<?php endif; ?>

				<a href="#" class="oryk-nav-toggle" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false">
					<span class="oryk-nav-text<?php echo $chosen ? $mono : ' oryk-nav-prompt'; ?>" data-oryk-nav-text="<?php echo $e($key); ?>" data-oryk-nav-mono="<?php echo $mono === '' ? '0' : '1'; ?>">
						<?php echo $e($chosen ? $level['text'] : $level['prompt']); ?>
					</span>
					<span class="caret"></span>
				</a>

				<ul class="dropdown-menu oryk-nav-menu">

					<li class="oryk-nav-filter">
						<input type="text" class="form-control input-sm" placeholder="<?php echo $e($level['search']); ?>" aria-label="<?php echo $e($level['search']); ?>">
					</li>

					<?php if (!$level['options']): ?>
						<li class="oryk-nav-empty"><?php echo $e(_('Nothing here yet')); ?></li>
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
	 * @param string key  Level to write: section, client, profile, resource.
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
