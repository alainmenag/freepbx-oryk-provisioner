<?php
/**
 * views/device.php -- one device association, over one tab.
 *
 * Reached at ?display=oryk_provisioner&device=<id> to edit an existing
 * association, or ?display=oryk_provisioner&device= (present, empty) to write
 * a new one -- the same shape ?profile= has, and for the same reasons.
 *
 * This was a modal on the list until 1.0.6. Three short fields did fit in one,
 * but a dialog has no address: nothing could link to an association, the
 * profile editor's Devices tab had to send a row id back to the list and have
 * JS re-open the dialog on arrival, and the two ways into the same three
 * fields were two things to keep in step. A page is one way in, and every
 * other editor in the module is already one.
 *
 * One tab, and the fields are in it rather than loose under the section title,
 * because every page in the module is laid out the same way -- the list has
 * two tabs, the profile editor three -- and a page that is the exception has
 * to be read as one. Whatever an association grows next (a provisioning log,
 * per-device parameters) is then another <li> rather than a re-layout. The
 * bare ?device=<id> *is* this tab, so there is no &tab= to carry: a single tab
 * has nothing to keep in the URL, which is why there is no shown.bs.tab
 * handler here the way there is on the pages that have more than one.
 *
 * Save, Delete and Close are the action bar's, drawn by FreePBX from
 * getActionBar() and bound by views/partials/editor.php.
 *
 * @var array<string, mixed>              $device         id (0 when new), mac, device_id, profile_id
 * @var array<int, array<string, mixed>>  $freepbxDevices What the FreePBX device select offers
 * @var array<int, array<string, mixed>>  $profiles       What the profile select offers
 */

$device = $device ?? ['id' => 0, 'mac' => '', 'device_id' => '', 'profile_id' => 0];
$freepbxDevices = $freepbxDevices ?? [];
$profiles = $profiles ?? [];

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$id = (int) $device['id'];
$isNew = $id === 0;
$mac = (string) $device['mac'];
$deviceId = (string) ($device['device_id'] ?? '');
$profileId = (int) ($device['profile_id'] ?? 0);
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<div class="section-title">
				<h2>
					<span class="title">
						<a class="title" href="?display=oryk_provisioner&tab=devices">Provisioner</a>
						<span>:: Device</span>
						<?php if (isset($device['mac'])): ?>
							<code><?php echo $h($device['mac']); ?></code>
						<?php endif; ?>
					</span>
				</h2>
			</div>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<ul class="nav nav-tabs" role="tablist">
					<li role="presentation" class="active">
						<a href="#oryk_device" aria-controls="oryk_device" role="tab" data-toggle="tab">
							<?php echo _('Device'); ?>
						</a>
					</li>
				</ul>

				<div class="tab-content">

					<div role="tabpanel" class="tab-pane oryk-tab-section active" id="oryk_device">

						<?php if (!$isNew && $profileId): ?>
							<div class="oryk-crumb">
								<?php
								// The URL a phone is given, opened in a tab rather than
								// fetched back into this one: it is a page of plain
								// text, not something this page has anywhere to put.
								echo sprintf(
									_('Provisioned at %s.'),
									'<a href="/provisioner/' . $h(rawurlencode($mac)) . '.cfg" target="_blank"><code>/provisioner/'
										. $h($mac) . '.cfg</code></a>'
								);
								?>
							</div>
						<?php endif; ?>

						<!-- Not a form: see the note in partials/editor.php. -->
						<input type="hidden" id="device_row_id" value="<?php echo $id; ?>">

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="device_mac">
											<?php echo _('MAC Address'); ?>
											<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
										</label>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control oryk-name" id="device_mac"
											autocomplete="off" placeholder="001565aabbcc"
											value="<?php echo $h($mac); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('The address the phone provisions with. Stored as 12 lowercase hexadecimal characters; separators are removed. Unique -- a MAC is associated once.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="device_device_id"><?php echo _('FreePBX Device'); ?></label>
									</div>
									<div class="col-md-8">
										<select class="form-control" id="device_device_id">
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
										<?php echo _('The extension this phone registers as. Optional: a profile of static configuration renders without one, with the device values left empty.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="device_profile_id"><?php echo _('Device Profile'); ?></label>
									</div>
									<div class="col-md-8">
										<select class="form-control" id="device_profile_id">
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
										<?php echo _('What this device is served. Until one is assigned there is nothing to provision, and the device is asked for a configuration it has none of.'); ?>
									</span>
								</div>
							</div>
						</div>

					</div>

				</div>

			</div>
		</div>
	</div>
</div>

<script>

	const orykDevices = '?display=oryk_provisioner&tab=devices';

	orykEditor({
		save: 'saveDevice',
		remove: 'deleteDevice',
		confirm: 'Delete this device association?',
		values: function () {
			return {
				id: $('#device_row_id').val(),
				mac: $('#device_mac').val(),
				device_id: $('#device_device_id').val(),
				profile_id: $('#device_profile_id').val()
			};
		},
		// Back to the list on the Devices tab, with the row that was just
		// written picked out -- the same thing the profile editor does.
		saved: function (response) {
			return orykDevices + '&saved=' + encodeURIComponent(response.id);
		},
		closed: orykDevices
	});

</script>
