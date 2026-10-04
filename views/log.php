<?php
/**
 * views/log.php -- one provisioning log entry, read-only.
 *
 * Reached at ?display=oryk_provisioner&log=<id>. Nothing here is edited: the
 * action bar is Delete and Close, and the bans offered are new pages with this
 * entry's address or MAC filled in.
 *
 * @var array<string, mixed>             $entry     ProvisioningLog::logRow()
 * @var array<int, array<string, mixed>> $navigator Levels the navigator draws -- see partials/navigator.php
 * @var array<int, array<string, mixed>> $sections  Navigator::sections() -- see partials/sections.php
 * @var string                           $version   Module version -- see partials/sections.php
 */

use FreePBX\Modules\Oryk_Provisioner\Bans;

$entryId = (int) $entry['id'];
$mac = (string) $entry['mac'];
$ip = (string) ($entry['ip'] ?? '');
$status = (int) $entry['status'];
$method = (string) $entry['method'];

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$tab = 'log';
$tabs = [
	'log' => [
		'label' => _('Log Entry'),
		'href' => '?display=oryk_provisioner&log=' . $entryId,
	],
];

// A read-only line: label, then what is already escaped.
$fact = function ($label, $html) use ($h) {
	echo '<div class="element-container"><div class="row"><div class="form-group">';
	echo '<div class="col-md-4"><label class="control-label">' . $h($label) . '</label></div>';
	echo '<div class="col-md-8"><p class="form-control-static flex" style="gap: 5px;">' . $html . '</p></div>';
	echo '</div></div></div>';
};

// A ban is offered only for what Bans::value() would store.
$banIp = Bans::value('ip', $ip);
$banMac = Bans::value('mac', $mac);
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
					<div class="tab-pane oryk-tab-section active" id="oryk_log">

						<?php
						$fact(_('Time'), $h($entry['created_at']));

						$fact(_('Status'), '<span class="label ' . ($status >= 200 && $status < 300 ? 'label-success' : 'label-danger') . '">'
							. $h($status ?: '-') . '</span>' . ($method !== '' ? ' <span class="text-muted">' . $h($method) . '</span>' : ''));

						$fact(_('Requested'), (string) $entry['filename'] !== ''
							? '<code>' . $h($entry['filename']) . '</code>'
							: '<span class="text-muted">' . $h(_('(main config)')) . '</span>');

						if ((string) ($entry['message'] ?? '') !== '') {
							$fact(_('Message'), '<span class="text-danger">' . $h($entry['message']) . '</span>');
						}

						$fact(_('MAC Address'), $mac === '' ? '-' : '<code>' . $h($mac) . '</code>');

						$fact(_('Client'), $entry['client_id']
							? '<a href="?display=oryk_provisioner&amp;client=' . (int) $entry['client_id'] . '">' . $h($entry['description'] ?: $mac) . '</a>'
							: '<span class="text-muted">' . $h(_('Not associated')) . '</span>');

						$fact(_('From'), $ip !== '' ? $h($ip) : '-');

						$fact(_('User-Agent'), (string) ($entry['user_agent'] ?? '') !== '' ? $h($entry['user_agent']) : '-');

						$offers = [];

						if ($banIp !== null) {
							$offers[] = '<a class="btn btn-default" href="?display=oryk_provisioner&amp;ban=&amp;ban_ip=' . $h(rawurlencode($banIp)) . '">' . $icon('ban') . ' ' . $h(_('Ban This Address')) . '</a>';
						}

						if ($banMac !== null) {
							$offers[] = '<a class="btn btn-default" href="?display=oryk_provisioner&amp;ban=&amp;ban_mac=' . $h(rawurlencode($banMac)) . '">' . $icon('ban') . ' ' . $h(_('Ban This MAC')) . '</a>';
						}

						if ($offers) {
							$fact(_('Ban'), implode(' ', $offers));
						}
						?>

					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<script>

	const orykLogId = <?php echo $entryId; ?>;

	orykEditor({
		save: '',
		remove: 'deleteLog',
		confirm: 'Delete this log entry?',
		values: function () {
			return { id: orykLogId };
		},
		page: function () {
			return '?display=oryk_provisioner&log=' + orykLogId;
		},
		closed: '?display=oryk_provisioner&tab=logs'
	});

</script>
