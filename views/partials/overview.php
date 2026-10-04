<?php
/**
 * views/partials/overview.php -- the Overview section's pane.
 *
 * Everything tied to one user or one client, each part removable where it is
 * listed, and all of it by the action bar's Delete All. What counts as tied,
 * and what Delete All takes, is Overview's business (src/Overview.php, and
 * ARCHITECTURE.md, "Overview"); this draws what inventory() found.
 *
 * The tables are the lists' own -- the same ids, columns and formatters, asked
 * with `&scope=` -- so views/admin.php's handlers answer their buttons. Every
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

$oBytes = function ($bytes) {
	$bytes = (int) $bytes;

	if ($bytes >= 1048576) {
		return sprintf(_('%s MB'), number_format($bytes / 1048576, 1));
	}

	return $bytes >= 1024 ? sprintf(_('%s KB'), number_format($bytes / 1024, 1)) : sprintf(_('%d bytes'), $bytes);
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
$oUser = is_array($overview['user']) ? $overview['user'] : null;
$oClient = is_array($overview['client']) ? $overview['client'] : null;
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

	<h3 class="oryk-overview-title">
		<?php if ($oIsUser): ?>
			<?php echo _('User'); ?>
			<a class="oryk-name" href="?display=oryk_provisioner&amp;user=<?php echo $oe(rawurlencode($overview['id'])); ?>"><?php echo $oe($overview['id']); ?></a>
			<?php if ((string) ($oUser['name'] ?? '') !== ''): ?>
				<small><?php echo $oe($oUser['name']); ?></small>
			<?php endif; ?>
		<?php else: ?>
			<?php echo _('Client'); ?>
			<a class="oryk-name" href="?display=oryk_provisioner&amp;client=<?php echo (int) $overview['id']; ?>"><?php echo $oe((string) ($oClient['mac'] ?? '') !== '' ? $oClient['mac'] : '#' . (int) $overview['id']); ?></a>
		<?php endif; ?>
	</h3>

	<?php if ($oShared): ?>
	<div class="alert alert-warning">
		<?php echo $oe(sprintf(_('Another device is on extension %s. Deleting this user removes its own device and clients; the extension, its User Manager account, voicemail, call history and the bans naming it stay until that device is gone too.'), $overview['id'])); ?>
	</div>
	<?php endif; ?>

	<table class="table oryk-overview-facts">
		<tbody>
			<?php if ($oIsUser): ?>
			<tr>
				<th><?php echo _('Extension'); ?></th>
				<td><a href="?display=extensions&amp;extdisplay=<?php echo $oe(rawurlencode($overview['id'])); ?>"><?php echo $oe($overview['id']); ?></a></td>
			</tr>
			<tr>
				<th><?php echo _('Context'); ?></th>
				<td><?php echo $oe((string) ($oUser['context'] ?? '') !== '' ? $oUser['context'] : '-'); ?></td>
			</tr>
			<tr>
				<th><?php echo _('Last Seen'); ?></th>
				<td><?php echo $oe((string) ($oUser['last_seen'] ?? '') !== '' ? $oUser['last_seen'] : _('Never')); ?></td>
			</tr>
			<tr>
				<th><?php echo _('User Manager account'); ?></th>
				<td><?php echo (string) ($oFreepbx['account'] ?? '') !== '' ? $oe($oFreepbx['account']) : '<span class="text-muted">' . _('None this module owns') . '</span>'; ?></td>
			</tr>
			<tr>
				<th><?php echo _('Voicemail'); ?></th>
				<td><?php echo !empty($oFreepbx['mailbox']) ? _('A mailbox') : '<span class="text-muted">' . _('None') . '</span>'; ?></td>
			</tr>
			<tr>
				<th><?php echo _('Call history'); ?></th>
				<td id="oryk_overview_history"><span class="text-muted"><?php echo _('Counting...'); ?></span></td>
			</tr>
			<tr>
				<th><?php echo _('Clients'); ?></th>
				<td><?php echo $oClients; ?></td>
			</tr>
			<?php else: ?>
			<tr>
				<th><?php echo _('User'); ?></th>
				<td>
					<?php if ($oUser): ?>
						<a class="oryk-name" href="<?php echo $oe('?display=oryk_provisioner&tab=overview&scope=user:' . rawurlencode((string) $oUser['extension'])); ?>"><?php echo $oe($oUser['extension']); ?></a>
						<?php echo $oe((string) ($oUser['name'] ?? '')); ?>
						<span class="text-muted"><?php echo _('-- kept; deleting this client does not delete its user'); ?></span>
					<?php elseif ((string) ($oClient['device_id'] ?? '') !== ''): ?>
						<?php echo $oe(sprintf(_('Device %s, which is not a user'), $oClient['device_id'])); ?>
					<?php else: ?>
						<span class="text-muted"><?php echo _('None'); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php echo _('Last Seen'); ?></th>
				<td><?php echo $oe((string) ($oClient['last_seen'] ?? '') !== '' ? $oClient['last_seen'] : _('Never')); ?></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><?php echo _('Provisioning log'); ?></th>
				<td><?php echo $oe(sprintf($oLogs === 1 ? _('%d entry') : _('%d entries'), $oLogs)); ?></td>
			</tr>
			<tr>
				<th><?php echo _('Stored phone logs'); ?></th>
				<td>
					<?php if ($oStored): ?>
						<?php echo $oe(sprintf($oStored === 1 ? _('%d file, %s') : _('%d files, %s'), $oStored, $oBytes($overview['stored']['bytes']))); ?>
						<button type="button" class="btn btn-danger btn-sm" id="oryk_overview_stored" title="<?php echo $oe(_('Delete the stored phone logs')); ?>">
							<?php echo $icon('trash'); ?>
						</button>
					<?php else: ?>
						<span class="text-muted"><?php echo _('None'); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php echo _('Bans'); ?></th>
				<td>
					<?php echo $oe(sprintf(_('%d naming it'), $oNamed)); ?><?php if ($oApplying): ?>,
						<?php echo $oe(sprintf(_('%d more that apply to it and are kept by Delete All'), $oApplying)); ?>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<?php if ($oIsUser): ?>
	<h4 class="oryk-overview-heading"><?php echo _('Clients'); ?></h4>
	<table
		id="client_table"
		data-toggle="table"
		data-url="ajax.php?module=oryk_provisioner&command=listClients<?php echo $oe($oScopeQuery); ?>"
		class="table table-striped"
		data-side-pagination="server"
		data-pagination="true"
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
				<th data-field="last_seen" data-formatter="formatClientSeen" data-sortable="true"><?php echo _('Last Seen'); ?></th>
				<th data-field="actions" data-formatter="formatClientActions" data-align="right"><?php echo _('Actions'); ?></th>
			</tr>
		</thead>
	</table>
	<?php endif; ?>

	<h4 class="oryk-overview-heading"><?php echo _('Provisioning log'); ?></h4>
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

	// Whether Delete All takes the ban: only one naming this row.
	function formatOverviewBan(value) {
		return Number(value)
			? `<span class="label label-danger" title="Names this ${orykOverviewScope.split(':')[0]}, its MAC or its extension">${orykEscape(orykOverviewRemoves)}</span>`
			: `<span class="label label-default" title="Only applies to it, through an address or a profile">${orykEscape(orykOverviewKeeps)}</span>`;
	}

	// The counts above are printed by the server, so a row deleted from one of
	// the tables is answered with the page again rather than with numbers that
	// no longer add up.
	$(document).ajaxSuccess(function (event, xhr, settings) {
		const answer = xhr && xhr.responseJSON;

		if (answer && answer.status && /[?&]command=(deleteClient|deleteBan|clearOverviewLogs)(&|$)/.test(settings.url || '')) {
			window.location.reload();
		}
	});

	<?php if ($oIsUser): ?>
	// Counted after the page is up: it is a scan of the call history. On
	// ready, because orykPost() is defined by views/admin.php, below this.
	$(function () {
		orykPost('countOverviewHistory', { scope: orykOverviewScope }).done(function (response) {
			const cell = $('#oryk_overview_history');

			if (!response || !response.status || !response.available) {
				cell.html('<span class="text-muted">No call history to read</span>');
				return;
			}

			const calls = Number(response.calls) || 0;
			const recordings = Number(response.recordings) || 0;

			cell.text(`${calls} call${calls === 1 ? '' : 's'}, ${recordings} recording${recordings === 1 ? '' : 's'}`);
		}).fail(function () {
			$('#oryk_overview_history').html('<span class="text-muted">Could not be counted</span>');
		});
	});
	<?php endif; ?>

	$(document).on('click', '#oryk_overview_stored', function () {
		if (!window.confirm('Delete the stored phone logs? The files these clients have sent are removed from the PBX.')) {
			return;
		}

		const button = $(this).prop('disabled', true);

		orykPost('clearOverviewStored', { scope: orykOverviewScope }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			window.location.reload();
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not delete.', 4);
		});
	});

	// Delete All, drawn by Pages::getActionBar().
	$(document).on('click', '#orykpurge', function (event) {
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
