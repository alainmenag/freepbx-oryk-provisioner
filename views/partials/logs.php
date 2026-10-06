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
 * Included by views/admin.php, views/client.php and
 * views/partials/overview.php, all of which already define orykPost() and
 * orykEscape(); this adds only what the log's own columns need.
 *
 * @var string $logMac      MAC to narrow to, or '' for every client's requests
 * @var string $logScope    Navigator scope key to narrow to, or '': the module
 *                          page opened from a navigator title, or Overview
 * @var bool   $logOverview On Overview: Clear is offered
 *                          for the scope, which the server resolves to MACs
 */

$logMac = (string) ($logMac ?? '');
$logScope = (string) ($logScope ?? '');
$logNarrowed = $logMac !== '';
$logOverview = !empty($logOverview) && $logScope !== '';

$logUrl = 'ajax.php?module=oryk_provisioner&command=listLogs'
	. ($logNarrowed ? '&mac=' . rawurlencode($logMac) : '')
	. ($logScope !== '' ? '&scope=' . rawurlencode($logScope) : '');
?>

<div id="log_toolbar" class="oryk-toolbar">
	<?php // Clear empties one MAC or the lot; a scope is neither, so only Overview gives it a Clear. ?>
	<?php if ($logScope === '' || $logOverview): ?>
	<button type="button" class="btn btn-danger" name="log_clear" title="<?php echo $logOverview ? _('Clear These Entries') : ($logNarrowed ? _('Clear This Client\'s Log') : _('Clear Log')); ?>">
		<?php echo $icon('trash'); ?>
		<?php echo _('Clear'); ?>
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
	data-icons-prefix="oryk-icon"
	data-icons='{"refresh":"oryk-icon-refresh"}'
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
	//
	// On Overview it is another command altogether, posted the scope: an
	// empty clearLogs is the whole log, so the two are never one request.
	var orykLogClearCommand = <?php echo json_encode($logOverview ? 'clearOverviewLogs' : 'clearLogs'); ?>;
	var orykLogClear = <?php echo json_encode($logOverview ? ['scope' => $logScope] : ($logNarrowed ? ['mac' => $logMac] : (object) [])); ?>;
	var orykLogClearConfirm = <?php echo json_encode($logOverview
		? _('Clear the provisioning log entries listed here?')
		: ($logNarrowed
			? _('Clear this client\'s provisioning log?')
			: _('Clear the provisioning log? Every client\'s requests go with it.'))); ?>;
	var orykLogUnknown = <?php echo json_encode(_('Not associated')); ?>;
	var orykLogMainConfig = <?php echo json_encode(_('(main config)')); ?>;

	// The time is the link to the entry's own page.
	function formatLogTime(value, row) {
		const time = value ? orykEscape(value) : '-';

		return `<a class="oryk-log-time" href="?display=oryk_provisioner&log=${encodeURIComponent(row.id)}">${time}</a>`;
	}

	// The MAC links to the client it belongs to *now*. One that belongs to
	// nothing is the row worth having a log for at all, and it is drawn as
	// plain text rather than a dead link.
	function formatLogMac(value, row) {
		if (!value) {
			return '-';
		}

		const mac = orykEscape(value);

		return row.client_id
			? `<a href="?display=oryk_provisioner&client=${encodeURIComponent(row.client_id)}">${mac}</a>`
			: `<code>${mac}</code>`;
	}

	function formatLogClient(value, row) {
		if (!row.client_id) {
			return `<span class="text-muted">${orykEscape(orykLogUnknown)}</span>`;
		}

		return value ? orykEscape(value) : '-';
	}

	// The reason a request failed sits under the filename it failed for,
	// which is the pair anyone reading this log is reading it for. An empty
	// filename is /provisioner/?mac=..., a caller with no name to give, and
	// the module reads that as the main config.
	function formatLogFilename(value, row) {
		const name = value
			? `<code>${orykEscape(value)}</code>`
			: `<span class="text-muted">${orykEscape(orykLogMainConfig)}</span>`;

		return row.message
			? `${name}<span class="oryk-log-detail">${orykEscape(row.message)}</span>`
			: name;
	}

	// The method is on the status rather than in a column of its own: all but
	// a handful of rows are a fetch, so GET and HEAD say so in the tooltip
	// alone and anything else is written out beside the code. A phone PUTting
	// a log is the reason that is worth doing -- a 200 for something received
	// and a 200 for something served are the same number and not the same
	// event, and the filename beside them does not always say which.
	function formatLogStatus(value, row) {
		const code = Number(value) || 0;
		const style = code >= 200 && code < 300 ? 'label-success' : 'label-danger';
		const method = row.method ? orykEscape(row.method) : '';
		const shown = method && method !== 'GET' && method !== 'HEAD' ? `${method} ` : '';

		return `<span class="label ${style}" title="${method}">${shown}${code || '-'}</span>`;
	}

	// Which phone, in the two ways a phone says so without being asked: the
	// address it came from and the User-Agent it announced, which is where
	// the model and the firmware are.
	function formatLogSource(value, row) {
		const ip = value ? orykEscape(value) : '-';

		if (!row.user_agent) {
			return ip;
		}

		const agent = orykEscape(row.user_agent);

		return `${ip}<span class="oryk-log-agent" title="${agent}">${agent}</span>`;
	}

	$(document).on('click', '[name="log_clear"]', function () {
		orykAsk(orykLogClearConfirm).done(() => {
			orykPost(orykLogClearCommand, orykLogClear).done(function (response) {
				if (!response || !response.status) {
					notie.alert(3, (response && response.message) || 'Could not clear the log.', 4);
					return;
				}

				$('#log_table').bootstrapTable('refresh');
				notie.alert(1, 'Cleared.', 2);
			});
		});
	});

</script>
