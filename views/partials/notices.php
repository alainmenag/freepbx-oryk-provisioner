<?php
/**
 * views/partials/notices.php -- the module's notices, over the section bar on
 * every page. See ARCHITECTURE.md, "Notices".
 *
 * Included by partials/sections.php, so no view names it.
 *
 * @var array<int, array<string, mixed>> $notices Notices::showing()
 * @var callable                         $icon    Prints assets/icons/<name>.svg
 */

$notices = isset($notices) && is_array($notices) ? $notices : [];
?>
<?php foreach ($notices as $notice): ?>
	<?php include __DIR__ . '/notice.php'; ?>
<?php endforeach; ?>
