<?php
/**
 * views/ban.php -- one ban, or a new one.
 *
 * Reached at ?display=oryk_provisioner&ban=<id>, or &ban= (present, empty) for
 * a new one, optionally with &ban_ip=, &ban_mac=, &ban_user=, &ban_client= or
 * &ban_profile= filled in. What a ban matches and which one wins is in ARCHITECTURE.md,
 * "Bans".
 *
 * @var array<string, mixed>                $ban       Bans::banRow(), or the new row's defaults
 * @var array<int, array<string, mixed>>    $users     Users::userChoices(): what the User select offers
 * @var array<int, array<string, mixed>>    $clients   Clients::clientChoices(): what the Client select offers
 * @var array<int, array<string, mixed>>    $profiles  Profiles::profileChoices(): what the Profile select offers
 * @var array<string, array<string, mixed>> $addresses Bans::clientAddresses(): clients by public address
 * @var string                              $remote    The address this page was asked from
 * @var array<int, array<string, mixed>>    $navigator Levels the navigator draws -- see partials/navigator.php
 * @var array<int, array<string, mixed>>    $sections  Navigator::sections() -- see partials/sections.php
 * @var string                              $version   Module version -- see partials/sections.php
 */

$users = isset($users) && is_array($users) ? $users : [];
$clients = isset($clients) && is_array($clients) ? $clients : [];
$profiles = isset($profiles) && is_array($profiles) ? $profiles : [];
$addresses = isset($addresses) && is_array($addresses) ? $addresses : [];
$banId = (int) $ban['id'];
$extension = (string) ($ban['extension'] ?? '');
$clientId = (string) ($ban['client_id'] ?? '');
$profileId = (string) ($ban['profile_id'] ?? '');
$state = (string) $ban['state'];

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

// What is left of a temporary ban, rounded up so saving it unchanged does not
// shorten it; an hour for anything else.
$minutes = ($state === 'banned' && $ban['expires_in'] !== null)
	? max(1, (int) ceil((int) $ban['expires_in'] / 60))
	: 60;

// A user ban outlives the user it names, so its number is offered even when no
// user has it.
$userNumbers = array_map(function ($user) {
	return (string) $user['extension'];
}, $users);

if ($extension !== '' && !in_array($extension, $userNumbers, true)) {
	$users[] = ['extension' => $extension, 'name' => _('(no such user)')];
}

$tab = 'ban';
$tabs = [
	'ban' => [
		'label' => _('Ban'),
		'href' => '?display=oryk_provisioner&ban=' . ($banId ?: ''),
	],
];

// One labelled field with its help; $control is already escaped.
$field = function ($id, $label, $control, $help, $hidden = false) use ($h) {
	echo '<div class="element-container" id="' . $h($id) . '-container"' . ($hidden ? ' style="display: none;"' : '') . '>';
	echo '<div class="row"><div class="form-group">';
	echo '<div class="col-md-4"><label class="control-label" for="' . $h($id) . '">' . $h($label) . '</label>';
	echo ' <i class="fa fa-question-circle fpbx-help-icon" data-for="' . $h($id) . '"></i></div>';
	echo '<div class="col-md-8">' . $control . '</div>';
	echo '</div></div>';
	echo '<div class="row"><div class="col-md-12"><span class="help-block fpbx-help-block" id="' . $h($id) . '-help">' . $help . '</span></div></div>';
	echo '</div>';
};

// A read-only line: label, then what is already escaped.
$fact = function ($label, $html) use ($h) {
	echo '<div class="element-container"><div class="row"><div class="form-group">';
	echo '<div class="col-md-4"><label class="control-label">' . $h($label) . '</label></div>';
	echo '<div class="col-md-8"><p class="form-control-static">' . $html . '</p></div>';
	echo '</div></div></div>';
};

$options = function (array $choices, $selected) use ($h) {
	$html = '';

	foreach ($choices as $key => $text) {
		$html .= '<option value="' . $h($key) . '"' . ((string) $key === (string) $selected ? ' selected' : '') . '>' . $h($text) . '</option>';
	}

	return $html;
};

$userChoices = ['' => _('Any user')];

foreach ($users as $user) {
	$userChoices[(string) $user['extension']] = $user['extension'] . (!empty($user['name']) ? ' - ' . $user['name'] : '');
}

$clientChoices = ['' => _('Any client')];

foreach ($clients as $client) {
	$clientChoices[(string) $client['id']] = ($client['mac'] ?: '#' . $client['id']) . (!empty($client['description']) ? ' - ' . $client['description'] : '');
}

// Where this ban is in fail2ban, as far as the last sync confirmed.
$syncFact = '<span class="text-muted">' . $h(_('Not in fail2ban')) . '</span>';

if ($banId && !\FreePBX\Modules\Oryk_Provisioner\BanSync::ipOnly($ban)) {
	$syncFact = '<span class="text-muted">' . $h(_('Not synced: fail2ban only takes bans that name an IP address and nothing else.')) . '</span>';
} elseif ($banId && !empty($ban['managed']) && !empty($ban['active'])) {
	// fail2ban's own ban, which the sync follows until a person saves this page.
	$syncFact = $h(sprintf(_('fail2ban\'s own ban in the %s jail, followed by the sync'), $ban['jail'] ?: '?'))
		. ' <span class="text-muted">' . $h(_('(saving this page makes it yours)')) . '</span>';
} elseif ($banId && !empty($ban['managed'])) {
	$syncFact = $h(sprintf(_('fail2ban\'s ban in the %s jail, which fail2ban has lifted'), $ban['jail'] ?: '?'))
		. ' <span class="text-muted">' . $h(_('(back in force here if fail2ban bans the address again)')) . '</span>';
} elseif ($banId && !empty($ban['synced_at'])) {
	$where = [
		'allow' => _('On the managed jails\' ignore lists'),
		'deny' => _('Banned in the deny jail'),
		'banned' => _('Banned in the banned jail'),
	][$state] ?? '';
	$syncFact = $h($where) . ' <span class="text-muted">' . $h(sprintf(_('confirmed %s'), $ban['synced_at'])) . '</span>';
}

$profileChoices = ['' => _('Any profile')];

foreach ($profiles as $profile) {
	$profileChoices[(string) $profile['id']] = $profile['name'] . ((int) ($profile['enabled'] ?? 1) ? '' : ' ' . _('(disabled)'));
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
					<div class="tab-pane oryk-tab-section active" id="oryk_ban">

						<?php
						$field('ban_ip', _('IP Address'),
							'<input type="text" class="form-control oryk-name" id="ban_ip" maxlength="45" autocomplete="off" spellcheck="false" placeholder="' . $h(_('Any address')) . '" value="' . $h($ban['ip'] ?? '') . '">',
							'<span class="oryk-help-part">' . $h(_('One IPv4 or IPv6 address, as the request arrives from it. Ranges are not accepted. On its own it matches every phone behind that address; with a user or client, it is where that user or client is allowed or refused from. Loopback cannot be banned.')) . '</span>'
							. '<span class="oryk-help-part">' . $h(_('With Fail2ban Sync on, a ban naming an address and nothing else also reaches fail2ban: Banned is banned in the banned jail until it expires, Deny in the deny jail until it is deleted -- both on every port -- and Allow goes on the ignore lists of the PBX jails setup chose, never sshd\'s: an allowed address is still watched there.')) . '</span>'
						);

						$field('ban_mac', _('MAC Address'),
							'<input type="text" class="form-control oryk-name" id="ban_mac" maxlength="17" autocomplete="off" spellcheck="false" placeholder="' . $h(_('Any MAC')) . '" value="' . $h($ban['mac'] ?? '') . '">',
							$h(_('Twelve hexadecimal digits, with or without separators. It need not belong to a client: a MAC nobody has added yet can be refused too.'))
						);

						$field('ban_user', _('User'),
							'<select class="form-control" id="ban_user">' . $options($userChoices, $extension) . '</select>',
							$h(_('Every client whose device or extension is this number, and open provisioning with this number as its username. The ban names the number: renumbering the user leaves it behind.'))
						);

						$field('ban_client', _('Client'),
							'<select class="form-control" id="ban_client">' . $options($clientChoices, $clientId) . '</select>',
							$h(_('This client, whatever MAC it is given later. Deleting the client deletes the ban.'))
						);

						$field('ban_profile', _('Profile'),
							'<select class="form-control" id="ban_profile">' . $options($profileChoices, $profileId) . '</select>',
							$h(_('Every client served this profile: assigned it, or given it for its vendor because it has none. A file fetched by name with no client behind it is not matched. Deleting the profile deletes the ban.'))
						);

						$field('ban_state', _('State'),
							'<select class="form-control" id="ban_state">' . $options([
								'banned' => _('Banned (for a while)'),
								'deny' => _('Deny (until deleted)'),
								'allow' => _('Allow (until deleted)'),
							], $state) . '</select>',
							'<span class="oryk-help-part">' . $h(_('Banned and Deny refuse every request that matches with a 403, written to the Logs tab. Banned stops refusing when its minutes are up; the row stays, expired, until it is deleted, and saving it again with minutes starts it over. Deny stays in force until it is deleted.')) . '</span>'
							. '<span class="oryk-help-part">' . $h(_('Allow wins over every less specific ban that matches: an allowed client is served from a banned address, and allowing user 1001 from one address beats denying user 1001. It changes nothing else -- a disabled client, a token or a profile still decide as they do.')) . '</span>'
						);

						$field('ban_minutes', _('Ban For (minutes)'),
							'<input type="number" class="form-control" id="ban_minutes" min="1" max="' . (int) \FreePBX\Modules\Oryk_Provisioner\Bans::MAX_MINUTES . '" step="1" value="' . (int) $minutes . '">',
							$h(_('How long from this save. 60 is an hour, 1440 a day, 10080 a week. Saving an existing ban starts its time again from now.')),
							$state !== 'banned'
						);

						$field('ban_note', _('Note'),
							'<input type="text" class="form-control" id="ban_note" maxlength="255" value="' . $h($ban['note'] ?? '') . '">',
							$h(_('Why, for whoever reads this list next. Not shown to the phone.'))
						);

						$field('ban_source', _('Source'),
							'<input type="text" class="form-control oryk-name" id="ban_source" maxlength="32" autocomplete="off" spellcheck="false" placeholder="' . $h(\FreePBX\Modules\Oryk_Provisioner\Bans::SOURCE_MANUAL) . '" value="' . $h($ban['source'] ?? \FreePBX\Modules\Oryk_Provisioner\Bans::SOURCE_MANUAL) . '">',
							$h(_('What created this ban: manual for one added here, or the name of whatever adds bans on its own, such as fail2ban. Up to 32 letters, digits, dashes and underscores; blank is manual. Adding the same ban again keeps the source it already has.'))
						);

						$field('ban_jail', _('Jail'),
							'<input type="text" class="form-control oryk-name" id="ban_jail" maxlength="64" autocomplete="off" spellcheck="false" placeholder="' . $h(_('None')) . '" value="' . $h($ban['jail'] ?? '') . '">',
							$h(_('The fail2ban jail, or other rule, that fired, when there is one -- asterisk, for instance. Up to 64 letters, digits, dots, dashes and underscores. Adding the same ban again keeps the jail it already has.'))
						);

						if ($banId) {
							$fact(_('Created'), $h($ban['created_at']) . ' <span class="text-muted" data-oryk-since="' . (int) $ban['created_age'] . '"></span>');
							$fact(_('fail2ban'), $syncFact);
							$fact(_('Hits'), (int) $ban['hits'] . ($ban['last_hit_at'] === null
								? ' <span class="text-muted">' . $h(_('never hit')) . '</span>'
								: ' <span class="text-muted">' . $h(_('last')) . ' ' . $h($ban['last_hit_at']) . '</span> <span class="text-muted" data-oryk-since="' . (int) $ban['last_hit_age'] . '"></span>'));
							$fact(_('Times'), (int) $ban['times'] . ($ban['started_at'] === null ? '' : ' <span class="text-muted">'
								. $h(sprintf(empty($ban['active']) ? _('last began %s') : _('current since %s'), $ban['started_at'])) . '</span>'));

							if ($state === 'banned') {
								$fact(_('Expires'), $h($ban['expires_at']) . ' <span class="text-muted" data-oryk-in="' . (int) $ban['expires_in'] . '"></span>'
									. ((int) $ban['expires_in'] <= 0 ? ' <span class="label label-default">' . $h(_('expired')) . '</span>' : ''));
							}

							if (!empty($ban['ip_client'])) {
								$fact(_('Public IP Of'), '<a href="?display=oryk_provisioner&amp;client=' . (int) $ban['ip_client_id'] . '">' . $h($ban['ip_client']) . '</a>');
							}
						}
						?>

					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<script>
	const orykBanId = <?php echo $banId; ?>;
	const orykBanAddresses = <?php echo json_encode((object) $addresses); ?>;
	const orykBanRemote = <?php echo json_encode((string) ($remote ?? '')); ?>;
</script>
<?php echo $script('ban'); ?>
