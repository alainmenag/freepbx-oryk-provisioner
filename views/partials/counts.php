<?php
/**
 * views/partials/counts.php -- the row counts a tab is labelled with.
 *
 * Every table in this module is drawn on a tab, and every one of those tabs
 * carries the number of rows behind it. The number is rendered with the page,
 * out of Counts, and refreshed from here whenever something on the page has
 * changed one.
 *
 * Two things, and they are the same contract read from either end. In the
 * markup, a badge is written by name -- the table's name, not the tab's:
 *
 *     <?php $countBadge('resources'); ?>
 *
 * and in the browser, orykCounts() writes every badge on the page from a
 * single request. Nothing adds or subtracts: a deleted row, a cleared log, a
 * delete the module refused and a row somebody wrote in another tab all end
 * the same way, which is this page asking again. A count arrived at
 * arithmetically is right until the first thing nobody thought of.
 *
 * What neither end may do is read a count off the table it labels. A table is
 * asked with whatever is in its search box and answers with the total of what
 * matched, so a tab counted from its own rows would fall as somebody typed
 * underneath it.
 *
 * Included by every view with a count on it, before its tab strip.
 * orykPost() has to exist by the time orykCounts() is *called*, not when it
 * is defined: views/admin.php has its own, the three editors take it from
 * views/partials/editor.php.
 *
 * @var array<string, int>   $counts     Rows behind each tab, from Counts
 * @var array<string, mixed> $countScope What narrows them -- profile_id, mac,
 *                                       the same parameters the list commands
 *                                       on this page are asked with
 */

$counts = isset($counts) && is_array($counts) ? $counts : [];
$countScope = isset($countScope) && is_array($countScope) ? $countScope : [];

/**
 * Write one tab's badge.
 *
 * A closure rather than a function because it is the view layer's idiom here
 * -- $h alongside it does the same for escaping -- and because it closes over
 * the counts the page was handed, so a badge is its name and nothing else.
 *
 * @param string $name Count to draw, named for its table.
 *
 * @return void
 */
$countBadge = function ($name) use ($counts) {
	printf(
		'<span class="badge" data-oryk-count="%s">%d</span>',
		htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
		(int) ($counts[$name] ?? 0)
	);
};
?>
<script>

	// What narrows this page's counts, sent back with every refresh: the
	// profile whose editor this is, the MAC whose log is on it, or nothing at
	// all on the module page, which counts everything.
	var orykCountScope = <?php echo json_encode($countScope); ?>;

	/**
	 * Re-read every count on this page and write it into its badge.
	 *
	 * Called after anything that changes a row -- a delete, a cleared log --
	 * and safe to call when nothing did: it writes what the module says,
	 * which is the point of asking.
	 *
	 * Silent when it cannot be reached. A stale badge is worth less than the
	 * table beside it, and not worth an error over the top of a page where
	 * whatever was asked for has just worked.
	 */
	function orykCounts() {
		return orykPost('counts', orykCountScope).done(function (response) {
			if (!response || !response.status || !response.counts) {
				return;
			}

			$.each(response.counts, function (name, value) {
				$('[data-oryk-count="' + name + '"]').text(value);
			});
		});
	}

</script>
