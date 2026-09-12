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
 * Client is the MAC, the FreePBX device behind it, the profile it is
 * assigned and where the phone is -- the two addresses, which are written
 * down here rather than discovered. Resources is what that comes to: the
 * files its profile serves, each with the filename this client asks for and
 * a link that fetches it as this client would -- the resource editor's
 * Clients tab read from the other end, and the tab to open when a particular
 * client is not getting what it should.
 *
 * Logs is what actually happened: every file this phone has asked the
 * endpoint for and how each one went. It is narrowed by MAC rather than by
 * this row's id, so requests logged before anybody wrote this client are on
 * it too -- which is usually the run of 404s that says what the phone has
 * been asking for all along. It needs no profile assigned for the same
 * reason: a client with nothing to serve it is the one being refused.
 *
 * The bare ?client=<id> *is* the Client tab, the way ?profile=<id> is the
 * profile editor's first tab; Resources and Logs name themselves with &tab=.
 * Each tab is a link and only the tab asked for is rendered -- see
 * partials/tabs.php.
 *
 * @var array<string, mixed>              $client         id (0 when new), mac, device_id, profile_id, token, enabled, last_seen, public_ip, private_ip
 * @var array<int, array<string, mixed>>  $freepbxDevices What the FreePBX device select offers
 * @var array<int, array<string, mixed>>  $profiles       What the profile select offers
 * @var array<string, int>                $counts         Rows behind each tab -- see partials/counts.php
 * @var array<string, bool>               $available      Which of the other tabs have anything on them
 * @var string                            $tab            Tab to open on: client|resources|logs
 * @var array<int, array<string, mixed>>  $navigator Levels the navigator draws -- see partials/navigator.php
 */

$client = $client ?? ['id' => 0, 'mac' => '', 'device_id' => '', 'profile_id' => 0, 'enabled' => 1];
$freepbxDevices = $freepbxDevices ?? [];
$profiles = $profiles ?? [];
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

// Where the phone is, as somebody wrote it down: the address it answers its
// own web interface on, and the address the site it sits behind is reached at.
// Nothing here discovers either, and nothing in the module connects to them --
// the private one is what the Clients list offers a link to, and both are
// renderable in a template. Stored validated, so what comes back is an
// address or nothing.
$publicIp = (string) ($client['public_ip'] ?? '');
$privateIp = (string) ($client['private_ip'] ?? '');

// The stored hash, shown as it stands. A save writes back whatever is in the
// field, so leaving it alone leaves the token alone and emptying it takes the
// token away -- and a value with a colon in it is a new token to hash.
$token = (string) ($client['token'] ?? '');

// Whether the endpoint answers this client at all. A client that has not been
// written yet is enabled: somebody adding one is adding one to serve.
$enabled = !isset($client['enabled']) || (int) $client['enabled'] === 1;

// When the endpoint last answered this client with a 200. Read with the row
// and shown rather than edited -- it is written by the phone asking, and
// there is nothing here for anybody to set it to.
//
// The timestamp itself, where the Clients list shows how long ago it was: a
// list is scanned, and "2 hours ago" is what scanning wants; this page is one
// client somebody has already found, and the exact moment is what is left to
// ask. It is the PBX's own clock, the same one the Logs tab prints.
$lastSeen = trim((string) ($client['last_seen'] ?? ''));

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

// Two scopes on one page: what is served comes from the profile this client
// is assigned to, what was asked for is kept against its MAC.
$countScope = ['profile_id' => $profileId, 'mac' => $mac];

// The strip, as links. The first tab is the bare ?client=<id>, the way every
// other editor's first tab is the bare URL of the row it edits -- and on a
// client that has not been written it is the key present and empty, since
// `client=0` names a row that does not exist and is bounced to the list.
$clientUrl = '?display=oryk_provisioner&client=' . ($isNew ? '' : $id);

$tabs = [
	'client' => [
		'label' => _('Client'),
		'href' => $clientUrl,
	],
	'resources' => [
		'label' => _('Resources'),
		'href' => $clientUrl . '&tab=resources',
		'count' => 'resources',
		'disabled' => !$served,
		'title' => $served ? '' : ($isNew
			? _('Save the client first -- what it is served follows from the profile it is assigned to.')
			: _('Assign a profile first -- nothing is served to a client without one.')),
	],
	'logs' => [
		'label' => _('Logs'),
		'href' => $clientUrl . '&tab=logs',
		'count' => 'logs',
		'disabled' => !$logged,
		'title' => $logged ? '' : _('Save the client first -- the log is kept by MAC address.'),
	],
];
?>
<?php include __DIR__ . '/partials/editor.php'; ?>
<?php include __DIR__ . '/partials/counts.php'; ?>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<?php include __DIR__ . '/partials/navigator.php'; ?>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<?php include __DIR__ . '/partials/tabs.php'; ?>

				<div class="tab-content">

					<?php if ($tab === 'client'): ?>
					<div class="tab-pane oryk-tab-section active" id="oryk_client">

						<p class="help-block fpbx-help-block">
							<?php echo _('ATTENTION! Client resources are public by default. To restrict access, assign a custom token or use <code>username:password</code> to generate a hashed token.'); ?>
						</p>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="client_mac"><?php echo _('MAC Address'); ?></label>
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
										<?php echo _('The address the client provisions with. Stored as 12 lowercase hexadecimal characters; separators are removed. Unique -- a MAC is associated once. Optional: a client can be written before anybody has read the label off the handset, and one without a MAC is simply never reached -- the endpoint finds a client by the MAC in the path a phone asks with, so there is nothing that could arrive for it until one is filled in. Typing something that is not a MAC is still refused; it is the empty box that is allowed.'); ?>
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
												<?php
												// A profile that has been switched off is still offered
												// -- a client being set up against a profile that is not
												// in service yet is assigned to it -- but it says so, or
												// the select would offer it as though it were serving.
												$profileOff = isset($profile['enabled']) && (int) $profile['enabled'] !== 1;
												?>
												<option value="<?php echo (int) $profile['id']; ?>"
													<?php echo (int) $profile['id'] === $profileId ? 'selected' : ''; ?>>
													<?php echo $h($profile['name']) . ($profileOff ? ' ' . $h(_('(disabled)')) : ''); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('What this client is served. Until one is assigned there is nothing to provision, and the client is asked for a configuration it has none of. A profile marked disabled is switched off and serves nothing, however this client is set.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="client_private_ip"><?php echo _('Private IP'); ?></label>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control" id="client_private_ip"
											autocomplete="off" spellcheck="false" placeholder="192.168.1.50"
											value="<?php echo $h($privateIp); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('Where this phone is on the local network. Optional, and written down here rather than discovered -- nothing in the module reaches a phone, so nothing can fill it in. Given one, the Clients list grows a button on this row that opens the phone\'s own web interface at that address in a new tab, which is the page you want when a handset needs looking at directly. An IPv4 or IPv6 address; anything else is refused, since the address goes into a link.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="client_public_ip"><?php echo _('Public IP'); ?></label>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control" id="client_public_ip"
											autocomplete="off" spellcheck="false" placeholder="203.0.113.24"
											value="<?php echo $h($publicIp); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('The address the site this phone sits behind is reached at from outside. Optional, and kept for reference: nothing is served differently because of it and there is no button for it -- a public address is usually the router rather than the handset. Both addresses are searched on the Clients list, and both can be rendered into a configuration as {{client.public_ip}} and {{client.private_ip}}.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="client_token"><?php echo _('Token'); ?></label>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control oryk-token" id="client_token"
											autocomplete="off" spellcheck="false"
											placeholder="<?php echo $h(_('username:password')); ?>"
											value="<?php echo $h($token); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('A secret this client proves itself with. Type it as -- username:password -- and it is hashed when you save; what the box holds from then on is that hash, which is why leaving it alone leaves the token alone. Empty the box to take the token away. While one is set, the endpoint answers this client nothing until it presents those credentials: a request without them, or with the wrong ones, is a 401. A client with no token is served to anyone who knows its MAC address.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="client_enabled"><?php echo _('Status'); ?></label>
									</div>
									<div class="col-md-8">
										<select class="form-control" id="client_enabled">
											<option value="1" <?php echo $enabled ? 'selected' : ''; ?>><?php echo _('Enabled'); ?></option>
											<option value="0" <?php echo $enabled ? '' : 'selected'; ?>><?php echo _('Disabled'); ?></option>
										</select>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('Whether the endpoint answers this client. Disabled, every request it makes is refused -- its profile\'s files, anything served by name, and any log it tries to send -- without the client being deleted or its configuration touched. What it asks for while it is off is still recorded on the Logs tab, which is usually the point of switching it off. The same switch is on the row on the Clients list.'); ?>
									</span>
								</div>
							</div>
						</div>

						<?php if (!$isNew): ?>
						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label"><?php echo _('Last Seen'); ?></label>
									</div>
									<div class="col-md-8">
										<p class="form-control-static">
											<?php if ($lastSeen !== ''): ?>
												<?php echo $h($lastSeen); ?>
											<?php else: ?>
												<span class="text-muted"><?php echo _('Never'); ?></span>
											<?php endif; ?>
										</p>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('The last time this client asked the endpoint for something and was given it -- a file served, a configuration rendered, or a log received. A refused request does not count: a phone that is switched off, or asking for a file its profile does not serve, is reaching the PBX and getting nothing, and those are on the Logs tab. Never means nothing has been served to this client since it was written.'); ?>
									</span>
								</div>
							</div>
						</div>
						<?php endif; ?>

					</div>

					<?php endif; ?>

					<?php if ($tab === 'resources'): ?>
						<div class="tab-pane oryk-tab-section active" id="oryk_resources">

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
								data-show-refresh="true"
								data-unique-id="id"
								data-sort-name="name"
								data-sort-order="asc">
								<thead>
									<tr>
										<th data-field="name" data-formatter="formatResourceName" data-sortable="true"><?php echo _('Resource'); ?></th>
										<th data-field="type" data-formatter="formatResourceKind" data-sortable="true"><?php echo _('Type'); ?></th>
										<th data-field="updated_at" data-formatter="formatResourceText" data-sortable="true"><?php echo _('Updated'); ?></th>
										<th data-field="actions" data-formatter="formatResourceActions"><?php echo _('Actions'); ?></th>
									</tr>
								</thead>
							</table>

						</div>
					<?php endif; ?>

					<?php if ($tab === 'logs'): ?>
						<div class="tab-pane oryk-tab-section active" id="oryk_logs">
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

	orykEditor({
		save: 'saveClient',
		remove: 'deleteClient',
		confirm: 'Delete this client? Any logs it has sent go with it.',
		values: function () {
			return {
				id: orykClientId,
				mac: $('#client_mac').val(),
				device_id: $('#client_device_id').val(),
				profile_id: $('#client_profile_id').val(),
				private_ip: $('#client_private_ip').val(),
				public_ip: $('#client_public_ip').val(),
				// Sent as it stands, hash or typed token: which one it is, is
				// saveClient()'s question, and a colon is how it answers it.
				token: $('#client_token').val(),
				enabled: $('#client_enabled').val()
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
