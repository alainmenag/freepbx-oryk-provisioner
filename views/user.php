<?php
/**
 * views/user.php -- one Extension/User, over two tabs.
 *
 * Reached at ?display=oryk_provisioner&user=<extension>, or &user= (present,
 * empty) for a new one. A user is a Core device, not a row of this module's
 * -- see ARCHITECTURE.md, "Users" -- so the address is its number, and a save
 * that renumbers it lands on the new one.
 *
 * Clients is the provisioner clients pointing at this user, with a way to add
 * one already pointed at it.
 *
 * @var array<string, mixed>             $user       extension ('' when new), name, email, from_domain, secure, clients, context
 * @var string                           $lobbyContext ORYK_OPEN_CONTEXT: a user in it is offered Promote
 * @var string                           $pbxDomain  What a blank From Domain resolves to
 * @var array<string, bool>              $available  Which of the other tabs have anything on them
 * @var string                           $tab        Tab to open on: user|clients
 * @var array<int, array<string, mixed>> $navigator  Levels the navigator draws -- see partials/navigator.php
 * @var array<int, array<string, mixed>> $sections Navigator::sections() -- see partials/sections.php
 * @var string                           $version  Module version -- see partials/sections.php
 */

$user = $user ?? ['extension' => '', 'name' => '', 'email' => '', 'from_domain' => '', 'secure' => 1, 'clients' => 0];
$available = $available ?? [];
$pbxDomain = (string) ($pbxDomain ?? '');
$lobbyContext = (string) ($lobbyContext ?? '');
$context = (string) ($user['context'] ?? '');
$inLobby = $context !== '' && $context === $lobbyContext;

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$extension = (string) $user['extension'];
$isNew = $extension === '';
$tab = ($tab ?? '') === 'clients' && !empty($available['clients']) ? 'clients' : 'user';
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

				<?php include __DIR__ . '/partials/tabs.php'; ?>

				<div class="tab-content">

					<?php if ($tab === 'user'): ?>
					<div class="tab-pane oryk-tab-section active" id="oryk_user">

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="user_extension"><?php echo _('Extension'); ?></label> <i class="fa fa-question-circle fpbx-help-icon" data-for="user_extension"></i>
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
										<label class="control-label" for="user_name"><?php echo _('Name'); ?></label> <i class="fa fa-question-circle fpbx-help-icon" data-for="user_name"></i>
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
										<label class="control-label" for="user_email"><?php echo _('Email'); ?></label> <i class="fa fa-question-circle fpbx-help-icon" data-for="user_email"></i>
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
										<label class="control-label" for="user_from_domain"><?php echo _('From Domain'); ?></label> <i class="fa fa-question-circle fpbx-help-icon" data-for="user_from_domain"></i>
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
										<label class="control-label" for="user_secret"><?php echo _('Secret'); ?></label> <i class="fa fa-question-circle fpbx-help-icon" data-for="user_secret"></i>
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
										<label class="control-label" for="user_context"><?php echo _('Context'); ?></label> <i class="fa fa-question-circle fpbx-help-icon" data-for="user_context"></i>
									</div>
									<div class="col-md-8">
										<p class="form-control-static oryk-name" id="user_context">
											<?php echo $h($context !== '' ? $context : '-'); ?>
											<?php if ($inLobby): ?>
											&nbsp;<button type="button" class="btn btn-default btn-sm" id="user_promote"><?php echo _('Promote'); ?></button>
											<?php endif; ?>
										</p>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="user_context-help">
										<?php echo _('Where this user\'s calls are placed. Open provisioning puts every user it creates in the lobby (Settings -> Sign-up Context): internal extensions, conferences, voicemail and emergency routes only, one call at a time, no UCP login, and no forward or transfer out. Promote moves it to from-internal, lets UCP follow its groups again, and lifts the rest on Apply Config. Any other change is made in Extensions.'); ?>
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
								<i class="fa fa-plus"></i> <?php echo _('Add Client'); ?>
							</a>
						</div>

						<table
							id="user_client_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listClients&device_id=<?php echo rawurlencode($extension); ?>"
							data-toolbar="#user_client_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
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

				</div>

			</div>
		</div>
	</div>
</div>

<script>
	const orykUserId = <?php echo json_encode($extension); ?>;
	const orykUserPromoteConfirm = <?php echo json_encode(_('Promote this user to from-internal? It will be able to use your outbound routes once Apply Config has run, and its UCP login will follow its groups again.')); ?>;
	const orykUserDeleteConfirm = <?php echo json_encode($confirm); ?>;
</script>
<?php echo $script('user'); ?>
