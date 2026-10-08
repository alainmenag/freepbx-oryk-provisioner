<?php
/**
 * views/user.php -- one Extension/User, over three tabs.
 *
 * Reached at ?display=oryk_provisioner&user=<extension>, or &user= (present,
 * empty) for a new one. A user is a Core device, not a row of this module's
 * -- see ARCHITECTURE.md, "Users" -- so the address is its number, and a save
 * that renumbers it lands on the new one.
 *
 * Clients is the provisioner clients pointing at this user, with a way to add
 * one already pointed at it.
 *
 * Services is partials/user_services.php: every service, ticked when this
 * user is assigned it, staged and saved together.
 *
 * @var array<string, mixed>             $user       extension ('' when new), name, email, from_domain, secure, clients, context
 * @var string                           $pbxDomain  What a blank From Domain resolves to
 * @var array<string, bool>              $available  Which of the other tabs have anything on them
 * @var string                           $tab        Tab to open on: user|clients|services
 * @var array<int, array<string, mixed>> $services   Services::userServices(), on the Services tab
 * @var array<string, array<string, mixed>> $serviceJobs Jobs::statusFor(), on the Services tab
 * @var array<int, array<string, mixed>> $navigator  Levels the navigator draws -- see partials/navigator.php
 * @var array<int, array<string, mixed>> $sections Navigator::sections() -- see partials/sections.php
 * @var string                           $version  Module version -- see partials/sections.php
 */

$user = $user ?? ['extension' => '', 'name' => '', 'email' => '', 'from_domain' => '', 'secure' => 1, 'clients' => 0];
$available = $available ?? [];
$pbxDomain = (string) ($pbxDomain ?? '');
$context = (string) ($user['context'] ?? '');

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$extension = (string) $user['extension'];
$isNew = $extension === '';
$tab = (in_array($tab ?? '', ['clients', 'services'], true) && !empty($available[$tab])) ? $tab : 'user';
$services = isset($services) && is_array($services) ? $services : [];
$serviceJobs = isset($serviceJobs) && is_array($serviceJobs) ? $serviceJobs : [];
$clientCount = (int) ($user['clients'] ?? 0);

$userUrl = '?display=oryk_provisioner&user=' . rawurlencode($extension);

$tabs = [
	'user' => [
		'label' => _('User'),
		'href' => $userUrl,
	],
	'clients' => [
		'label' => _('Clients'),
		'href' => $userUrl . '&tab=clients',
		'disabled' => empty($available['clients']),
		'title' => _('Save the user first.'),
	],
	'services' => [
		'label' => _('Services'),
		'href' => $userUrl . '&tab=services',
		'disabled' => empty($available['services']),
		'title' => _('Save the user first.'),
	],
];

// Deleting is permanent and reaches well past this row, so Delete says what
// goes with it before it asks.
$confirm = _('Delete this user? The extension, its User Manager account, its voicemail and its call history and recordings are removed permanently. This cannot be undone.');

if ($clientCount > 0) {
	$confirm .= ' ' . sprintf(
		ngettext('%d client points at this user and will be deleted too, with the logs it sent.', '%d clients point at this user and will be deleted too, with the logs they sent.', $clientCount),
		$clientCount
	);
}
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="provisioner container-fluid">
	<div class="fpbx-container">
		<div class="display no-border">

			<?php include __DIR__ . '/partials/sections.php'; ?>
			<?php include __DIR__ . '/partials/navigator.php'; ?>

			<div class="section no-border" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<?php if (!$isNew && array_key_exists('device', $user) && !(int) $user['device']): ?>
				<div class="alert alert-warning">
					<?php echo _('This extension has no device, so no phone can register as it. Save gives it one back on this number, in from-internal, with a new secret unless you type one.'); ?>
				</div>
				<?php endif; ?>

				<?php include __DIR__ . '/partials/tabs.php'; ?>

				<div class="tab-content">

					<?php if ($tab === 'user'): ?>
					<div class="tab-pane oryk-tab-section active" id="oryk_user">

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="user_extension"><?php echo _('Extension'); ?></label> <i class="fpbx-help-icon" data-for="user_extension"><?php echo $icon('help'); ?></i>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control oryk-name" id="user_extension"
											autocomplete="off" inputmode="numeric" maxlength="10"
											placeholder="<?php echo $h($isNew ? _('Next free 999… number') : $extension); ?>"
											value="<?php echo $h($extension); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="user_extension-help">
										<?php echo _('The device, the extension and the User Manager account are one number. Left blank on a new user, the next free number in the 999… range is used. Digits only, at most ten, and not already held by a device, an extension or a User Manager account. Changing it renumbers the user: the extension settings, User Manager account, mailbox and messages, UCP access, call history and any client pointing at it move with it.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="user_name"><?php echo _('Name'); ?></label> <i class="fpbx-help-icon" data-for="user_name"><?php echo $icon('help'); ?></i>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control" id="user_name" maxlength="255"
											autocomplete="off" placeholder="<?php echo $h(_('Front Desk')); ?>"
											value="<?php echo $h($user['name'] ?? ''); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="user_name-help">
										<?php echo _('The device description, the extension name and the User Manager display name, kept in step on every save. Left blank, the number.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="user_email"><?php echo _('Email'); ?></label> <i class="fpbx-help-icon" data-for="user_email"><?php echo $icon('help'); ?></i>
									</div>
									<div class="col-md-8">
										<input type="email" class="form-control" id="user_email" maxlength="255"
											autocomplete="off" placeholder="user@example.com"
											value="<?php echo $h($user['email'] ?? ''); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="user_email-help">
										<?php echo _('Used for the User Manager account and its welcome email, and for voicemail.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="user_from_domain"><?php echo _('From Domain'); ?></label> <i class="fpbx-help-icon" data-for="user_from_domain"><?php echo $icon('help'); ?></i>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control oryk-name" id="user_from_domain" maxlength="255"
											autocomplete="off" spellcheck="false"
											placeholder="<?php echo $h($pbxDomain !== '' ? $pbxDomain : _('none')); ?>"
											value="<?php echo $h($user['from_domain'] ?? ''); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="user_from_domain-help">
										<?php echo _('The domain this endpoint puts in the From header. Left blank, it follows the From Domain on the Settings tab (also in Advanced Settings), or the PBX hostname when that is a domain name -- the grey value is what blank comes to right now.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="user_secret"><?php echo _('Secret'); ?></label> <i class="fpbx-help-icon" data-for="user_secret"><?php echo $icon('help'); ?></i>
									</div>
									<div class="col-md-8">
										<input type="password" class="form-control" id="user_secret" maxlength="255"
											autocomplete="new-password"
											placeholder="<?php echo $h($isNew ? _('Generated if left blank') : _('Unchanged if left blank')); ?>"
											value="">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="user_secret-help">
										<?php echo _('The SIP password. Never shown; type one to replace it. Media encryption (SDES) is switched on for every user on every save.'); ?>
									</span>
								</div>
							</div>
						</div>

						<?php if (!$isNew): ?>
						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="user_context"><?php echo _('Context'); ?></label> <i class="fpbx-help-icon" data-for="user_context"><?php echo $icon('help'); ?></i>
									</div>
									<div class="col-md-8">
										<p class="form-control-static oryk-name" id="user_context">
											<?php echo $h($context !== '' ? $context : '-'); ?>
										</p>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="user_context-help">
										<?php echo _('Where this user\'s calls are placed. Open provisioning puts every user it creates in the lobby (Settings -> Sign-up Context): internal extensions, conferences, voicemail and emergency routes only, one call at a time, no UCP login, and no forward or transfer out. It is changed in Extensions, not here.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label"><?php echo _('Account'); ?></label>
									</div>
									<div class="col-md-8">
										<p class="form-control-static oryk-name">
											<?php echo $h($extension); ?>
											&nbsp;<a href="?display=extensions&amp;extdisplay=<?php echo rawurlencode($extension); ?>"><?php echo _('Open in Extensions'); ?></a>
										</p>
									</div>
								</div>
							</div>
						</div>
						<?php endif; ?>

						<p class="help-block">
							<?php echo _('Saving writes the user and raises Apply Config; the change reaches Asterisk when it is applied.'); ?>
						</p>

					</div>
					<?php endif; ?>

					<?php if ($tab === 'clients'): ?>
					<div class="tab-pane oryk-tab-section active" id="oryk_clients">

						<div id="user_client_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;client=&amp;device_id=<?php echo rawurlencode($extension); ?>">
								<?php echo $icon('plus'); ?> <?php echo _('Add Client'); ?>
							</a>
						</div>

						<table
							id="user_client_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listClients&extension=<?php echo rawurlencode($extension); ?>"
							data-toolbar="#user_client_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-icons-prefix="oryk-icon"
							data-icons='{"refresh":"oryk-icon-refresh"}'
							data-unique-id="id"
							data-row-style="orykUserClientRow"
							data-sort-name="mac"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="mac" data-formatter="formatUserClientMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
									<th data-field="profile" data-formatter="formatUserClientProfile" data-sortable="true"><?php echo _('Profile'); ?></th>
									<th data-field="last_seen" data-formatter="formatUserClientText" data-sortable="true"><?php echo _('Last Seen'); ?></th>
									<th data-field="actions" data-formatter="formatUserClientActions" data-align="right"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>

					</div>
					<?php endif; ?>

					<?php if ($tab === 'services'): ?>
					<?php include __DIR__ . '/partials/user_services.php'; ?>
					<?php endif; ?>

				</div>

			</div>
		</div>
	</div>
</div>

<script>

	const orykUserId = <?php echo json_encode($extension); ?>;

	function orykUserClientRow(row) {
		return Number(row.enabled) ? {} : { classes: 'oryk-disabled' };
	}

	function formatUserClientText(value) {
		return value ? orykEscape(value) : '-';
	}

	function formatUserClientMac(value, row) {
		return `<a href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">${value ? orykEscape(value) : '-'}</a>`;
	}

	function formatUserClientProfile(value, row) {
		return value ? `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.profile_id)}">${orykEscape(value)}</a>` : '-';
	}

	function formatUserClientActions(value, row) {
		return `<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">Edit</a>`;
	}

	orykEditor({
		save: 'saveUser',
		remove: 'deleteUser',
		confirm: <?php echo json_encode($confirm); ?>,
		values: function () {
			return {
				id: orykUserId,
				extension: $('#user_extension').val(),
				name: $('#user_name').val(),
				email: $('#user_email').val(),
				from_domain: $('#user_from_domain').val(),
				secret: $('#user_secret').val()
			};
		},
		// The number the save answered with: the same one, or the new one a
		// renumbering moved it to.
		page: function (id) {
			return '?display=oryk_provisioner&user=' + encodeURIComponent(id);
		},
		closed: '?display=oryk_provisioner&tab=users'
	});

</script>
