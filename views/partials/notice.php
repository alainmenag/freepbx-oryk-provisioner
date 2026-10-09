<?php
/**
 * views/partials/notice.php -- one notice: its text, where it leads, and its
 * dismissal.
 *
 * Drawn by partials/notices.php with the page, and on its own by
 * Pages::dismissNotice() for the notice that takes a dismissed one's place.
 * The dismissal is bound by assets/oryk_notices.js, which posts the id and
 * the version printed here.
 *
 * @var array<string, mixed> $notice A Notices::showing() entry; `href` is '' on the page it leads to
 * @var callable             $icon   Prints assets/icons/<name>.svg
 */

$noticeEscape = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>
<div class="alert alert-<?php echo $notice['level'] === 'warning' ? 'warning' : 'info'; ?> oryk-notice" role="status"
	data-id="<?php echo $noticeEscape($notice['id']); ?>" data-version="<?php echo (int) $notice['version']; ?>">
	<span class="oryk-notice-text"><?php echo $noticeEscape($notice['text']); ?></span>
	<?php if ($notice['href'] !== ''): ?>
		<a class="btn btn-default oryk-notice-action" href="<?php echo $noticeEscape($notice['href']); ?>"><?php echo $noticeEscape($notice['action']); ?></a>
	<?php endif; ?>
	<?php if ($notice['dismissible']): ?>
		<button type="button" class="oryk-notice-dismiss" title="<?php echo $noticeEscape(_('Dismiss')); ?>" aria-label="<?php echo $noticeEscape(_('Dismiss')); ?>"><?php echo $icon('close'); ?></button>
	<?php endif; ?>
</div>
