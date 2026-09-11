<?php
/**
 * The module page: a Clients tab and a Profiles tab.
 *
 * Both tables are filled by the module's AJAX commands, so nothing on this
 * page is rendered from data: what it is handed is which tab to open and
 * which row was just written, so it can say so.
 *
 * Neither a client nor a profile is edited here. Both are pages of their own
 * -- views/client.php and views/profile.php -- which the Add and Edit buttons
 * link to. What is left on the list is deletion, which needs no page.
 *
 * Every tab is a link and only the tab asked for is rendered -- see
 * partials/tabs.php. Both tabs name themselves rather than one of them being
 * the bare URL: neither is the other's default, though a bare
 * ?display=oryk_provisioner still opens Clients.
 *
 * @var string             $tab    Tab to open on: clients|profiles|logs
 * @var int                $saved  Row just written on that tab, highlighted here
 * @var array<string, int> $counts Rows behind each tab -- see partials/counts.php
 * @var array<int, array<string, mixed>>  $navigator Levels the navigator draws -- see partials/navigator.php
 */

$tab = in_array($tab ?? '', ['profiles', 'logs'], true) ? $tab : 'clients';
$saved = (int) ($saved ?? 0);

// Nothing above this page narrows them: every client, every profile, every
// request the endpoint has answered.
$countScope = [];

// One `saved` in the URL, and the tab it arrives on says which table it means:
// each editor comes back to its own tab, so there is never a saved client and
// a saved profile to tell apart.
$savedClient = $tab === 'clients' ? $saved : 0;
$savedProfile = $tab === 'profiles' ? $saved : 0;

// The strip, as links. Nothing on this page is ever behind a tab that cannot
// be opened: an empty table is still a table, and Add lives on it.
$tabs = [
	'clients' => [
		'label' => _('Clients'),
		'href' => '?display=oryk_provisioner&tab=clients',
		'count' => 'clients',
	],
	'profiles' => [
		'label' => _('Profiles'),
		'href' => '?display=oryk_provisioner&tab=profiles',
		'count' => 'profiles',
	],
	'logs' => [
		'label' => _('Logs'),
		'href' => '?display=oryk_provisioner&tab=logs',
		'count' => 'logs',
	],
];
?>
<?php include __DIR__ . '/partials/counts.php'; ?>
<style>
	.flex {
		display: flex;
	}
	.gap-3 {
		gap: 3px;
	}
</style>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<?php include __DIR__ . '/partials/navigator.php'; ?>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<?php include __DIR__ . '/partials/tabs.php'; ?>

				<div class="tab-content">

					<?php if ($tab === 'clients'): ?>
					<div class="tab-pane active" id="oryk_clients">
						<div id="client_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;client=">
								<i class="fa fa-plus"></i> <?php echo _('Add Client'); ?>
							</a>
						</div>

						<table
							id="client_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listClients"
							data-toolbar="#client_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="id"
							data-row-style="formatClientRow"
							data-sort-name="mac"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="mac" data-formatter="formatMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
									<th data-field="description" data-formatter="formatText" data-sortable="true"><?php echo _('Description'); ?></th>
									<th data-field="device_id" data-formatter="formatDevice" data-sortable="true"><?php echo _('Device'); ?></th>
									<th data-field="device_extension" data-formatter="formatExtension" data-sortable="true"><?php echo _('Extension'); ?></th>
									<th data-field="profile" data-formatter="formatClientProfile" data-sortable="true"><?php echo _('Profile'); ?></th>
									<th data-field="secure" data-formatter="formatClientSecure" data-sortable="true"><?php echo _('Secure'); ?></th>
									<th data-field="actions" data-formatter="formatClientActions"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
					</div>

					<?php endif; ?>

					<?php if ($tab === 'profiles'): ?>
					<div class="tab-pane active" id="oryk_profiles">
						<div id="profile_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;profile=">
								<i class="fa fa-plus"></i> <?php echo _('Add Profile'); ?>
							</a>
						</div>

						<table
							id="profile_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listProfiles"
							data-toolbar="#profile_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="id"
							data-row-style="formatProfileRow"
							data-sort-name="name"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="name" data-formatter="formatProfileName" data-sortable="true" ><?php echo _('Name'); ?></th>
									<th data-field="assigned" data-formatter="formatAssignedClients" data-sortable="true"><?php echo _('Clients'); ?></th>
									<th data-field="actions" data-formatter="formatProfileActions"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
					</div>

					<?php endif; ?>

					<?php if ($tab === 'logs'): ?>
					<div class="tab-pane active" id="oryk_logs">
						<?php
						// Every request, not just the ones a client was found
						// for -- see the note at the top of the partial.
						$logMac = '';
						include __DIR__ . '/partials/logs.php';
						?>
					</div>
					<?php endif; ?>

				</div>

			</div>

		</div>
	</div>
</div>

<script>

	const orykAjax = 'ajax.php?module=oryk_provisioner&command=';

	// The row each editor has just written, so the one it landed on can say so
	// rather than the page looking unchanged after coming back.
	const orykSavedClient = <?php echo $savedClient; ?>;
	const orykSavedProfile = <?php echo $savedProfile; ?>;

	// Every call to the module is a POST to ajax.php with the command in the
	// query string, which is what FreePBX dispatches on.
	function orykPost(command, data) {
		return $.ajax({
			url: orykAjax + command,
			type: 'POST',
			data: data,
			dataType: 'json'
		});
	}

	function orykEscape(value) {
		return $('<div>').text(value === null || value === undefined ? '' : value).html();
	}

	function formatText(value) {
		return value ? orykEscape(value) : '-';
	}

	function formatMac(value, row) {
		return value ? `<a href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>` : '-';
	}

	// The Device column names the FreePBX device; the extension it is attached
	// to is shown alongside it when there is one, and links to that extension.
	function formatDevice(value, row) {
		if (!value) {
			return '-';
		}

		const device = orykEscape(value);

		return `<a href="?display=devices&extdisplay=${encodeURIComponent(device)}">${device}</a>`;
	}

	function formatExtension(value, row) {
		if (!row.extension) {
			return '-';
		}

		const extension = orykEscape(row.extension);

		return `<a href="?display=extensions&extdisplay=${encodeURIComponent(row.extension)}">${extension}</a>`;
	}

	function formatClientProfile(value, row) {
		if (!value) {
			return '-';
		}
		return `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.profile_id)}">${value}</a>`;
	}

	// Whether the client has a token, which the row is told as a flag rather
	// than by being handed the hash. MySQL answers the comparison with 1 or 0
	// and PDO brings it back as a string, so the truthiness test is on the
	// number rather than on the value as it arrives -- '0' is true.
	function formatClientSecure(value, row) {
		return Number(value) ? 'Yes' : 'No';
	}

	function formatProfileName(value, row) {
		if (!value) {
			return '-';
		}
		return `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.id)}">${value}</a>`;
	}

	function formatAssignedClients(value, row) {
		if (!value) {
			return '0';
		}
		return `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.id)}&tab=clients">${orykEscape(value)}</a>`;
	}

	// Editing a client is a page, not a dialog, so Edit is a link: the
	// row's id is the whole of what the editor needs, and it reads the
	// client back itself rather than being handed one.
	//
	// Config is a link for a different reason: the rendered configuration is a
	// page of plain text at its own URL, the same one a phone will be given,
	// so it opens in a tab instead of being fetched back into this one. A row
	// with no profile has nothing to render, so it does not offer it.
	function formatClientActions(value, row) {
		const actions = [
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">Edit</a>`
		];

		actions.push(`<button type="button" class="btn btn-danger btn-sm" name="client_delete" value="${row.id}"><i class="fa fa-trash" style="margin: 0;"></i></button>`);

		return `<div class="flex gap-3">${actions.join('')}</div>`;
	}

	function formatClientRow(row) {
		return orykSavedClient && Number(row.id) === orykSavedClient ? { classes: 'success' } : {};
	}

	// Editing a profile is a page too, and for the same reason.
	function formatProfileActions(value, row) {
		return [
			`<div class="flex gap-3">`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&profile=${encodeURIComponent(row.id)}">Edit</a>`,
			`<button type="button" class="btn btn-danger btn-sm" name="profile_delete" value="${row.id}"><i class="fa fa-trash" style="margin: 0;"></i></button>`,
			`</div>`
		].join('');
	}

	function formatProfileRow(row) {
		return orykSavedProfile && Number(row.id) === orykSavedProfile ? { classes: 'success' } : {};
	}

	// Deleting is the one action on either tab that needs no page of its own.
	//
	// Only one table is on the page -- the tab that was asked for is the only
	// pane rendered -- so only that one is refreshed. What a delete changes on
	// the other tab is its badge, and the badges are re-read whole.

	$(document).on('click', '[name="client_delete"]', function () {
		if (!window.confirm('Delete this client? Any logs it has sent go with it.')) {
			return;
		}

		orykPost('deleteClient', { id: $(this).val() }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			$('#client_table').bootstrapTable('refresh');
			orykCounts();
			notie.alert(1, 'Deleted.', 2);
		});
	});

	$(document).on('click', '[name="profile_delete"]', function () {
		if (!window.confirm('Delete this profile? Its resources go with it.')) {
			return;
		}

		orykPost('deleteProfile', { id: $(this).val() }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			$('#profile_table').bootstrapTable('refresh');
			orykCounts();
			notie.alert(1, 'Deleted.', 2);
		});
	});

</script>
