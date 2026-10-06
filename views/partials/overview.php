<?php
/**
 * views/partials/overview.php -- the Overview section's pane.
 *
 * Everything tied to one user or one client, each part removable where it is
 * listed, and all of it by the action bar's Delete All. What counts as tied,
 * and what Delete All takes, is Overview's business (src/Overview.php, and
 * ARCHITECTURE.md, "Overview"); this draws what inventory() found.
 *
 * It is tables all the way down -- User, Clients, Provisioning log, Bans --
 * and they are the lists' own: the same ids and formatters, so
 * views/admin.php's handlers answer their buttons, with a column or two only
 * Overview has. Every
 * command of this pane's own is posted the scope and nothing else.
 *
 * Included by views/admin.php, which defines orykPost() and the formatters.
 *
 * @var array<string, mixed>|null $overview Overview::inventory(), or null with
 *                                          no user or client chosen
 */

$overview = isset($overview) && is_array($overview) ? $overview : null;

$oe = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>

<?php if (!$overview): ?>
<div class="oryk-overview-empty">
	<p><strong><?php echo _('Select a user or a client above.'); ?></strong></p>
	<p class="text-muted"><?php echo _('Overview lists everything tied to it -- clients, provisioning log entries, stored phone logs and bans -- so any of it can be removed on its own, or all of it at once.'); ?></p>
</div>
<?php return; endif; ?>

<?php
$oIsUser = $overview['kind'] === 'user';
$oFreepbx = is_array($overview['freepbx']) ? $overview['freepbx'] : [];
$oShared = !empty($oFreepbx['shared']);
$oClients = count($overview['clients']);
$oLogs = (int) $overview['logs'];
$oStored = (int) $overview['stored']['files'];
$oNamed = count($overview['named']);
$oApplying = count(array_diff($overview['applying'], $overview['named']));
$oScopeQuery = '&scope=' . rawurlencode($overview['key']);

// What Delete All asks, from what is on the page.
$oParts = [];

if ($oIsUser && $oClients) {
	$oParts[] = sprintf($oClients === 1 ? _('%d client') : _('%d clients'), $oClients);
}

if ($oLogs) {
	$oParts[] = sprintf($oLogs === 1 ? _('%d provisioning log entry') : _('%d provisioning log entries'), $oLogs);
}

if ($oStored) {
	$oParts[] = sprintf($oStored === 1 ? _('%d stored phone log') : _('%d stored phone logs'), $oStored);
}

if ($oNamed) {
	$oParts[] = sprintf($oNamed === 1 ? _('%d ban naming it') : _('%d bans naming it'), $oNamed);
}

$oAsk = $oIsUser
	? sprintf(_('Delete user %s and everything listed here?'), $overview['id'])
	: _('Delete this client and everything listed here?');

if ($oParts) {
	$oAsk .= ' ' . sprintf(_('That is %s.'), implode(', ', $oParts));
}

if ($oIsUser) {
	$oAsk .= ' ' . ($oShared
		? _('Another device is on this extension, so the extension, its User Manager account, voicemail, call history and the bans naming it stay.')
		: _('The extension, its User Manager account, its voicemail and its call history and recordings are removed permanently.'));
}

if ($oApplying) {
	$oAsk .= ' ' . _('Bans that only apply to it are kept.');
}

$oAsk .= ' ' . _('This cannot be undone.');
?>

<div class="oryk-overview">

	<?php if ($oShared): ?>
	<div class="alert alert-warning">
		<?php echo $oe(sprintf(_('Another device is on extension %s. Deleting this user removes its own device and clients; the extension, its User Manager account, voicemail, call history and the bans naming it stay until that device is gone too.'), $overview['id'])); ?>
	</div>
	<?php endif; ?>

	<h4 class="oryk-overview-heading"><?php echo _('User'); ?></h4>
	<table
		id="user_table"
		data-toggle="table"
		data-url="ajax.php?module=oryk_provisioner&command=listOverviewUsers<?php echo $oe($oScopeQuery); ?>"
		class="table table-striped"
		data-side-pagination="server"
		data-show-refresh="true"
		data-icons-prefix="oryk-icon"
		data-icons='{"refresh":"oryk-icon-refresh"}'
		data-unique-id="extension">
		<thead>
			<tr>
				<th data-field="extension" data-formatter="formatUserExtension"><?php echo _('Extension'); ?></th>
				<th data-field="name" data-formatter="formatText"><?php echo _('Name'); ?></th>
				<th data-field="context" data-formatter="formatUserContext"><?php echo _('Context'); ?></th>
				<th data-field="account" data-formatter="formatOverviewAccount"><?php echo _('User Manager'); ?></th>
				<th data-field="last_seen" data-formatter="formatClientSeen"><?php echo _('Last Seen'); ?></th>
				<th data-field="actions" data-formatter="formatUserActions" data-align="right"><?php echo _('Actions'); ?></th>
			</tr>
		</thead>
	</table>

	<?php if ($oIsUser): ?>
	<h4 class="oryk-overview-heading"><?php echo _('Devices'); ?></h4>
	<table
		id="device_table"
		data-toggle="table"
		data-url="ajax.php?module=oryk_provisioner&command=listOverviewDevices<?php echo $oe($oScopeQuery); ?>"
		class="table table-striped"
		data-side-pagination="server"
		data-show-refresh="true"
		data-icons-prefix="oryk-icon"
		data-icons='{"refresh":"oryk-icon-refresh"}'
		data-unique-id="id">
		<thead>
			<tr>
				<th data-field="id" data-formatter="formatDevice"><?php echo _('Device'); ?></th>
				<th data-field="description" data-formatter="formatText"><?php echo _('Description'); ?></th>
				<th data-field="tech" data-formatter="formatText"><?php echo _('Technology'); ?></th>
				<th data-field="status" data-formatter="formatOverviewDeviceStatus"><?php echo _('Status'); ?></th>
				<th data-field="actions" data-formatter="formatOverviewDeviceActions" data-align="right"><?php echo _('Actions'); ?></th>
			</tr>
		</thead>
	</table>
	<?php endif; ?>

	<h4 class="oryk-overview-heading"><?php echo $oIsUser ? _('Clients') : _('Client'); ?></h4>
	<table
		id="client_table"
		data-toggle="table"
		data-url="ajax.php?module=oryk_provisioner&command=listOverviewClients<?php echo $oe($oScopeQuery); ?>"
		class="table table-striped"
		data-side-pagination="server"
		data-pagination="true"
		data-show-refresh="true"
		data-icons-prefix="oryk-icon"
		data-icons='{"refresh":"oryk-icon-refresh"}'
		data-unique-id="id"
		data-row-style="orykRowClasses"
		data-sort-name="mac"
		data-sort-order="asc">
		<thead>
			<tr>
				<th data-field="mac" data-formatter="formatMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
				<th data-field="description" data-formatter="formatText" data-sortable="true"><?php echo _('Description'); ?></th>
				<th data-field="profile" data-formatter="formatClientProfile" data-sortable="true"><?php echo _('Profile'); ?></th>
				<th data-field="secure" data-formatter="formatClientSecure" data-sortable="true"><?php echo _('Secure'); ?></th>
				<th data-field="stored_files" data-formatter="formatOverviewStored"><?php echo _('Stored Logs'); ?></th>
				<th data-field="last_seen" data-formatter="formatClientSeen" data-sortable="true"><?php echo _('Last Seen'); ?></th>
				<th data-field="actions" data-formatter="formatClientActions" data-align="right"><?php echo _('Actions'); ?></th>
			</tr>
		</thead>
	</table>

	<?php if ($oIsUser): ?>
	<h4 class="oryk-overview-heading"><?php echo _('Call Detail Record'); ?></h4>
	<div id="call_toolbar" class="oryk-toolbar">
		<button type="button" class="btn btn-danger" id="oryk_overview_calls_clear" title="<?php echo _('Clear Call History'); ?>">
			<?php echo $icon('trash'); ?>
			<?php echo _('Clear'); ?>
		</button>
	</div>
	<table
		id="call_table"
		data-toggle="table"
		data-url="ajax.php?module=oryk_provisioner&command=listOverviewCalls<?php echo $oe($oScopeQuery); ?>"
		data-toolbar="#call_toolbar"
		class="table table-striped"
		data-side-pagination="server"
		data-pagination="true"
		data-show-refresh="true"
		data-icons-prefix="oryk-icon"
		data-icons='{"refresh":"oryk-icon-refresh"}'
		data-sort-name="calldate"
		data-sort-order="desc">
		<thead>
			<tr>
				<th data-field="calldate" data-formatter="formatText" data-sortable="true"><?php echo _('Time'); ?></th>
				<th data-field="src" data-formatter="formatOverviewCaller" data-sortable="true"><?php echo _('From'); ?></th>
				<th data-field="dst" data-formatter="formatText" data-sortable="true"><?php echo _('To'); ?></th>
				<th data-field="disposition" data-formatter="formatOverviewDisposition" data-sortable="true"><?php echo _('Result'); ?></th>
				<th data-field="duration" data-formatter="formatOverviewDuration" data-sortable="true" data-align="right"><?php echo _('Duration'); ?></th>
				<th data-field="recordingfile" data-formatter="formatOverviewRecording"><?php echo _('Recording'); ?></th>
			</tr>
		</thead>
	</table>

	<h4 class="oryk-overview-heading"><?php echo _('Voicemail'); ?></h4>
	<div id="voicemail_toolbar" class="oryk-toolbar">
		<button type="button" class="btn btn-danger" id="oryk_overview_voicemail_clear" title="<?php echo _('Clear Voicemail'); ?>">
			<?php echo $icon('trash'); ?>
			<?php echo _('Clear'); ?>
		</button>
	</div>
	<table
		id="voicemail_table"
		data-toggle="table"
		data-url="ajax.php?module=oryk_provisioner&command=listOverviewVoicemail<?php echo $oe($oScopeQuery); ?>"
		data-toolbar="#voicemail_toolbar"
		class="table table-striped"
		data-side-pagination="server"
		data-pagination="true"
		data-show-refresh="true"
		data-icons-prefix="oryk-icon"
		data-icons='{"refresh":"oryk-icon-refresh"}'
		data-unique-id="id">
		<thead>
			<tr>
				<th data-field="time" data-formatter="formatText"><?php echo _('Time'); ?></th>
				<th data-field="callerid" data-formatter="formatText"><?php echo _('From'); ?></th>
				<th data-field="folder" data-formatter="formatText"><?php echo _('Folder'); ?></th>
				<th data-field="duration" data-formatter="formatOverviewDuration" data-align="right"><?php echo _('Duration'); ?></th>
				<th data-field="actions" data-formatter="formatOverviewVoicemailActions" data-align="right"><?php echo _('Actions'); ?></th>
			</tr>
		</thead>
	</table>
	<?php endif; ?>

	<h4 class="oryk-overview-heading"><?php echo _('Provisioning Logs'); ?></h4>
	<?php
	$logMac = '';
	$logScope = $overview['key'];
	$logOverview = true;
	include __DIR__ . '/logs.php';
	?>

	<h4 class="oryk-overview-heading"><?php echo _('Bans'); ?></h4>
	<table
		id="ban_table"
		data-toggle="table"
		data-url="ajax.php?module=oryk_provisioner&command=listOverviewBans<?php echo $oe($oScopeQuery); ?>"
		class="table table-striped"
		data-side-pagination="server"
		data-pagination="true"
		data-show-refresh="true"
		data-icons-prefix="oryk-icon"
		data-icons='{"refresh":"oryk-icon-refresh"}'
		data-unique-id="id"
		data-row-style="orykBanRowClasses"
		data-sort-name="created_at"
		data-sort-order="desc">
		<thead>
			<tr>
				<th data-field="names" data-formatter="formatOverviewBan"><?php echo _('Delete All'); ?></th>
				<th data-field="ip" data-formatter="formatBanIp" data-sortable="true"><?php echo _('IP'); ?></th>
				<th data-field="mac" data-formatter="formatBanMac" data-sortable="true"><?php echo _('MAC'); ?></th>
				<th data-field="user" data-formatter="formatBanUser" data-sortable="true"><?php echo _('User'); ?></th>
				<th data-field="client" data-formatter="formatBanClient" data-sortable="true"><?php echo _('Client'); ?></th>
				<th data-field="profile" data-formatter="formatBanProfile" data-sortable="true"><?php echo _('Profile'); ?></th>
				<th data-field="state" data-formatter="formatBanState" data-sortable="true"><?php echo _('State'); ?></th>
				<th data-field="expires_at" data-formatter="formatBanExpires" data-sortable="true"><?php echo _('Expires'); ?></th>
				<th data-field="actions" data-formatter="formatBanActions" data-align="right"><?php echo _('Actions'); ?></th>
			</tr>
		</thead>
	</table>

</div>

<script>

	// The one thing every command of this pane is posted: the server works
	// out what is related, so nothing here names a row to delete.
	const orykOverviewScope = <?php echo json_encode((string) $overview['key']); ?>;
	const orykOverviewAsk = <?php echo json_encode($oAsk); ?>;
	const orykOverviewRemoves = <?php echo json_encode(_('Removes')); ?>;
	const orykOverviewKeeps = <?php echo json_encode(_('Keeps')); ?>;

	// Whether the device is registered with Asterisk right now, as the list
	// command asked it: where from and how fast are on the hover.
	function formatOverviewDeviceStatus(value) {
		const status = value || {};
		const labels = {
			registered: ['label-success', 'Registered'],
			unreachable: ['label-warning', 'Unreachable'],
			unregistered: ['label-default', 'Not registered'],
			unknown: ['label-default', 'Unknown']
		};

		if (!labels[status.state]) {
			return '-';
		}

		const detail = [];

		if (status.address) {
			detail.push(`at ${status.address}`);
		}

		if (status.rtt !== null && status.rtt !== undefined) {
			detail.push(`${status.rtt} ms`);
		}

		if (Number(status.contacts) > 1) {
			detail.push(`${status.contacts} contacts`);
		}

		if (status.state === 'unknown') {
			detail.push('Asterisk could not be asked');
		}

		return `<span class="label ${labels[status.state][0]}" title="${orykEscape(detail.join(', '))}">${labels[status.state][1]}</span>`;
	}

	// Delete is the device and nothing else; on the user's own, the question
	// says what that leaves behind. Edit is the device's page in FreePBX.
	function formatOverviewDeviceActions(value, row) {
		return `<div class="flex gap-3" style="justify-content: flex-end;">` +
			`<button type="button" class="btn btn-danger btn-sm" name="overview_device_delete" value="${orykEscape(row.id)}" data-own="${Number(row.own) ? 1 : 0}" title="Delete this device">${orykIcon('trash')}</button>` +
			`<a class="btn btn-primary btn-sm" href="?display=devices&extdisplay=${encodeURIComponent(row.id)}">Edit</a>` +
			`</div>`;
	}

	$(document).on('click', '[name="overview_device_delete"]', function () {
		const ask = Number($(this).data('own'))
			? 'Delete this device? Only the device is deleted. A client using it is kept, with no device assigned. It is this user\'s own device, so the user leaves the Users list and this page; its extension, User Manager account, voicemail and call history stay in FreePBX. This cannot be undone.'
			: 'Delete this device? Only the device is deleted. A client using it is kept, with no device assigned. This cannot be undone.';

		if (!window.confirm(ask)) {
			return;
		}

		const button = $(this).prop('disabled', true);

		orykPost('deleteOverviewDevice', { scope: orykOverviewScope, id: button.val() }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
			}
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not delete.', 4);
		});
	});

	// Whether Delete All takes the ban: only one naming this row.
	function formatOverviewBan(value) {
		return Number(value)
			? `<span class="label label-danger" title="Names this ${orykOverviewScope.split(':')[0]}, its MAC or its extension">${orykEscape(orykOverviewRemoves)}</span>`
			: `<span class="label label-default" title="Only applies to it, through an address or a profile">${orykEscape(orykOverviewKeeps)}</span>`;
	}

	// What Delete All asks is counted by the server when the page is drawn, so
	// anything deleted from one of the tables is answered with the page again.
	$(document).ajaxSuccess(function (event, xhr, settings) {
		const answer = xhr && xhr.responseJSON;

		if (answer && answer.status && /[?&]command=(deleteClient|deleteBan|clearOverviewLogs|clearOverviewStored|clearOverviewHistory|clearOverviewVoicemail|deleteOverviewDevice)(&|$)/.test(settings.url || '')) {
			window.location.reload();
		}
	});

	// The owned account, as a link to it in User Manager.
	function formatOverviewAccount(value, row) {
		if (!value) {
			return '<span class="text-muted">None this module owns</span>';
		}

		return Number(row.account_id)
			? `<a href="?display=userman&action=showuser&user=${encodeURIComponent(row.account_id)}" title="Open in User Manager">${orykEscape(value)}</a>`
			: orykEscape(value);
	}

	// The number, with the caller id it announced under it when that says more.
	function formatOverviewCaller(value, row) {
		const number = value ? orykEscape(value) : '-';

		return row.clid && row.clid !== value
			? `${number}<span class="oryk-log-detail">${orykEscape(row.clid)}</span>`
			: number;
	}

	function formatOverviewDisposition(value) {
		if (!value) {
			return '-';
		}

		return `<span class="label ${value === 'ANSWERED' ? 'label-success' : 'label-default'}">${orykEscape(String(value).toLowerCase())}</span>`;
	}

	function formatOverviewDuration(value) {
		const seconds = Math.max(0, Number(value) || 0);

		return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
	}

	function formatOverviewRecording(value) {
		return value ? `<span title="${orykEscape(value)}">Yes</span>` : '<span class="text-muted">No</span>';
	}

	// The history alone, with the user kept: what a delete does to it.
	$(document).on('click', '#oryk_overview_calls_clear', function () {
		if (!window.confirm('Clear this user\'s call history? Every call it was part of is removed, with its recordings -- from the other extension\'s history too. The user is kept. This cannot be undone.')) {
			return;
		}

		const button = $(this).prop('disabled', true);

		orykPost('clearOverviewHistory', { scope: orykOverviewScope }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				notie.alert(3, (response && response.message) || 'Could not clear the call history.', 4);
			}
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not clear the call history.', 4);
		});
	});

	function formatOverviewVoicemailActions(value, row) {
		return `<div class="flex gap-3" style="justify-content: flex-end;">` +
			`<button type="button" class="btn btn-danger btn-sm" name="overview_voicemail_delete" value="${orykEscape(row.id)}" title="Delete this message">${orykIcon('trash')}</button>` +
			`</div>`;
	}

	// The table is asked again afterwards: the messages after a deleted one
	// are renumbered, so the ids on the page are stale.
	$(document).on('click', '[name="overview_voicemail_delete"]', function () {
		if (!window.confirm('Delete this voicemail message? This cannot be undone.')) {
			return;
		}

		const button = $(this).prop('disabled', true);

		orykPost('deleteOverviewVoicemail', { scope: orykOverviewScope, id: button.val() }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
			} else {
				notie.alert(1, 'Deleted.', 2);
			}

			$('#voicemail_table').bootstrapTable('refresh');
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not delete.', 4);
		});
	});

	// The messages alone: the mailbox, its greetings and the user are kept.
	$(document).on('click', '#oryk_overview_voicemail_clear', function () {
		if (!window.confirm('Clear this user\'s voicemail? Every message in every folder is deleted. The mailbox and its greetings are kept. This cannot be undone.')) {
			return;
		}

		const button = $(this).prop('disabled', true);

		orykPost('clearOverviewVoicemail', { scope: orykOverviewScope }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				notie.alert(3, (response && response.message) || 'Could not clear the voicemail.', 4);
			}
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not clear the voicemail.', 4);
		});
	});

	// The files a client has sent, with their own delete.
	function formatOverviewStored(value, row) {
		const files = Number(value) || 0;

		if (!files) {
			return '<span class="text-muted">None</span>';
		}

		const bytes = Number(row.stored_bytes) || 0;
		const size = bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : (bytes >= 1024 ? `${(bytes / 1024).toFixed(1)} KB` : `${bytes} bytes`);

		return `${files} file${files === 1 ? '' : 's'}, ${size} ` +
			`<button type="button" class="btn btn-danger btn-sm" name="overview_stored" value="${orykEscape(row.id)}" title="Delete this client's stored logs">${orykIcon('trash')}</button>`;
	}

	// Posted as a scope of that one client, like every command here.
	$(document).on('click', '[name="overview_stored"]', function () {
		if (!window.confirm('Delete the stored phone logs? The files this client has sent are removed from the PBX.')) {
			return;
		}

		const button = $(this).prop('disabled', true);

		orykPost('clearOverviewStored', { scope: 'client:' + button.val() }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not delete.', 4);
		});
	});

	// Delete All: the action bar's button, drawn by Pages::getActionBar(), and
	// the trash can on the user's own row, which is the same delete.
	$(document).on('click', '#orykpurge, [name="overview_purge"]', function (event) {
		event.preventDefault();

		if (!window.confirm(orykOverviewAsk)) {
			return;
		}

		const button = $(this).prop('disabled', true);

		orykPost('purgeOverview', { scope: orykOverviewScope }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			window.location = '?display=oryk_provisioner&tab=overview';
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not delete.', 4);
		});
	});

</script>
