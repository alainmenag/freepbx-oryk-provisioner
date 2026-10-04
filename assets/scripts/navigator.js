// assets/scripts/navigator.js -- the row of scoped dropdowns (views/partials/navigator.php).

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
