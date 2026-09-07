<?php
/**
 * The module page: a Devices tab and a Profiles tab.
 *
 * Both tables are filled by the module's AJAX commands, so nothing on this
 * page is rendered from data: what it is handed is which tab to open and
 * which row was just written, so it can say so.
 *
 * Neither a device nor a profile is edited here. Both are pages of their own
 * -- views/device.php and views/profile.php -- which the Add and Edit buttons
 * link to. What is left on the list is deletion, which needs no page.
 *
 * @var string $tab   Tab to open on: devices|profiles
 * @var int    $saved Row just written on that tab, highlighted here
 */

$tab = ($tab ?? '') === 'profiles' ? 'profiles' : 'devices';
$saved = (int) ($saved ?? 0);

// One `saved` in the URL, and the tab it arrives on says which table it means:
// each editor comes back to its own tab, so there is never a saved device and
// a saved profile to tell apart.
$savedDevice = $tab === 'devices' ? $saved : 0;
$savedProfile = $tab === 'profiles' ? $saved : 0;
?>
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

			<div class="section-title">
				<h2>
					<span class="title">
						Provisioner
					</span>
				</h2>
			</div>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<ul class="nav nav-tabs" role="tablist">
					<li role="presentation" class="<?php echo $tab === 'devices' ? 'active' : ''; ?>">
						<a href="#oryk_devices" aria-controls="oryk_devices" role="tab" data-toggle="tab">
							<?php echo _('Devices'); ?>
						</a>
					</li>
					<li role="presentation" class="<?php echo $tab === 'profiles' ? 'active' : ''; ?>">
						<a href="#oryk_profiles" aria-controls="oryk_profiles" role="tab" data-toggle="tab">
							<?php echo _('Profiles'); ?>
						</a>
					</li>
				</ul>

				<div class="tab-content">

					<div role="tabpanel" class="tab-pane <?php echo $tab === 'devices' ? 'active' : ''; ?>" id="oryk_devices">
						<div id="device_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;device=">
								<i class="fa fa-plus"></i> <?php echo _('Add Device'); ?>
							</a>
						</div>

						<table
							id="device_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listDevices"
							data-toolbar="#device_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-unique-id="id"
							data-row-style="formatDeviceRow"
							data-sort-name="mac"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="mac" data-formatter="formatMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
									<th data-field="device_id" data-formatter="formatDevice" data-sortable="true"><?php echo _('Device'); ?></th>
									<th data-field="device_extension" data-formatter="formatExtension" data-sortable="true"><?php echo _('Extension'); ?></th>
									<th data-field="description" data-formatter="formatText" data-sortable="true"><?php echo _('Description'); ?></th>
									<th data-field="profile" data-formatter="formatDeviceProfile" data-sortable="true"><?php echo _('Device Profile'); ?></th>
									<th data-field="actions" data-formatter="formatDeviceActions"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
					</div>

					<div role="tabpanel" class="tab-pane <?php echo $tab === 'profiles' ? 'active' : ''; ?>" id="oryk_profiles">
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
							data-unique-id="id"
							data-row-style="formatProfileRow"
							data-sort-name="name"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="name" data-formatter="formatProfileName" data-sortable="true" ><?php echo _('Name'); ?></th>
									<th data-field="assigned" data-sortable="true"><?php echo _('Assigned Devices'); ?></th>
									<th data-field="actions" data-formatter="formatProfileActions"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
					</div>

				</div>

			</div>

		</div>
	</div>
</div>

<script>

	const orykAjax = 'ajax.php?module=oryk_provisioner&command=';

	// The row each editor has just written, so the one it landed on can say so
	// rather than the page looking unchanged after coming back.
	const orykSavedDevice = <?php echo $savedDevice; ?>;
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

	function formatMac(value) {
		return value ? `<code>${orykEscape(value)}</code>` : '-';
	}

	// The device column names the FreePBX device; the extension it is attached
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

	function formatDeviceProfile(value, row) {
		if (!value) {
			return '-';
		}
		return `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.profile_id)}">${value}</a>`;
	}

	function formatProfileName(value, row) {
		if (!value) {
			return '-';
		}
		return `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.id)}">${value}</a>`;
	}

	// Editing an association is a page, not a dialog, so Edit is a link: the
	// row's id is the whole of what the editor needs, and it reads the
	// association back itself rather than being handed one.
	//
	// Config is a link for a different reason: the rendered configuration is a
	// page of plain text at its own URL, the same one a phone will be given,
	// so it opens in a tab instead of being fetched back into this one. A row
	// with no profile has nothing to render, so it does not offer it.
	function formatDeviceActions(value, row) {
		const actions = [
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&device=${encodeURIComponent(row.id)}">Edit</a>`
		];

		actions.push(`<button type="button" class="btn btn-danger btn-sm" name="device_delete" value="${row.id}"><i class="fa fa-trash" style="margin: 0;"></i></button>`);

		if (row.profile_id) {
			const url = `/provisioner/${encodeURIComponent(row.mac)}.cfg`;
			actions.push(`<a class="btn btn-default btn-sm" href="${url}" target="_blank" title="View the rendered configuration">Render</a>`);
		}

		return `<div class="flex gap-3">${actions.join('')}</div>`;
	}

	function formatDeviceRow(row) {
		return orykSavedDevice && Number(row.id) === orykSavedDevice ? { classes: 'success' } : {};
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

	// A table drawn while its tab is hidden has no width to lay itself out
	// against, so it is told to measure again once the tab is on screen. The
	// URL is kept in step at the same time -- the way the profile editor keeps
	// `&tab=` in step with the tab it has open -- so a reload, a bookmark or a
	// link back here all come back to the tab that was open. Both tabs name
	// themselves rather than one of them being the bare URL: neither is the
	// other's default, and `?display=oryk_provisioner` on its own still opens
	// Devices.
	$(document).on('shown.bs.tab', 'a[data-toggle="tab"]', function () {
		const pane = $(this).attr('href');

		$(pane).find('table[data-toggle="table"]').bootstrapTable('resetView');

		if (window.history && window.history.replaceState) {
			const tabs = { '#oryk_devices': 'devices', '#oryk_profiles': 'profiles' };
			window.history.replaceState(null, '', `?display=oryk_provisioner&tab=${tabs[pane] || 'devices'}`);
		}
	});

	// Deleting is the one action on either tab that needs no page of its own.

	$(document).on('click', '[name="device_delete"]', function () {
		if (!window.confirm('Delete this device association?')) {
			return;
		}

		orykPost('deleteDevice', { id: $(this).val() }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			$('#device_table').bootstrapTable('refresh');
			$('#profile_table').bootstrapTable('refresh');
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
			notie.alert(1, 'Deleted.', 2);
		});
	});

</script>
