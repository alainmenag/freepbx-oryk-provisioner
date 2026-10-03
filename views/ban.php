<?php
/**
 * views/ban.php -- one fail2ban ban, or a new one.
 *
 * Reached at ?display=oryk_provisioner&jail=<jail>&ban=<ip>, or &ban=
 * (present, empty) for a new one, optionally with &ip= filled in. A ban is
 * fail2ban's, not a row of this module's -- see ARCHITECTURE.md, "Bans" -- and
 * it cannot be edited, only lifted: an existing one is shown, not fielded, and
 * its action bar is Unban and Close.
 *
 * @var array<string, mixed>|null           $ban       The ban open, from Bans::banRow(); null when new
 * @var array<int, string>                  $jails     What a new ban can be added to
 * @var string                              $prefill   An address to start a new ban with
 * @var array<string, array<string, mixed>> $clients   Bans::clientAddresses(): clients by public address
 * @var string                              $remote    The address this page was asked from
 * @var array<int, array<string, mixed>>    $navigator Levels the navigator draws -- see partials/navigator.php
 * @var array<int, array<string, mixed>>    $sections Navigator::sections() -- see partials/sections.php
 */

$ban = isset($ban) && is_array($ban) ? $ban : null;
$jails = isset($jails) && is_array($jails) ? $jails : [];
$clients = isset($clients) && is_array($clients) ? $clients : [];
$isNew = $ban === null;

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$tab = 'ban';
$tabs = [
	'ban' => [
		'label' => _('Ban'),
		'href' => $isNew
			? '?display=oryk_provisioner&ban='
			: '?display=oryk_provisioner&jail=' . rawurlencode($ban['jail']) . '&ban=' . rawurlencode($ban['ip']),
	],
];

// A read-only line of the existing ban: label, then what is already escaped.
$fact = function ($label, $html) use ($h) {
	echo '<div class="element-container"><div class="row"><div class="form-group">';
	echo '<div class="col-md-4"><label class="control-label">' . $h($label) . '</label></div>';
	echo '<div class="col-md-8"><p class="form-control-static">' . $html . '</p></div>';
	echo '</div></div></div>';
};
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<?php include __DIR__ . '/partials/sections.php'; ?>
			<?php include __DIR__ . '/partials/navigator.php'; ?>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<?php include __DIR__ . '/partials/tabs.php'; ?>

				<div class="tab-content">
					<div class="tab-pane oryk-tab-section active" id="oryk_ban">

						<?php if ($isNew): ?>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="ban_jail"><?php echo _('Jail'); ?></label> <i class="fa fa-question-circle fpbx-help-icon" data-for="ban_jail"></i>
									</div>
									<div class="col-md-8">
										<select class="form-control" id="ban_jail">
											<?php foreach ($jails as $jail): ?>
											<option value="<?php echo $h($jail); ?>"<?php echo $jail === 'asterisk' ? ' selected' : ''; ?>><?php echo $h($jail); ?></option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="ban_jail-help">
										<?php echo _('Which of fail2ban\'s jails to ban it in. asterisk blocks SIP; sshd blocks SSH. The ban lasts the jail\'s own bantime, as if fail2ban had banned it itself.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="ban_ip"><?php echo _('IP Address'); ?></label> <i class="fa fa-question-circle fpbx-help-icon" data-for="ban_ip"></i>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control oryk-name" id="ban_ip" maxlength="45"
											autocomplete="off" spellcheck="false" placeholder="203.0.113.7"
											value="<?php echo $h($prefill ?? ''); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="ban_ip-help">
										<span class="oryk-help-part"><?php echo _('One IPv4 or IPv6 address. Ranges are not accepted.'); ?></span>
										<span class="oryk-help-part"><?php echo _('Refused: the address you are connected from, loopback, and this PBX\'s own addresses. An address written on a client as its public address is allowed, after a warning: it blocks every phone at that site.'); ?></span>
									</span>
								</div>
							</div>
						</div>

						<?php else: ?>

						<?php
						$fact(_('IP Address'), '<span class="oryk-name">' . $h($ban['ip']) . '</span>');
						$fact(_('Jail'), $h($ban['jail']));
						$fact(_('Banned'), $h($ban['banned']) . ' <span class="text-muted" data-oryk-since="' . (int) $ban['banned_age'] . '"></span>');
						$fact(_('Expires'), $ban['permanent']
							? $h(_('Never: this jail bans permanently'))
							: $h((string) $ban['expires']) . ' <span class="text-muted" data-oryk-in="' . (int) $ban['expires_in'] . '"></span>');
						$fact(_('Client'), $ban['client_id']
							? '<a href="?display=oryk_provisioner&amp;client=' . (int) $ban['client_id'] . '">' . $h($ban['client']) . '</a> <span class="text-muted">' . $h(_('this address is the client\'s public address')) . '</span>'
							: '-');
						?>

						<p class="help-block"><?php echo _('A ban cannot be edited. Unban lifts it now; otherwise fail2ban lifts it when it expires.'); ?></p>

						<?php endif; ?>

					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<script>

	const orykBan = <?php echo json_encode($isNew ? null : ['id' => $ban['id'], 'jail' => $ban['jail'], 'ip' => $ban['ip']]); ?>;
	const orykBanClients = <?php echo json_encode((object) $clients); ?>;
	const orykBanRemote = <?php echo json_encode((string) ($remote ?? '')); ?>;

	// Seconds as a coarse age, the way the lists say it.
	function orykBanAge(seconds) {
		const units = [[31536000, 'year'], [2592000, 'month'], [86400, 'day'], [3600, 'hour'], [60, 'minute']];
		const age = Math.abs(Number(seconds) || 0);

		for (const [size, name] of units) {
			if (age >= size) {
				const count = Math.floor(age / size);

				return `${count} ${name}${count === 1 ? '' : 's'}`;
			}
		}

		return '';
	}

	$('[data-oryk-since]').each(function () {
		const age = orykBanAge($(this).data('oryk-since'));
		$(this).text(age ? `(${age} ago)` : '(just now)');
	});

	$('[data-oryk-in]').each(function () {
		const left = orykBanAge($(this).data('oryk-in'));
		$(this).text(left ? `(in ${left})` : '(any moment)');
	});

	// Registered before orykEditor(), so a declined warning stops its Save. The
	// server refuses the address you are connected from on its own; a client's
	// public address is only warned about, because it can be the right thing.
	$(document).on('click', '#oryksave', function (event) {
		const ip = $.trim($('#ban_ip').val()).toLowerCase();
		const client = orykBanClients[ip];

		if (ip !== '' && ip === orykBanRemote) {
			return;
		}

		if (client && !window.confirm(`${ip} is the public address of ${client.label}${client.count > 1 ? ` and ${client.count - 1} more` : ''}. Banning it blocks every phone at that site. Ban it anyway?`)) {
			event.preventDefault();
			event.stopImmediatePropagation();
		}
	});

	orykEditor({
		save: 'saveBan',
		remove: 'deleteBan',
		confirm: orykBan ? `Unban ${orykBan.ip} from ${orykBan.jail}?` : '',
		values: function () {
			return {
				id: orykBan ? orykBan.id : '',
				jail: $('#ban_jail').val(),
				ip: $('#ban_ip').val()
			};
		},
		// The key is jail/ip; a jail cannot hold a slash.
		page: function (id) {
			const slash = String(id).indexOf('/');

			return '?display=oryk_provisioner&jail=' + encodeURIComponent(String(id).slice(0, slash))
				+ '&ban=' + encodeURIComponent(String(id).slice(slash + 1));
		},
		closed: '?display=oryk_provisioner&tab=bans'
	});

</script>
