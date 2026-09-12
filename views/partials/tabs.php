<?php
/**
 * views/partials/tabs.php -- the tab strip every page is laid out under.
 *
 * A tab is a link. Clicking one is a page load, the way choosing a level in
 * partials/navigator.php is, and for the same reasons: the address is the
 * page, so the tab that is open is a thing the server decided rather than a
 * thing JavaScript did to the address afterwards.
 *
 * Until now the strip was Bootstrap's own: every pane rendered, `data-toggle`
 * switching between them, and a `shown.bs.tab` handler `replaceState`-ing the
 * tab back into the URL. Four things were wrong with it and all four are
 * gone here:
 *
 *   - A tab was not a link. Middle-click, ctrl-click, copy-link-address and
 *     open-in-new-tab -- every way of following a link that is not a plain
 *     left click -- did nothing, or opened `#oryk_clients` of the current
 *     page. Every other navigation in this module is an ordinary link.
 *   - The address was written after the fact, so the page and its URL could
 *     disagree for as long as it took the handler to run, and did disagree
 *     entirely when the browser had no history API.
 *   - `replaceState` leaves no history entry, so Back skipped every tab the
 *     user had moved through and left the module altogether.
 *   - Every pane was rendered and every table on it fetched, on every page
 *     load, however many of them were behind a tab nobody opened. A table
 *     drawn in a hidden pane also has no width to lay itself out against,
 *     which is what the `resetView` in each of those handlers was for.
 *
 * So the server renders one pane -- the one asked for -- and the strip above
 * it is links to the others. Nothing here is scripted at all.
 *
 * A tab with nothing behind it yet -- Resources on a profile nobody has
 * written -- is not drawn at all. It was drawn disabled at first, with a
 * title saying why, on the argument that hiding it reads as a feature the
 * page does not have where the truth is that it does not have it *yet*. What
 * settled it the other way is that a tab is a link now: a strip of links with
 * a dead one in it is the odd one out, and the page it would lead to is one
 * the user reaches by saving the row in front of them anyway. The views still
 * pass `disabled` and `title` -- they are what says which tabs to leave out.
 *
 * The badge is written by partials/counts.php, which is included before this
 * and refreshes every badge on the page from one request. Counts are of the
 * tabs, not of the panes, so a tab still says how many rows are behind it
 * when its pane is not the one on screen.
 *
 * Included by every view, between its error alert and its tab content.
 *
 * @var string                              $tab  Key of the tab to draw active
 * @var array<string, array<string, mixed>> $tabs Keyed by tab, in the order
 *                                                they are drawn. Each:
 *                                                label    what it is called
 *                                                href     where it goes
 *                                                count    badge to draw, named
 *                                                         for its table, from
 *                                                         partials/counts.php
 *                                                disabled nothing behind it
 *                                                         yet, so it is not
 *                                                         drawn
 *                                                title    why, when it is
 */

$tabs = isset($tabs) && is_array($tabs) ? $tabs : [];
$tab = (string) ($tab ?? '');

$tabEscape = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<ul class="nav nav-tabs">
	<?php foreach ($tabs as $tabKey => $tabItem): ?>
		<?php
		$isActive = (string) $tabKey === $tab;

		// A tab with nothing behind it yet is left out entirely -- see the
		// note at the top. The tab that is open is never one of them: it is
		// the pane the page actually rendered, so skipping it would leave a
		// strip that does not contain the page you are on.
		if (!$isActive && !empty($tabItem['disabled'])) {
			continue;
		}

		$tabAttributes = 'href="' . $tabEscape($tabItem['href']) . '"';

		if ($isActive) {
			$tabAttributes .= ' aria-current="page"';
		}
		?>
		<li<?php echo $isActive ? ' class="active"' : ''; ?>>
			<a <?php echo $tabAttributes; ?>><?php echo $tabEscape($tabItem['label']); ?><?php
				if (!empty($tabItem['count']) && isset($countBadge)) {
					echo ' ';
					$countBadge($tabItem['count']);
				}
			?></a>
		</li>
	<?php endforeach; ?>
</ul>
