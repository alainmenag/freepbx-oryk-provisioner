<?php
/**
 * views/profile.php -- one profile, over two tabs.
 *
 * Reached at ?display=oryk_provisioner&profile=<id> to edit an existing
 * profile, or ?display=oryk_provisioner&profile= (present, empty) to write a
 * new one. `profile` present but empty is deliberate rather than a degenerate
 * case: it is the same page doing the same thing, minus a row to replace.
 *
 * Profile is the main config -- the name and the one template every profile
 * has. Resources are the other files a phone asks that profile for, each its
 * own page at &resource=<id>, for the reason a profile is its own page: a
 * block of configuration text wants room and a monospace column. Devices is
 * who this is all for: the associations assigned to the profile, the same
 * table the module page draws, narrowed to this one.
 *
 * Tabs rather than pages because they are one thing being edited. Resources
 * and Devices are both inert until the profile has been saved -- a resource
 * hangs off a profile_id, and nothing can have been assigned to a profile
 * that has never been written.
 *
 * Save, Delete and Close are the action bar's, drawn by FreePBX from
 * getActionBar() and bound by views/partials/editor.php.
 *
 * @var array<string, mixed>                 $profile      id (0 when new), name, template
 * @var int                                  $assigned     Devices using it, for the tab's count
 * @var array<string, array<string, string>> $placeholders What a template can refer to
 * @var string                               $tab          Tab to open on: profile|resources|devices
 * @var int                                  $saved        Resource just written, highlighted here
 */

$profile = $profile ?? ['id' => 0, 'name' => '', 'template' => ''];
$assigned = (int) ($assigned ?? 0);
$placeholders = $placeholders ?? [];
$tab = in_array($tab ?? '', ['resources', 'devices'], true) ? $tab : 'profile';
$saved = (int) ($saved ?? 0);

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$id = (int) $profile['id'];
$isNew = $id === 0;

// A new profile has nothing for a resource to belong to and nothing assigned
// to it, so those tabs are there but do not open: hidden, they would look
// like features this profile does not have rather than ones it does not have
// yet.
$tab = $isNew ? 'profile' : $tab;
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<div class="section-title">
				<h2>
					<span class="title">
						<a class="title" href="?display=oryk_provisioner&tab=profiles">Provisioner</a>
						<span>:: Profile</span>
						<?php if (isset($profile['name'])): ?>
							<code><?php echo $h($profile['name']); ?></code>
						<?php endif; ?>
					</span>
				</h2>
			</div>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<ul class="nav nav-tabs" role="tablist">
					<li role="presentation" class="<?php echo $tab === 'profile' ? 'active' : ''; ?>">
						<a href="#oryk_profile" aria-controls="oryk_profile" role="tab" data-toggle="tab">
							<?php echo _('Profile'); ?>
						</a>
					</li>
					<li role="presentation" class="<?php echo $tab === 'resources' ? 'active' : ($isNew ? 'disabled' : ''); ?>">
						<?php if ($isNew): ?>
							<a href="#" title="<?php echo _('Save the profile first -- a resource belongs to one.'); ?>"
								onclick="return false;">
								<?php echo _('Resources'); ?>
							</a>
						<?php else: ?>
							<a href="#oryk_resources" aria-controls="oryk_resources" role="tab" data-toggle="tab">
								<?php echo _('Resources'); ?>
							</a>
						<?php endif; ?>
					</li>
					<li role="presentation" class="<?php echo $tab === 'devices' ? 'active' : ($isNew ? 'disabled' : ''); ?>">
						<?php if ($isNew): ?>
							<a href="#" title="<?php echo _('Save the profile first -- nothing can be assigned to one that has not been written.'); ?>"
								onclick="return false;">
								<?php echo _('Devices'); ?>
							</a>
						<?php else: ?>
							<a href="#oryk_devices" aria-controls="oryk_devices" role="tab" data-toggle="tab">
								<?php echo _('Devices'); ?>
								<span class="badge"><?php echo $assigned; ?></span>
							</a>
						<?php endif; ?>
					</li>
				</ul>

				<div class="tab-content">

					<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'profile' ? 'active' : ''; ?>" id="oryk_profile">

						<!-- Not a form: see the note in partials/editor.php. -->
						<input type="hidden" id="profile_row_id" value="<?php echo $id; ?>">

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="profile_name">
											<?php echo _('Name'); ?>
											<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
										</label>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control" id="profile_name"
											autocomplete="off" placeholder="<?php echo _('Yealink T54W'); ?>"
											value="<?php echo $h($profile['name']); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('How the profile is named in the device list. Must be unique.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="profile_template"><?php echo _('Template'); ?></label>
									</div>
									<div class="col-md-8">
										<textarea class="form-control oryk-template" id="profile_template"
											rows="6" spellcheck="false" wrap="off">
<?php echo $h($profile['template']); ?></textarea>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('The main configuration file, served for [mac].cfg. Stored as typed. Names in double braces are replaced when a device asks for its configuration; a name nothing answers to is replaced with nothing.'); ?>
									</span>
									<?php include __DIR__ . '/partials/placeholders.php'; ?>
								</div>
							</div>
						</div>

					</div>

					<?php if (!$isNew): ?>
						<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'resources' ? 'active' : ''; ?>" id="oryk_resources">

							<p class="help-block fpbx-help-block">
								<?php echo _('Files a client asks this profile for -- .cfg, [mac]-phone.cfg, [mac]-web.cfg. ** Firmware is not yet supported.'); ?>
							</p>

							<div id="resource_toolbar" class="oryk-toolbar">
								<a class="btn btn-primary" href="?display=oryk_provisioner&amp;profile=<?php echo $id; ?>&amp;resource=">
									<i class="fa fa-plus"></i> <?php echo _('Add Resource'); ?>
								</a>
							</div>

							<table
								id="resource_table"
								data-toggle="table"
								data-url="ajax.php?module=oryk_provisioner&command=listResources&profile_id=<?php echo $id; ?>"
								data-toolbar="#resource_toolbar"
								class="table table-striped"
								data-side-pagination="server"
								data-pagination="true"
								data-search="true"
								data-unique-id="id"
								data-row-style="formatResourceRow"
								data-sort-name="name"
								data-sort-order="asc">
								<thead>
									<tr>
										<th data-field="name" data-formatter="formatResourceName" data-sortable="true"><?php echo _('Filename'); ?></th>
										<th data-field="updated_at" data-formatter="formatResourceText" data-sortable="true"><?php echo _('Updated'); ?></th>
										<th data-field="actions" data-formatter="formatResourceActions"><?php echo _('Actions'); ?></th>
									</tr>
								</thead>
							</table>

						</div>

						<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'devices' ? 'active' : ''; ?>" id="oryk_devices">

							<p class="help-block fpbx-help-block">
								<?php echo _('Devices assigned to this profile.'); ?>
							</p>

							<table
								id="device_table"
								data-toggle="table"
								data-url="ajax.php?module=oryk_provisioner&command=listDevices&profile_id=<?php echo $id; ?>"
								class="table table-striped"
								data-side-pagination="server"
								data-pagination="true"
								data-search="true"
								data-unique-id="id"
								data-sort-name="mac"
								data-sort-order="asc">
								<thead>
									<tr>
										<th data-field="mac" data-formatter="formatDeviceMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
										<th data-field="device_id" data-formatter="formatDevice" data-sortable="true"><?php echo _('Device'); ?></th>
										<th data-field="device_extension" data-formatter="formatExtension" data-sortable="true"><?php echo _('Extension'); ?></th>
										<th data-field="description" data-formatter="formatDeviceText" data-sortable="true"><?php echo _('Description'); ?></th>
										<th data-field="actions" data-formatter="formatDeviceActions"><?php echo _('Actions'); ?></th>
									</tr>
								</thead>
							</table>

						</div>
					<?php endif; ?>

				</div>

			</div>
		</div>
	</div>
</div>

<script>

	const orykProfileId = <?php echo $id; ?>;
	const orykList = '?display=oryk_provisioner&tab=profiles';

	// The resource just written by its editor, so the row it landed on says so
	// rather than the tab looking unchanged after coming back to it.
	const orykSavedResource = <?php echo $saved; ?>;

	function formatResourceText(value) {
		return value ? orykEscape(value) : '-';
	}

	function formatResourceName(value, row) {
		return value ? `<a href="?display=oryk_provisioner&profile=${orykProfileId}&resource=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>` : '-';
	}

	// Editing a resource is a page, not a dialog, for the reason editing a
	// profile is: the row's id is the whole of what the editor needs, and it
	// reads the resource back itself rather than being handed one.
	function formatResourceActions(value, row) {
		return [
			`<div class="flex gap-3">`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&profile=${orykProfileId}&resource=${encodeURIComponent(row.id)}">Edit</a>`,
			`<button type="button" class="btn btn-danger btn-sm" name="resource_delete" value="${row.id}"><i class="fa fa-trash" style="margin: 0;"></i></button>`,
			`</div>`
		].join('');
	}

	function formatResourceRow(row) {
		return orykSavedResource && Number(row.id) === orykSavedResource ? { classes: 'success' } : {};
	}

	function formatDeviceText(value) {
		return value ? orykEscape(value) : '-';
	}

	function formatDeviceMac(value, row) {
		return value ? `<a href="?display=oryk_provisioner&device=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>` : '-';
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

	// Edit is the association's own page, the same link the list draws: an
	// association is one row with one editor, wherever it is reached from,
	// and that editor is also where a device is moved to another profile and
	// so off this tab. Config is the URL a phone is given; every row here has
	// a profile by definition, so every row has one.
	function formatDeviceActions(value, row) {
		return [
			`<div class="flex gap-3">`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&device=${encodeURIComponent(row.id)}">Edit</a>`,
			`<a class="btn btn-default btn-sm" href="/provisioner/${encodeURIComponent(row.mac)}.cfg" target="_blank" title="View the rendered configuration">Render</a>`,
			`</div>`
		].join('');
	}

	// A table drawn while its tab is hidden has no width to lay itself out
	// against, so it is told to measure again once the tab is on screen. The
	// URL is kept in step at the same time, so a reload -- and the Add
	// Resource link on the tab -- come back to the tab that is open.
	$(document).on('shown.bs.tab', 'a[data-toggle="tab"]', function () {
		const pane = $(this).attr('href');

		$(pane).find('table[data-toggle="table"]').bootstrapTable('resetView');

		if (window.history && window.history.replaceState) {
			const tabs = { '#oryk_resources': '&tab=resources', '#oryk_devices': '&tab=devices' };
			const tab = tabs[pane] || '';
			window.history.replaceState(null, '', `?display=oryk_provisioner&profile=${orykProfileId}${tab}`);
		}
	});

	// Deleting a resource is the one action that needs no page of its own.
	$(document).on('click', '[name="resource_delete"]', function () {
		if (!window.confirm('Delete this resource?')) {
			return;
		}

		orykPost('deleteResource', { id: $(this).val() }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			$('#resource_table').bootstrapTable('refresh');
			notie.alert(1, 'Deleted.', 2);
		});
	});

	orykEditor({
		save: 'saveProfile',
		remove: 'deleteProfile',
		confirm: 'Delete this profile? Its resources go with it.',
		values: function () {
			return {
				id: $('#profile_row_id').val(),
				name: $('#profile_name').val(),
				template: $('#profile_template').val()
			};
		},
		// The list is re-rendered on arrival, so the saved profile is in
		// its table without anything here having to put it there.
		saved: function (response) {
			return orykList + '&saved=' + encodeURIComponent(response.id);
		},
		closed: orykList
	});

</script>
