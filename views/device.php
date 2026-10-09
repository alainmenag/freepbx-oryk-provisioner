<?php
/**
 * views/device.php -- one FreePBX device, and the user it is on.
 *
 * Reached at ?display=oryk_provisioner&device=<id>. The device is FreePBX's
 * and so is everything about it but one thing changed here: its user, an
 * extension or nobody. There is no new one. Delete asks what a client's
 * delete asks from the other side: Device Only, which keeps the clients on
 * it with no device, or Device + Client.
 *
 * Moving a user's own device -- the one numbered like its extension -- to
 * another user takes that extension off the Users list (Users::SHAPE), so
 * Save asks first.
 *
 * @var array<string, mixed>             $device    Devices::deviceRow(): id, tech, description, user, user_name, is_user, own, clients
 * @var array<int, array<string, mixed>> $users     Devices::userChoices(): every extension
 * @var array<int, array<string, mixed>> $navigator Levels the navigator draws -- see partials/navigator.php
 * @var array<int, array<string, mixed>> $sections  Navigator::sections() -- see partials/sections.php
 * @var string                           $version   Module version -- see partials/sections.php
 */

$device = ($device ?? []) + ['id' => '', 'tech' => '', 'description' => '', 'user' => 'none', 'own' => 0];
$users = isset($users) && is_array($users) ? $users : [];

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$id = (string) $device['id'];
$on = (string) $device['user'] === '' ? 'none' : (string) $device['user'];
$listed = $on === 'none' || in_array($on, array_map('strval', array_column($users, 'extension')), true);

$tab = 'device';
$tabs = [
	'device' => [
		'label' => _('Device'),
		'href' => '?display=oryk_provisioner&device=' . rawurlencode($id),
	],
];
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="provisioner container-fluid">
	<div class="fpbx-container">
		<div class="display no-border">

			<?php include __DIR__ . '/partials/sections.php'; ?>
			<?php include __DIR__ . '/partials/navigator.php'; ?>

			<div class="section no-border" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<?php include __DIR__ . '/partials/tabs.php'; ?>

				<div class="tab-content">
					<div class="tab-pane oryk-tab-section active" id="oryk_device">

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label"><?php echo _('Device'); ?></label>
									</div>
									<div class="col-md-8">
										<p class="form-control-static oryk-name">
											<?php echo $h($id); ?>
											<?php if ((string) $device['tech'] !== ''): ?>
											&nbsp;<span class="text-muted"><?php echo $h($device['tech']); ?></span>
											<?php endif; ?>
											&nbsp;<a href="?display=devices&amp;extdisplay=<?php echo rawurlencode($id); ?>"><?php echo _('Open in Devices'); ?></a>
										</p>
									</div>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label"><?php echo _('Description'); ?></label>
									</div>
									<div class="col-md-8">
										<p class="form-control-static"><?php echo (string) $device['description'] !== '' ? $h($device['description']) : '-'; ?></p>
									</div>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="device_user"><?php echo _('User'); ?></label> <i class="fpbx-help-icon" data-for="device_user"><?php echo $icon('help'); ?></i>
									</div>
									<div class="col-md-8">
										<select class="form-control" id="device_user">
											<option value="none"<?php echo $on === 'none' ? ' selected' : ''; ?>><?php echo _('None'); ?></option>
											<?php if (!$listed): ?>
											<option value="<?php echo $h($on); ?>" selected><?php echo $h(sprintf(_('%s (no such extension)'), $on)); ?></option>
											<?php endif; ?>
											<?php foreach ($users as $choice): ?>
											<?php $extension = (string) $choice['extension']; ?>
											<option value="<?php echo $h($extension); ?>"<?php echo $extension === $on ? ' selected' : ''; ?>>
												<?php echo $h($extension . ((string) $choice['name'] !== '' && (string) $choice['name'] !== $extension ? ' - ' . $choice['name'] : '')); ?>
											</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="device_user-help">
										<?php echo _('The extension this device is on: whose calls ring it, and who it calls as. None leaves it on no extension. A client on this device follows it to the new user.'); ?>
									</span>
								</div>
							</div>
						</div>

						<p class="help-block">
							<?php echo _('Saving writes the device\'s user and raises Apply Config; the change reaches Asterisk when it is applied.'); ?>
						</p>

					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<script>

	const orykDeviceId = <?php echo json_encode($id); ?>;
	const orykDeviceUser = <?php echo json_encode($on); ?>;

	// The device that is a user's own: moving it leaves that user without one.
	const orykDeviceOwn = <?php echo json_encode((bool) (int) $device['own']); ?>;

	// How many clients are on it: what Delete's question turns on.
	const orykDeviceClients = <?php echo json_encode((int) ($device['clients'] ?? 0)); ?>;

	orykEditor({
		save: 'saveDevice',
		ask: function (values) {
			return orykAsk(
				orykDeviceOwn && values.user !== orykDeviceUser
					? `Device ${orykDeviceId} is extension ${orykDeviceUser}'s own. Moved, that extension is left with no device and is no longer on the Users list, until this device is put back on it.`
					: '',
				{ title: 'Change user', choices: [{ label: 'Change User', value: true, style: 'btn-primary' }] }
			);
		},
		remove: 'deleteDevice',
		confirm: orykDeviceDeleteQuestion(orykDeviceId, orykDeviceOwn, orykDeviceClients),
		asking: function () {
			return orykDeviceDeleteChoices(orykDeviceClients);
		},
		answered: function (withClients) {
			return { clients: withClients ? 1 : '' };
		},
		values: function () {
			return {
				id: orykDeviceId,
				user: $('#device_user').val()
			};
		},
		page: function () {
			return '?display=oryk_provisioner&device=' + encodeURIComponent(orykDeviceId);
		},
		closed: '?display=oryk_provisioner&tab=devices'
	});

</script>
