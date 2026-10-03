<?php
/**
 * views/partials/sections.php -- the module's main nav, over every page.
 *
 * Six fixed destinations, always in the same place, so they are a bar rather
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
<style>
	/*
	 * A flat band with an underline under the active section, so it never
	 * reads as the row's `nav-tabs` strip further down the page. Wraps rather
	 * than scrolls on a narrow screen.
	 */
	.oryk-sections {
		display: flex;
		flex-wrap: wrap;
		align-items: flex-end;
		gap: 4px 24px;
		margin-bottom: 20px;
		border-bottom: 3px solid #ddd;
	}
	.oryk-sections .oryk-sections-home {
		padding: 8px 0;
		font-size: 20px;
		line-height: 26px;
		font-weight: 700;
		text-decoration: none;
	}
	.oryk-sections .oryk-sections-version {
		display: block;
		font-size: 11px;
		line-height: 14px;
		font-weight: 400;
		color: #999;
	}
	.oryk-sections ul {
		display: flex;
		flex-wrap: wrap;
		margin: 0;
		padding: 0;
		list-style: none;
		margin-bottom: -2px;
	}
	.oryk-sections ul > li > a {
		display: block;
		margin-bottom: -1px;
		padding: 10px 14px 9px;
		border-bottom: 3px solid transparent;
		font-size: 15px;
		line-height: 22px;
		text-decoration: none;
		font-weight: 700;
		color: #0f5a59;
	}
	.oryk-sections ul > li > a:hover,
	.oryk-sections ul > li > a:focus {
		border-bottom-color: #ccc;
		background: #f7f7f7;
	}
	.oryk-sections ul > li.active > a {
		border-bottom-color: currentColor;
		color: currentColor;
	}
</style>

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
