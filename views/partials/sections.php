<?php
/**
 * views/partials/sections.php -- the module's main nav, over every page.
 *
 * Seven fixed destinations, always in the same place, so they are a bar rather
 * than a searchable dropdown. Everything under the section -- a row, a file of
 * that row -- is partials/navigator.php's, and a tab strip under that only
 * ever means views of the one row that is open.
 *
 * The active section is the branch the page is in: a resource page lights
 * Profiles, and clicking it goes back to the list.
 *
 * No badges: how many of each there are is on the dropdowns under it, where
 * the number is of what that dropdown lists here.
 *
 * Included by every view, directly before partials/navigator.php.
 *
 * @var array<int, array<string, mixed>> $sections Navigator::sections()
 * @var string                           $version  The module's, under its name
 */

$sections = isset($sections) && is_array($sections) ? $sections : [];
$version = isset($version) ? (string) $version : '';

$sectionEscape = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>

<nav class="oryk-sections" aria-label="<?php echo $sectionEscape(_('Provisioner sections')); ?>">
	<a class="oryk-sections-home" href="?display=oryk_provisioner">
		<?php echo $sectionEscape(_('Provisioner')); ?>
		<?php if ($version !== ''): ?>
			<span class="oryk-sections-version"><?php echo $sectionEscape(sprintf(_('v%s'), $version)); ?></span>
		<?php endif; ?>
	</a>
	<ul>
		<?php foreach ($sections as $section): ?>
			<li<?php echo !empty($section['active']) ? ' class="active"' : ''; ?>>
				<a href="<?php echo $sectionEscape($section['href']); ?>"<?php echo !empty($section['active']) ? ' aria-current="true"' : ''; ?>>
					<?php echo $sectionEscape($section['text']); ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</nav>
