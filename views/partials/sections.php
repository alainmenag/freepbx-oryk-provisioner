<?php
/**
 * views/partials/sections.php -- the module's main nav, over every page.
 *
 * Fixed destinations, always in the same place, so they are a bar rather
 * than a searchable dropdown. Everything under the section -- a row, a file of
 * that row -- is partials/navigator.php's, and a tab strip under that only
 * ever means views of the one row that is open.
 *
 * The active section is the branch the page is in: a resource page lights
 * Profiles, and clicking it goes back to the list.
 *
 * An entry with `items` is a group: still a link to the section it names --
 * the active one of the group, else its first -- with a menu of the group's
 * sections that opens on hover or focus. Which sections are grouped is
 * Navigator::sectionGroups()'s business; this only draws what it is given.
 *
 * No badges: how many of each there are is on the dropdowns under it, where
 * the number is of what that dropdown lists here.
 *
 * Included by every view, directly before partials/navigator.php.
 *
 * @var array<int, array<string, mixed>> $sections Navigator::sections()
 * @var string                           $version  The module's, under its name
 * @var callable                         $icon     Prints assets/icons/<name>.svg
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
			<?php if (!empty($section['items'])): ?>
				<li class="oryk-sections-group<?php echo !empty($section['active']) ? ' active' : ''; ?>">
					<a href="<?php echo $sectionEscape($section['href']); ?>" aria-haspopup="true"<?php echo !empty($section['active']) ? ' aria-current="true"' : ''; ?>>
						<?php echo $sectionEscape($section['text']); ?><?php echo $icon('chevron-down', 'oryk-sections-caret'); ?>
					</a>
					<ul class="dropdown-menu oryk-sections-menu">
						<?php foreach ($section['items'] as $item): ?>
							<li<?php echo !empty($item['active']) ? ' class="active"' : ''; ?>>
								<a href="<?php echo $sectionEscape($item['href']); ?>"<?php echo !empty($item['active']) ? ' aria-current="true"' : ''; ?>>
									<?php echo $sectionEscape($item['text']); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</li>
			<?php else: ?>
				<li<?php echo !empty($section['active']) ? ' class="active"' : ''; ?>>
					<a href="<?php echo $sectionEscape($section['href']); ?>"<?php echo !empty($section['active']) ? ' aria-current="true"' : ''; ?>>
						<?php echo $sectionEscape($section['text']); ?>
					</a>
				</li>
			<?php endif; ?>
		<?php endforeach; ?>
	</ul>
</nav>
