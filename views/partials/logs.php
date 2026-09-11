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
 * @var string $logMac MAC to narrow to, or '' for every client's requests
 */

$logMac = (string) ($logMac ?? '');
$logNarrowed = $logMac !== '';

$logUrl = 'ajax.php?module=oryk_provisioner&command=listLogs'
	. ($logNarrowed ? '&mac=' . rawurlencode($logMac) : '');
?>
<style>
	.oryk-log-detail {
		display: block;
		color: #a94442;
		font-size: 11px;
		line-height: 15px;
	}
	.oryk-log-agent {
		display: block;
		max-width: 260px;
		overflow: hidden;
		color: #777;
		font-size: 11px;
		line-height: 15px;
		white-space: nowrap;
		text-overflow: ellipsis;
	}
	.oryk-log-time {
		white-space: nowrap;
	}
</style>

<p class="help-block fpbx-help-block">
	<?php echo $logNarrowed
		? _('Every file this client has asked for, whichever way it went. A phone with nothing here is a phone that is not reaching this PBX at all, which is a different fault from the ones the rows describe.')
		: _('Every file a client has asked this PBX for, whether or not the MAC is one that has been associated. A MAC with no client behind it is a phone waiting to be added rather than a phone that is not there.'); ?>
</p>

<div id="log_toolbar" class="oryk-toolbar">
	<button type="button" class="btn btn-danger" name="log_clear">
		<i class="fa fa-trash"></i>
		<?php echo $logNarrowed ? _('Clear This Client\'s Log') : _('Clear Log'); ?>
	</button>
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

	function formatLogTime(value) {
		return value ? `<span class="oryk-log-time">${orykEscape(value)}</span>` : '-';
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
	// a handful of rows are GET, and the ones that are not say so in their
	// message anyway.
	function formatLogStatus(value, row) {
		const code = Number(value) || 0;
		const style = code >= 200 && code < 300 ? 'label-success' : 'label-danger';
		const method = row.method ? orykEscape(row.method) : '';

		return `<span class="label ${style}" title="${method}">${code || '-'}</span>`;
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
		if (!window.confirm(orykLogClearConfirm)) {
			return;
		}

		orykPost('clearLogs', orykLogClear).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not clear the log.', 4);
				return;
			}

			$('#log_table').bootstrapTable('refresh');
			orykCounts();
			notie.alert(1, 'Cleared.', 2);
		});
	});

</script>
