<?php
/**
 * The module page: a Devices tab and a Profiles tab.
 *
 * Both tables are filled by the module's AJAX commands; the only thing
 * rendered here is what the device dialog offers as choices.
 *
 * A device association is small enough to stay in a dialog. A profile is not:
 * it carries a block of configuration text, so it has a page of its own,
 * views/profile.php, which the Add and Edit buttons here link to.
 *
 * @var array<int, array<string, mixed>> $freepbxDevices
 * @var array<int, array<string, mixed>> $profiles
 * @var string                           $tab    Tab to open on: devices|profiles
 * @var int                              $saved  Profile just written, highlighted here
 */

$freepbxDevices = $freepbxDevices ?? [];
$profiles = $profiles ?? [];
$tab = ($tab ?? '') === 'profiles' ? 'profiles' : 'devices';
$saved = (int) ($saved ?? 0);
?>
<style>
	.flex {
		display: flex;
	}
	.gap-3 {
		gap: 3px;
	}
	.oryk-toolbar {
		padding-bottom: 5px;
	}
</style>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display no-border">

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
						<button type="button" class="btn btn-primary" id="device_add">
							<i class="fa fa-plus"></i> <?php echo _('Add Device'); ?>
						</button>
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
						data-sort-name="mac"
						data-sort-order="asc">
						<thead>
							<tr>
								<th data-field="mac" data-formatter="formatMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
								<th data-field="device_id" data-formatter="formatDevice" data-sortable="true"><?php echo _('FreePBX Device / Extension'); ?></th>
								<th data-field="description" data-formatter="formatText" data-sortable="true"><?php echo _('Description'); ?></th>
								<th data-field="profile" data-formatter="formatText" data-sortable="true"><?php echo _('Device Profile'); ?></th>
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
								<th data-field="name" data-formatter="formatText" data-sortable="true"><?php echo _('Name'); ?></th>
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

<div class="modal fade" id="device_modal" tabindex="-1" role="dialog">
	<div class="modal-dialog" role="document">
		<div class="modal-content">
			<!-- Not a form: the module page is itself inside a FreePBX form, and a
			     nested one is dropped by the browser, which leaves the fields
			     submitting the page instead. The values are read by id and
			     posted to ajax.php. -->
			<div id="device_form">
				<input type="hidden" id="device_row_id" value="">

				<div class="modal-header">
					<button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
					<h4 class="modal-title" id="device_modal_title"><?php echo _('Add Device'); ?></h4>
				</div>

				<div class="modal-body">
					<div class="alert alert-danger hidden" id="device_error"></div>

					<div class="form-group">
						<label class="control-label" for="device_mac">
							<?php echo _('MAC Address'); ?>
							<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
						</label>
						<input type="text" class="form-control" id="device_mac"
							placeholder="001565AABBCC" autocomplete="off">
						<span class="help-block"><?php echo _('Stored as 12 uppercase hexadecimal characters; separators are removed.'); ?></span>
					</div>

					<div class="form-group">
						<label class="control-label" for="device_device_id"><?php echo _('FreePBX Device'); ?></label>
						<select class="form-control" id="device_device_id">
							<option value=""><?php echo _('None'); ?></option>
							<?php foreach ($freepbxDevices as $device): ?>
								<option value="<?php echo htmlspecialchars((string) $device['id']); ?>">
									<?php
									echo htmlspecialchars(trim(
										$device['id']
										. (($device['description'] ?? '') !== '' ? ' - ' . $device['description'] : '')
										. (($device['tech'] ?? '') !== '' ? ' (' . $device['tech'] . ')' : '')
									));
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<div class="form-group">
						<label class="control-label" for="device_profile_id"><?php echo _('Device Profile'); ?></label>
						<select class="form-control" id="device_profile_id">
							<option value=""><?php echo _('None'); ?></option>
							<?php foreach ($profiles as $profile): ?>
								<option value="<?php echo (int) $profile['id']; ?>">
									<?php echo htmlspecialchars((string) $profile['name']); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
				</div>

				<div class="modal-footer">
					<button type="button" class="btn btn-default" data-dismiss="modal"><?php echo _('Cancel'); ?></button>
					<button type="button" class="btn btn-primary" id="device_save"><?php echo _('Save'); ?></button>
				</div>
			</div>
		</div>
	</div>
</div>

<script>

	const orykAjax = 'ajax.php?module=oryk_provisioner&command=';

	// The profile just written by the editor, so the row it landed on can say
	// so rather than the page looking unchanged after coming back.
	const orykSavedProfile = <?php echo $saved; ?>;

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

		if (!row.extension) {
			return device;
		}

		const extension = orykEscape(row.extension);

		return `${device} <a href="?display=extensions&extdisplay=${encodeURIComponent(row.extension)}">(${extension})</a>`;
	}

	// Config is a link rather than a button: the rendered configuration is a
	// page of plain text at its own URL, the same one a phone will be given,
	// so it opens in a tab instead of being fetched back into this one. A row
	// with no profile has nothing to render, so it does not offer it.
	function formatDeviceActions(value, row) {
		const actions = [
			`<button type="button" class="btn btn-primary btn-sm" name="device_edit" value="${row.id}">Edit</button>`
		];

		if (row.profile_id) {
			const url = `?display=oryk_provisioner&mac=${encodeURIComponent(row.mac)}&config=`;
			actions.push(`<a class="btn btn-default btn-sm" href="${url}" target="_blank" title="View the rendered configuration">Config</a>`);
		}

		actions.push(`<button type="button" class="btn btn-danger btn-sm" name="device_delete" value="${row.id}"><i class="fa fa-trash"></i></button>`);

		return `<div class="flex gap-3">${actions.join('')}</div>`;
	}

	// Editing a profile is a page, not a dialog, so Edit is a link: the row's
	// id is the whole of what the editor needs, and it reads the profile back
	// itself rather than being handed one.
	function formatProfileActions(value, row) {
		return [
			`<div class="flex gap-3">`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&profile=${encodeURIComponent(row.id)}">Edit</a>`,
			`<button type="button" class="btn btn-danger btn-sm" name="profile_delete" value="${row.id}"><i class="fa fa-trash"></i></button>`,
			`</div>`
		].join('');
	}

	function formatProfileRow(row) {
		return orykSavedProfile && Number(row.id) === orykSavedProfile ? { classes: 'success' } : {};
	}

	// A table drawn while its tab is hidden has no width to lay itself out
	// against, so it is told to measure again once the tab is on screen.
	$(document).on('shown.bs.tab', 'a[data-toggle="tab"]', function () {
		$($(this).attr('href')).find('table[data-toggle="table"]').bootstrapTable('resetView');
	});

	function orykShowError($box, message) {
		$box.text(message || 'Something went wrong.').removeClass('hidden');
	}

	// The page is rendered inside the FreePBX page form. The dialog is moved
	// out to the end of the document so nothing in it belongs to that form,
	// and so the backdrop sits behind it.
	$(function () {
		$('#device_modal').appendTo('body');
	});

	// Devices

	$(document).on('click', '#device_add', function () {
		$('#device_error').addClass('hidden').text('');
		$('#device_modal_title').text('Add Device');
		$('#device_row_id').val('');
		$('#device_mac').val('');
		$('#device_device_id').val('');
		$('#device_profile_id').val('');
		$('#device_modal').modal('show');
	});

	$(document).on('click', '[name="device_edit"]', function () {
		orykPost('getDevice', { id: $(this).val() }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Device not found.', 4);
				return;
			}

			$('#device_error').addClass('hidden').text('');
			$('#device_modal_title').text('Edit Device');
			$('#device_row_id').val(response.device.id);
			$('#device_mac').val(response.device.mac);
			$('#device_device_id').val(response.device.device_id || '');
			$('#device_profile_id').val(response.device.profile_id || '');
			$('#device_modal').modal('show');
		});
	});

	$(document).on('click', '#device_save', function () {
		orykPost('saveDevice', {
			id: $('#device_row_id').val(),
			mac: $('#device_mac').val(),
			device_id: $('#device_device_id').val(),
			profile_id: $('#device_profile_id').val()
		}).done(function (response) {
			if (!response || !response.status) {
				orykShowError($('#device_error'), response && response.message);
				return;
			}

			$('#device_modal').modal('hide');
			$('#device_table').bootstrapTable('refresh');
			$('#profile_table').bootstrapTable('refresh');
			notie.alert(1, 'Saved.', 2);
		}).fail(function () {
			orykShowError($('#device_error'), 'The server could not be reached.');
		});
	});

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

	// Profiles
	//
	// Adding and editing are views/profile.php; what is left here is the one
	// action that needs no page of its own.

	$(document).on('click', '[name="profile_delete"]', function () {
		const id = $(this).val();

		if (!window.confirm('Delete this profile?')) {
			return;
		}

		orykPost('deleteProfile', { id: id }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			$('#device_profile_id').find(`option[value="${id}"]`).remove();
			$('#profile_table').bootstrapTable('refresh');
			notie.alert(1, 'Deleted.', 2);
		});
	});

</script>
