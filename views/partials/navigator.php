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
					<svg class="oryk-nav-caret" width="10" height="10" viewBox="0 0 10 10" aria-hidden="true" focusable="false"><path d="M1.5 3.5 5 7l3.5-3.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
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

<?php echo $script('navigator'); ?>
