<?php
/**
 * views/client.php -- one client, over two tabs.
 *
 * Reached at ?display=oryk_provisioner&client=<id> to edit an existing
 * client, or ?display=oryk_provisioner&client= (present, empty) to write
 * a new one -- the same shape ?profile= has, and for the same reasons.
 *
 * This was a modal on the list until 1.0.6. Three short fields did fit in one,
 * but a dialog has no address: nothing could link to a client, the
 * profile editor's Clients tab had to send a row id back to the list and have
 * JS re-open the dialog on arrival, and the two ways into the same three
 * fields were two things to keep in step. A page is one way in, and every
 * other editor in the module is already one.
 *
 * Client is the MAC, the FreePBX device behind it and the profile it is
 * assigned. Resources is what that comes to: the files its profile serves,
 * each with the filename this client asks for and a link that fetches it as
 * this client would -- the resource editor's Clients tab read from the other
 * end, and the tab to open when a particular client is not getting what it
 * should.
 *
 * Logs is what actually happened: every file this phone has asked the
 * endpoint for and how each one went. It is narrowed by MAC rather than by
 * this row's id, so requests logged before anybody wrote this client are on
 * it too -- which is usually the run of 404s that says what the phone has
 * been asking for all along. It needs no profile assigned for the same
 * reason: a client with nothing to serve it is the one being refused.
 *
 * The bare ?client=<id> *is* the Client tab, the way ?profile=<id> is the
 * profile editor's first tab; Resources names itself with &tab=resources, and
 * the shown.bs.tab handler keeps the address in step.
 *
 * @var array<string, mixed>              $client         id (0 when new), mac, device_id, profile_id
 * @var array<int, array<string, mixed>>  $freepbxDevices What the FreePBX device select offers
 * @var array<int, array<string, mixed>>  $profiles       What the profile select offers
 * @var int                               $resources      Resources on its profile, for the tab's count
 * @var int                               $logs           Requests logged against its MAC, for the tab's count
 * @var array<string, bool>               $available      Which of the other tabs have anything on them
 * @var string                            $tab            Tab to open on: client|resources|logs
 */

$client = $client ?? ['id' => 0, 'mac' => '', 'device_id' => '', 'profile_id' => 0];
$freepbxDevices = $freepbxDevices ?? [];
$profiles = $profiles ?? [];
$resources = (int) ($resources ?? 0);
$logs = (int) ($logs ?? 0);
$available = $available ?? [];
$tab = in_array($tab ?? '', ['resources', 'logs'], true) ? $tab : 'client';

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$id = (int) $client['id'];
$isNew = $id === 0;
$mac = (string) $client['mac'];
$deviceId = (string) ($client['device_id'] ?? '');
$profileId = (int) ($client['profile_id'] ?? 0);

// Nothing is served to a client that has never been written, or to one
// with no profile assigned, so its Resources tab is there but does not open.
//
// Logs asks less of it: a MAC that has been written is enough, because the
// log is of what was asked rather than of what was served, and a client with
// no profile is precisely the one whose refusals are worth reading.
$served = (bool) ($available['resources'] ?? (!$isNew && $profileId));
$logged = (bool) ($available['logs'] ?? (!$isNew && $mac !== ''));

if (($tab === 'resources' && !$served) || ($tab === 'logs' && !$logged)) {
	$tab = 'client';
}
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<div class="section-title">
				<h2>
					<span class="title">
						<a class="title" href="?display=oryk_provisioner&tab=clients">Provisioner</a>
						<span>:: Client</span>
						<?php if (isset($client['mac'])): ?>
							<code><?php echo $h($client['mac']); ?></code>
						<?php endif; ?>
					</span>
				</h2>
			</div>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<ul class="nav nav-tabs" role="tablist">
					<li role="presentation" class="<?php echo $tab === 'client' ? 'active' : ''; ?>">
						<a href="#oryk_client" aria-controls="oryk_client" role="tab" data-toggle="tab">
							<?php echo _('Client'); ?>
						</a>
					</li>
					<li role="presentation" class="<?php echo $tab === 'resources' ? 'active' : ($served ? '' : 'disabled'); ?>">
						<?php if (!$served): ?>
							<a href="#" onclick="return false;"
								title="<?php echo $isNew
									? _('Save the client first -- what it is served follows from the profile it is assigned to.')
									: _('Assign a profile first -- nothing is served to a client without one.'); ?>">
								<?php echo _('Resources'); ?>
							</a>
						<?php else: ?>
							<a href="#oryk_resources" aria-controls="oryk_resources" role="tab" data-toggle="tab">
								<?php echo _('Resources'); ?>
								<span class="badge"><?php echo $resources; ?></span>
							</a>
						<?php endif; ?>
					</li>
					<li role="presentation" class="<?php echo $tab === 'logs' ? 'active' : ($logged ? '' : 'disabled'); ?>">
						<?php if (!$logged): ?>
							<a href="#" onclick="return false;"
								title="<?php echo _('Save the client first -- the log is kept by MAC address.'); ?>">
								<?php echo _('Logs'); ?>
							</a>
						<?php else: ?>
							<a href="#oryk_logs" aria-controls="oryk_logs" role="tab" data-toggle="tab">
								<?php echo _('Logs'); ?>
								<span class="badge"><?php echo $logs; ?></span>
							</a>
						<?php endif; ?>
					</li>
				</ul>

				<div class="tab-content">

					<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'client' ? 'active' : ''; ?>" id="oryk_client">

						<!-- Not a form: see the note in partials/editor.php. -->
						<input type="hidden" id="client_row_id" value="<?php echo $id; ?>">

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="client_mac">
											<?php echo _('MAC Address'); ?>
											<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
										</label>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control oryk-name" id="client_mac"
											autocomplete="off" placeholder="001565aabbcc"
											value="<?php echo $h($mac); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('The address the client provisions with. Stored as 12 lowercase hexadecimal characters; separators are removed. Unique -- a MAC is associated once.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="client_device_id"><?php echo _('Device'); ?></label>
									</div>
									<div class="col-md-8">
										<select class="form-control" id="client_device_id">
											<option value=""><?php echo _('None'); ?></option>
											<?php foreach ($freepbxDevices as $choice): ?>
												<option value="<?php echo $h($choice['id']); ?>"
													<?php echo (string) $choice['id'] === $deviceId ? 'selected' : ''; ?>>
													<?php
													echo $h(trim(
														$choice['id']
														. (($choice['description'] ?? '') !== '' ? ' - ' . $choice['description'] : '')
														. (($choice['tech'] ?? '') !== '' ? ' (' . $choice['tech'] . ')' : '')
													));
													?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('The extension this client registers as. Optional: a profile of static configuration renders without one, with the device values left empty.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="client_profile_id"><?php echo _('Profile'); ?></label>
									</div>
									<div class="col-md-8">
										<select class="form-control" id="client_profile_id">
											<option value=""><?php echo _('None'); ?></option>
											<?php foreach ($profiles as $profile): ?>
												<option value="<?php echo (int) $profile['id']; ?>"
													<?php echo (int) $profile['id'] === $profileId ? 'selected' : ''; ?>>
													<?php echo $h($profile['name']); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('What this client is served. Until one is assigned there is nothing to provision, and the client is asked for a configuration it has none of.'); ?>
									</span>
								</div>
							</div>
						</div>

					</div>

					<?php if ($served): ?>
						<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'resources' ? 'active' : ''; ?>" id="oryk_resources">

							<div id="resource_toolbar" class="oryk-toolbar">
								<a class="btn btn-default" href="?display=oryk_provisioner&amp;profile=<?php echo $profileId; ?>&amp;tab=resources">
									<i class="fa fa-cog"></i> <?php echo _('Edit Profile'); ?>
								</a>
							</div>

							<table
								id="resource_table"
								data-toggle="table"
								data-url="ajax.php?module=oryk_provisioner&command=listResources&profile_id=<?php echo $profileId; ?>&client_id=<?php echo $id; ?>"
								data-toolbar="#resource_toolbar"
								class="table table-striped"
								data-side-pagination="server"
								data-pagination="true"
								data-search="true"
								data-unique-id="id"
								data-sort-name="name"
								data-sort-order="asc">
								<thead>
									<tr>
										<th data-field="name" data-formatter="formatResourceName" data-sortable="true"><?php echo _('Resource'); ?></th>
										<th data-field="updated_at" data-formatter="formatResourceText" data-sortable="true"><?php echo _('Updated'); ?></th>
										<th data-field="actions" data-formatter="formatResourceActions"><?php echo _('Actions'); ?></th>
									</tr>
								</thead>
							</table>

						</div>
					<?php endif; ?>

					<?php if ($logged): ?>
						<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'logs' ? 'active' : ''; ?>" id="oryk_logs">
							<?php
							// By MAC, not by this row's id: see the note at the
							// top of the partial, and the one above $logged.
							$logMac = $mac;
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

	const orykClientId = <?php echo $id; ?>;
	const orykClientProfileId = <?php echo $profileId; ?>;
	const orykClients = '?display=oryk_provisioner&tab=clients';

	function formatResourceText(value) {
		return value ? orykEscape(value) : '-';
	}

	// The resource as it is written on the profile: a filename template, which
	// is why it is worth showing beside what it comes to here.
	function formatResourceName(value, row) {
		return value ? `<a href="?display=oryk_provisioner&profile=${orykClientProfileId}&resource=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>` : '-';
	}

	// Edit is the resource's own page under its profile, the same link that
	// profile's Resources tab draws -- a resource is edited in one place
	// wherever it is reached from. Render is this file as this client receives
	// it, absent when the rendered name carries somebody else's MAC
	// (000000000000-directory.xml and the like), since the endpoint reads the
	// client out of the path and such a URL would answer for another client.
	function formatResourceActions(value, row) {
		const actions = [
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&profile=${orykClientProfileId}&resource=${encodeURIComponent(row.id)}">Edit</a>`
		];

		if (row.url) {
			actions.push(`<a class="btn btn-default btn-sm" href="${orykEscape(row.url)}" target="_blank" title="View this resource as this client receives it">Render</a>`);
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
			const tabs = { '#oryk_resources': '&tab=resources', '#oryk_logs': '&tab=logs' };
			const tab = tabs[pane] || '';
			window.history.replaceState(null, '', `?display=oryk_provisioner&client=${orykClientId}${tab}`);
		}
	});

	orykEditor({
		save: 'saveClient',
		remove: 'deleteClient',
		confirm: 'Delete this client?',
		values: function () {
			return {
				id: $('#client_row_id').val(),
				mac: $('#client_mac').val(),
				device_id: $('#client_device_id').val(),
				profile_id: $('#client_profile_id').val()
			};
		},
		// Back to the list on the Clients tab, with the row that was just
		// written picked out -- the same thing the profile editor does.
		saved: function (response) {
			return orykClients + '&saved=' + encodeURIComponent(response.id);
		},
		closed: orykClients
	});

</script>
