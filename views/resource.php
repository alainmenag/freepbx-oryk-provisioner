<?php
/**
 * views/resource.php -- one resource of one profile, over two tabs.
 *
 * Reached at ?display=oryk_provisioner&profile=<id>&resource=<id>, or with
 * `resource` present and empty to write a new one -- the same shape the
 * profile editor has, one level down.
 *
 * A resource is a profile's other files: the phone asks for [mac]-phone.cfg
 * or [mac]-directory.xml alongside its main config, and this is what it gets.
 * Which makes it a profile minus the parts a profile has because devices are
 * assigned to it, which is why the two views share partials/editor.php and
 * differ in little more than their two fields.
 *
 * Devices is who this file is served to: the profile's own device table,
 * asked for again with this resource's id, so each row can say which filename
 * that device asks this resource for. A resource's name is a template, so
 * that filename is a different string per device -- which is why a resource
 * has no one preview URL of its own and why the preview belongs here, on the
 * device row, one link per phone.
 *
 * The bare ?profile=<id>&resource=<id> *is* the Resource tab, the way
 * ?profile=<id> is the profile editor's first tab; Devices names itself with
 * &tab=devices, and the shown.bs.tab handler keeps the address in step.
 *
 * @var array<string, mixed>                 $resource     id (0 when new), profile_id, name, template
 * @var array<string, mixed>                 $profile      The profile it belongs to
 * @var array<string, array<string, string>> $placeholders What a template can refer to
 * @var int                                  $assigned     Devices on the profile, for the tab's count
 * @var string                               $tab          Tab to open on: resource|devices
 */

$resource = $resource ?? ['id' => 0, 'profile_id' => 0, 'name' => '', 'template' => ''];
$profile = $profile ?? ['id' => 0, 'name' => ''];
$placeholders = $placeholders ?? [];
$assigned = (int) ($assigned ?? 0);
$tab = ($tab ?? '') === 'devices' ? 'devices' : 'resource';

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$id = (int) $resource['id'];
$profileId = (int) $profile['id'];
$isNew = $id === 0;

// A resource that has never been written has no name to render against a
// device, so its Devices tab is there but does not open -- hidden, it would
// look like something a resource does not have rather than something this one
// does not have yet.
$tab = $isNew ? 'resource' : $tab;
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
						<a class="title" href="?display=oryk_provisioner&profile=<?php echo $profileId; ?>&tab=resources">
							<span><?php echo $h($profile['name']); ?></span>
						</a>
						<span>:: Resource</span>
						<?php if (isset($resource['name'])): ?>
							<code><?php echo $h($resource['name']); ?></code>
						<?php endif; ?>
					</span>
				</h2>
			</div>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<ul class="nav nav-tabs" role="tablist">
					<li role="presentation" class="<?php echo $tab === 'resource' ? 'active' : ''; ?>">
						<a href="#oryk_resource" aria-controls="oryk_resource" role="tab" data-toggle="tab">
							<?php echo _('Resource'); ?>
						</a>
					</li>
					<li role="presentation" class="<?php echo $tab === 'devices' ? 'active' : ($isNew ? 'disabled' : ''); ?>">
						<?php if ($isNew): ?>
							<a href="#" title="<?php echo _('Save the resource first -- the filename a device asks for is this one rendered.'); ?>"
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

					<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'resource' ? 'active' : ''; ?>" id="oryk_resource">

						<!-- Not a form: see the note in partials/editor.php. -->
						<input type="hidden" id="resource_row_id" value="<?php echo $id; ?>">
						<input type="hidden" id="resource_profile_id" value="<?php echo $profileId; ?>">

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="resource_name">
											<?php echo _('Filename'); ?>
											<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
										</label>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control oryk-name" id="resource_name"
											autocomplete="off" placeholder="{{device.mac}}-phone.cfg"
											value="<?php echo $h($resource['name']); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('The filename a phone asks for. It is a template like the body below, so <code>{{device.mac}}-phone.cfg</code> covers every device on this profile, and a vendor that names its files some other way can be matched exactly. A name with no placeholders in it -- <code>phone.cfg</code> -- is matched against the request with the device\'s MAC taken off the front, so either way of writing it works. Unique within this profile.'); ?>
									</span>
									<span class="help-block fpbx-help-block">
										<?php echo _('The main configuration file, <code>{{device.mac}}.cfg</code>, is the profile\'s own template -- unless a resource here claims that name, which then wins.'); ?>
									</span>
									<?php if (!$isNew): ?>
										<span class="help-block fpbx-help-block">
											<?php echo _('What that comes to for each device on this profile is on the Devices tab.'); ?>
										</span>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="resource_template"><?php echo _('Template'); ?></label>
									</div>
									<div class="col-md-8">
										<textarea class="form-control oryk-template" id="resource_template"
											rows="24" spellcheck="false" wrap="off">
<?php echo $h($resource['template']); ?></textarea>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('What is served under that filename, stored as typed and rendered the same way the profile\'s template is. The content type is taken from the extension: .xml is served as XML, .json as JSON, anything else as plain text.'); ?>
									</span>
									<?php include __DIR__ . '/partials/placeholders.php'; ?>
								</div>
							</div>
						</div>

					</div>

					<?php if (!$isNew): ?>
						<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'devices' ? 'active' : ''; ?>" id="oryk_devices">

							<p class="help-block fpbx-help-block">
								<?php echo _('Devices assigned to this profile, and the filename each of them asks this resource for -- the name above, rendered against that device. Render fetches it as that phone would.'); ?>
							</p>

							<table
								id="device_table"
								data-toggle="table"
								data-url="ajax.php?module=oryk_provisioner&command=listDevices&profile_id=<?php echo $profileId; ?>&resource_id=<?php echo $id; ?>"
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
										<th data-field="filename" data-formatter="formatResourceFilename"><?php echo _('Asks For'); ?></th>
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

	const orykProfileId = <?php echo $profileId; ?>;
	const orykResourceId = <?php echo $id; ?>;
	const orykResources = '?display=oryk_provisioner&profile=<?php echo $profileId; ?>&tab=resources';

	function formatDeviceMac(value) {
		return value ? `<code>${orykEscape(value)}</code>` : '-';
	}

	// The device column names the FreePBX device the association points at,
	// and links to it; the extension is its own column beside it.
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

	// What this device actually asks for: the resource's name rendered with
	// that device's values, worked out server-side by the same code that
	// matches an incoming request, so the column is what a phone gets rather
	// than a second guess at it.
	function formatResourceFilename(value) {
		return value ? `<code>${orykEscape(value)}</code>` : '-';
	}

	// Edit is the association's own page, the same link the profile's Devices
	// tab and the list both draw. Render is this resource as that device
	// receives it -- absent when the rendered name carries somebody else's
	// MAC (000000000000-directory.xml and the like), since the endpoint reads
	// the device out of the path and such a URL would answer for the wrong
	// phone.
	function formatDeviceActions(value, row) {
		const actions = [
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&device=${encodeURIComponent(row.id)}">Edit</a>`
		];

		if (row.url) {
			actions.push(`<a class="btn btn-default btn-sm" href="${orykEscape(row.url)}" target="_blank" title="View this resource as this device receives it">Render</a>`);
		}

		return `<div class="flex gap-3">${actions.join('')}</div>`;
	}

	// A table drawn while its tab is hidden has no width to lay itself out
	// against, so it is told to measure again once the tab is on screen. The
	// URL is kept in step at the same time, so a reload or a bookmark comes
	// back to the tab that is open.
	$(document).on('shown.bs.tab', 'a[data-toggle="tab"]', function () {
		const pane = $(this).attr('href');

		$(pane).find('table[data-toggle="table"]').bootstrapTable('resetView');

		if (window.history && window.history.replaceState) {
			const tab = pane === '#oryk_devices' ? '&tab=devices' : '';
			window.history.replaceState(null, '', `?display=oryk_provisioner&profile=${orykProfileId}&resource=${orykResourceId}${tab}`);
		}
	});

	orykEditor({
		save: 'saveResource',
		remove: 'deleteResource',
		confirm: 'Delete this resource?',
		values: function () {
			return {
				id: $('#resource_row_id').val(),
				profile_id: $('#resource_profile_id').val(),
				name: $('#resource_name').val(),
				template: $('#resource_template').val()
			};
		},
		// Back to the profile's Resources tab, which is re-rendered on
		// arrival, with the row that was just written picked out.
		saved: function (response) {
			return orykResources + '&saved=' + encodeURIComponent(response.id);
		},
		closed: orykResources
	});

</script>
