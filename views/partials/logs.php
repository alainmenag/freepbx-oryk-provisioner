<?php
/**
 * views/partials/logs.php -- the provisioning log, as a table.
 *
 * One table asked two ways, the way listClients is asked twice: every request
 * the endpoint has answered, on the module page's Logs tab, and one client's
 * own on the client editor's. What differs between them is a MAC in the URL
 * and two columns that stop being worth drawing once every row has the same
 * value in them.
 *
 * It is narrowed by MAC rather than by client id, which is the whole point of
 * the tab: a row is written for a MAC whether or not anything is associated
 * with it, so the requests a phone made before somebody wrote its client are
 * on that client's tab the moment it exists. A run of 404s from before the
 * client was added is usually the most informative thing on the page.
 *
 * Included by views/admin.php and views/client.php, both of which already
 * define orykPost() and orykEscape(); this adds only what the log's own
 * columns need.
 *
 * @var string $logMac   MAC to narrow to, or '' for every client's requests
 * @var string $logScope Navigator scope key to narrow to, or '': the module
 *                       page opened from a navigator title
 */

$logMac = (string) ($logMac ?? '');
$logScope = (string) ($logScope ?? '');
$logNarrowed = $logMac !== '';

$logUrl = 'ajax.php?module=oryk_provisioner&command=listLogs'
	. ($logNarrowed ? '&mac=' . rawurlencode($logMac) : '')
	. ($logScope !== '' ? '&scope=' . rawurlencode($logScope) : '');
?>

<div id="log_toolbar" class="oryk-toolbar">
	<?php // Clear empties one MAC or the lot; a scope is neither, so it has no Clear. ?>
	<?php if ($logScope === ''): ?>
	<button type="button" class="btn btn-danger" name="log_clear">
		<i class="fa fa-trash"></i>
		<?php echo $logNarrowed ? _('Clear This Client\'s Log') : _('Clear Log'); ?>
	</button>
	<?php endif; ?>
</div>

<table
	id="log_table"
	data-toggle="table"
	data-url="<?php echo htmlspecialchars($logUrl, ENT_QUOTES, 'UTF-8'); ?>"
	data-toolbar="#log_toolbar"
	class="table table-striped"
	data-side-pagination="server"
	data-pagination="true"
	data-search="true"
	data-show-refresh="true"
	data-unique-id="id"
	data-sort-name="created_at"
	data-sort-order="desc">
	<thead>
		<tr>
			<th data-field="created_at" data-formatter="formatLogTime" data-sortable="true"><?php echo _('Time'); ?></th>
			<?php if (!$logNarrowed): ?>
				<th data-field="mac" data-formatter="formatLogMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
				<th data-field="description" data-formatter="formatLogClient"><?php echo _('Client'); ?></th>
			<?php endif; ?>
			<th data-field="filename" data-formatter="formatLogFilename" data-sortable="true"><?php echo _('Requested'); ?></th>
			<th data-field="status" data-formatter="formatLogStatus" data-sortable="true"><?php echo _('Status'); ?></th>
			<th data-field="ip" data-formatter="formatLogSource" data-sortable="true"><?php echo _('From'); ?></th>
		</tr>
	</thead>
</table>

<script>
	// What Clear posts: one client's rows on the client editor, the lot on
	// the module page. Nothing prunes this table on its own -- a row per file
	// per boot per phone -- so the button is what stands between a busy site
	// and a log larger than everything else the module has.
	var orykLogClear = <?php echo json_encode($logNarrowed ? ['mac' => $logMac] : []); ?>;
	var orykLogClearConfirm = <?php echo json_encode($logNarrowed
		? _('Clear this client\'s provisioning log?')
		: _('Clear the provisioning log? Every client\'s requests go with it.')); ?>;
	var orykLogUnknown = <?php echo json_encode(_('Not associated')); ?>;
	var orykLogMainConfig = <?php echo json_encode(_('(main config)')); ?>;
</script>
<?php echo $script('logs'); ?>
