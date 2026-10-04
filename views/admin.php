<?php
/**
 * The module page: one pane per section -- Users, Clients, Profiles, Logs,
 * Bans and Settings.
 *
 * Every table is filled by the module's AJAX commands, so nothing on this
 * page is rendered from data: what it is handed is which tab to open and
 * which row was just written, so it can say so. Logs is the provisioning log
 * unnarrowed, and is drawn by partials/logs.php, which the client editor
 * includes too.
 *
 * Neither a client nor a profile is edited here. Both are pages of their own
 * -- views/client.php and views/profile.php -- which the Add and Edit buttons
 * link to. What is left on the list is deletion and the switch, which is the
 * other thing that needs no page: one column, changed on the row it is shown
 * on. The clients table and the profiles table both have one, they mean the
 * same thing -- the endpoint answers this row, or it answers nothing for it
 * -- so both are drawn and handled by the same three functions below. The
 * one action on either table that leaves the PBX is on a client: a link to
 * the phone's own web interface, drawn from the private address written on
 * that client and only on the rows that have one.
 *
 * The sections are the bar every page is topped with -- see
 * partials/sections.php -- so this page has no tab strip of its own. Only the
 * section asked for is rendered. Each names itself (`&tab=<section>`) rather
 * than one being the bare URL, though a bare ?display=oryk_provisioner opens
 * Users, the first of them.
 *
 * Settings is the one tab with fields rather than a table, drawn by
 * partials/settings.php and saved by the action bar's Save.
 *
 * Bans is the bans table: who the endpoint refuses, or answers in spite of a
 * ban -- see ARCHITECTURE.md, "Bans".
 *
 * Opened from a navigator title, a table is narrowed to what that title's badge
 * counted: `$scope` names the row, and every list command is asked with it.
 *
 * @var string             $tab    Section to open on, settled by Navigator::section()
 * @var array<int, array<string, mixed>>  $navigator Levels the navigator draws -- see partials/navigator.php
 * @var array<int, array<string, mixed>>  $sections Navigator::sections() -- see partials/sections.php
 * @var string                            $version  Module version -- see partials/sections.php
 * @var array<int, array<string, mixed>>  $settings  Settings::fields(), on the Settings tab
 * @var array<string, mixed>              $sync      Fail2ban::status(), on the Bans tab
 * @var string                            $remote    The address this page was asked from, canonical
 * @var array<string, string>|null        $scope     Pages::scopeBanner(): what the list is narrowed to, or null
 * @var int                               $expireDays ORYK_OPEN_EXPIRE_DAYS, on the Users tab: Expired is offered above 0
 */

$tab = (string) ($tab ?? 'users');
$sync = isset($sync) && is_array($sync) ? $sync : [];
$scope = isset($scope) && is_array($scope) ? $scope : null;
$expireDays = (int) ($expireDays ?? 0);
// Appended to every list command's URL, so a refresh stays narrowed.
$scopeQuery = htmlspecialchars($scope ? '&scope=' . rawurlencode($scope['key']) : '', ENT_QUOTES, 'UTF-8');
?>

<div class="provisioner container-fluid">
	<div class="fpbx-container">
		<div class="display no-border">

			<?php include __DIR__ . '/partials/sections.php'; ?>
			<?php include __DIR__ . '/partials/navigator.php'; ?>

			<div class="section no-border" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<?php if ($scope): ?>
				<p class="oryk-scope">
					<?php echo sprintf(
						htmlspecialchars(_('Only what is linked to %s.'), ENT_QUOTES, 'UTF-8'),
						'<a href="' . htmlspecialchars($scope['href'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($scope['text'], ENT_QUOTES, 'UTF-8') . '</a>'
					); ?>
					<a class="oryk-scope-all" href="<?php echo htmlspecialchars($scope['all'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo _('Show all'); ?></a>
				</p>
				<?php endif; ?>

				<div class="tab-content">

					<?php if ($tab === 'clients'): ?>
					<div class="tab-pane active" id="oryk_clients">
						<div id="client_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;client=">
								<i class="fa fa-plus"></i> <?php echo _('Add Client'); ?>
							</a>
						</div>

						<table
							id="client_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listClients<?php echo $scopeQuery; ?>"
							data-toolbar="#client_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="id"
							data-row-style="orykRowClasses"
							data-sort-name="mac"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="mac" data-formatter="formatMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
									<th data-field="description" data-formatter="formatText" data-sortable="true"><?php echo _('Description'); ?></th>
									<th data-field="device_id" data-formatter="formatDevice" data-sortable="true"><?php echo _('Device'); ?></th>
									<th data-field="extension" data-formatter="formatExtension" data-sortable="true"><?php echo _('Extension'); ?></th>
									<th data-field="profile" data-formatter="formatClientProfile" data-sortable="true"><?php echo _('Profile'); ?></th>
									<th data-field="secure" data-formatter="formatClientSecure" data-sortable="true"><?php echo _('Secure'); ?></th>
									<th data-field="last_seen" data-formatter="formatClientSeen" data-sortable="true"><?php echo _('Last Seen'); ?></th>
									<th data-field="actions" data-formatter="formatClientActions" data-align="right"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
					</div>

					<?php endif; ?>

					<?php if ($tab === 'profiles'): ?>
					<div class="tab-pane active" id="oryk_profiles">
						<div id="profile_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;profile=">
								<i class="fa fa-plus"></i> <?php echo _('Add Profile'); ?>
							</a>
						</div>

						<table
							id="profile_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listProfiles<?php echo $scopeQuery; ?>"
							data-toolbar="#profile_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="id"
							data-row-style="orykRowClasses"
							data-sort-name="name"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="name" data-formatter="formatProfileName" data-sortable="true" ><?php echo _('Name'); ?></th>
									<th data-field="assigned" data-formatter="formatAssignedClients" data-sortable="true"><?php echo _('Clients'); ?></th>
									<th data-field="actions" data-formatter="formatProfileActions" data-align="right"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
					</div>

					<?php endif; ?>

					<?php if ($tab === 'users'): ?>
					<div class="tab-pane active" id="oryk_users">
						<div id="user_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;user=">
								<i class="fa fa-plus"></i> <?php echo _('Add User'); ?>
							</a>
							<select class="form-control oryk-user-filter" id="user_filter" aria-label="<?php echo _('Show'); ?>">
								<option value=""><?php echo _('All users'); ?></option>
								<option value="lobby"><?php echo _('Lobby'); ?></option>
								<?php if ($expireDays > 0): ?>
								<option value="expired"><?php echo sprintf(_('Expired (unseen %d days)'), $expireDays); ?></option>
								<?php endif; ?>
							</select>
							<button type="button" class="btn btn-danger hidden" id="user_delete_expired">
								<i class="fa fa-trash"></i> <?php echo _('Delete listed'); ?>
							</button>
						</div>

						<table
							id="user_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listUsers<?php echo $scopeQuery; ?>"
							data-toolbar="#user_toolbar"
							data-query-params="orykUserQuery"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="extension"
							data-sort-name="extension"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="extension" data-formatter="formatUserExtension" data-sortable="true"><?php echo _('Extension'); ?></th>
									<th data-field="name" data-formatter="formatText" data-sortable="true"><?php echo _('Name'); ?></th>
									<th data-field="email" data-formatter="formatText" data-sortable="true"><?php echo _('Email'); ?></th>
									<th data-field="clients" data-formatter="formatUserClients" data-sortable="true"><?php echo _('Clients'); ?></th>
									<th data-field="secure" data-formatter="formatClientSecure" data-sortable="true"><?php echo _('Secure'); ?></th>
									<th data-field="context" data-formatter="formatUserContext" data-sortable="true"><?php echo _('Context'); ?></th>
									<th data-field="last_seen" data-formatter="formatClientSeen" data-sortable="true"><?php echo _('Last Seen'); ?></th>
									<th data-field="actions" data-formatter="formatUserActions" data-align="right"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
					</div>

					<?php endif; ?>

					<?php if ($tab === 'logs'): ?>
					<div class="tab-pane active" id="oryk_logs">
						<?php
						// Every request, not just the ones a client was found
						// for -- see the note at the top of the partial.
						$logMac = '';
						$logScope = $scope ? $scope['key'] : '';
						include __DIR__ . '/partials/logs.php';
						?>
					</div>
					<?php endif; ?>

					<?php if ($tab === 'bans'): ?>
					<div class="tab-pane active" id="oryk_bans">
						<div id="ban_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;ban=">
								<i class="fa fa-plus"></i> <?php echo _('Add Ban'); ?>
							</a>
						</div>

						<table
							id="ban_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listBans<?php echo $scopeQuery; ?>"
							data-toolbar="#ban_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="id"
							data-row-style="orykBanRowClasses"
							data-sort-name="created_at"
							data-sort-order="desc">
							<thead>
								<tr>
									<th data-field="ip" data-formatter="formatBanIp" data-sortable="true"><?php echo _('IP'); ?></th>
									<th data-field="mac" data-formatter="formatBanMac" data-sortable="true"><?php echo _('MAC'); ?></th>
									<th data-field="user" data-formatter="formatBanUser" data-sortable="true"><?php echo _('User'); ?></th>
									<th data-field="client" data-formatter="formatBanClient" data-sortable="true"><?php echo _('Client'); ?></th>
									<th data-field="profile" data-formatter="formatBanProfile" data-sortable="true"><?php echo _('Profile'); ?></th>
									<th data-field="state" data-formatter="formatBanState" data-sortable="true"><?php echo _('State'); ?></th>
									<th data-field="hits" data-formatter="formatBanHits" data-sortable="true" data-align="right"><?php echo _('Hits'); ?></th>
									<th data-field="times" data-formatter="formatBanTimes" data-sortable="true" data-align="right"><?php echo _('Times'); ?></th>
									<th data-field="created_at" data-formatter="formatBanCreated" data-sortable="true"><?php echo _('Created'); ?></th>
									<th data-field="expires_at" data-formatter="formatBanExpires" data-sortable="true"><?php echo _('Expires'); ?></th>
									<th data-field="actions" data-formatter="formatBanActions" data-align="right"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
						<?php
						// The fail2ban sync in one line, under the table: what it is doing, or what stops it.
						$syncState = (string) ($sync['state'] ?? '');
						$syncClass = ['ok' => 'success', 'disabled' => 'info'][$syncState] ?? 'warning';
						?>
						<?php if ($syncState !== ''): ?>
						<div class="alert alert-<?php echo $syncClass; ?>" style="margin: 0; clear: both;">
							<i class="fa fa-shield"></i>
							<?php if ($syncState === 'ok'): ?>
								<?php echo htmlspecialchars(sprintf(_('IP bans sync with fail2ban every minute (fail2ban %s): Banned into the banned jail, Deny into deny (both every port), Allow onto the ignore lists of %s only.'), (string) ($sync['fail2ban'] ?? ''), implode(', ', (array) ($sync['jails'] ?? []))), ENT_QUOTES, 'UTF-8'); ?>
							<?php else: ?>
								<strong><?php echo htmlspecialchars((string) ($sync['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
								<?php if (!empty($sync['detail'])): ?>
								<small class="text-muted"><?php echo htmlspecialchars((string) $sync['detail'], ENT_QUOTES, 'UTF-8'); ?></small>
								<?php endif; ?>
								<?php if (in_array($syncState, ['missing', 'sudo', 'stale', 'nojail'], true)): ?>
								<br><?php echo _('Run this once on the PBX, as root:'); ?>
								<code style="user-select: all;"><?php echo htmlspecialchars((string) ($sync['command'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>
								<?php endif; ?>
							<?php endif; ?>
						</div>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<?php if ($tab === 'settings'): ?>
					<div class="tab-pane oryk-tab-section active" id="oryk_settings">
						<?php include __DIR__ . '/partials/settings.php'; ?>
					</div>
					<?php endif; ?>

				</div>

			</div>

		</div>
	</div>
</div>

<script>

	const orykAjax = 'ajax.php?module=oryk_provisioner&command=';

	// Said in one place because the client editor says the same thing about
	// the same client, and the two reading differently would be two answers
	// to one question.
	const orykClientNeverSeen = <?php echo json_encode(_('Never')); ?>;

	// Every call to the module is a POST to ajax.php with the command in the
	// query string, which is what FreePBX dispatches on.
	function orykPost(command, data) {
		return $.ajax({
			url: orykAjax + command,
			type: 'POST',
			data: data,
			dataType: 'json'
		});
	}

	// Safe in element text and inside a quoted attribute alike: quotes are
	// escaped too, which .text().html() never does.
	function orykEscape(value) {
		return String(value === null || value === undefined ? '' : value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	function formatText(value) {
		return value ? orykEscape(value) : '-';
	}

	function formatMac(value, row) {
		return value ? `<a href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>` : '-';
	}

	// The Device column names the FreePBX device; the extension it is attached
	// to is shown alongside it when there is one, and links to that extension.
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

	// The profile as the client's own row sees it. A client assigned to a
	// profile that has been switched off is served nothing, and the client
	// itself looks perfectly fine, so this is the column that has to say so
	// -- this is the row somebody reads before wondering why a phone that is
	// plainly enabled is not provisioning.
	function formatClientProfile(value, row) {
		if (!value) {
			return '-';
		}

		const link = `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.profile_id)}">${orykEscape(value)}</a>`;

		if (Number(row.profile_enabled)) {
			return link;
		}

		return `${link} <span class="label label-default" title="This profile is switched off, so nothing is served to this client">disabled</span>`;
	}

	// Whether the client has a token, which the row is told as a flag rather
	// than by being handed the hash. MySQL answers the comparison with 1 or 0
	// and PDO brings it back as a string, so the truthiness test is on the
	// number rather than on the value as it arrives -- '0' is true.
	function formatClientSecure(value, row) {
		return Number(value) ? 'Yes' : 'No';
	}

	// When the endpoint last answered this client with a 200, which is to say
	// the last time the phone asked for something and got it.
	//
	// Drawn as how long ago rather than as the timestamp, because the
	// question being asked of this column is "is that phone alive", and an
	// answer of "14:32" has to be subtracted from the wall clock before it
	// says anything. The timestamp is the hover title, for when the age is
	// not enough and the exact moment is the point.
	//
	// The age is the server's own subtraction -- see Clients::SEEN_AGE_EXPR.
	// Working it out here from the timestamp would mean parsing a DATETIME
	// written in the PBX's clock as though it were written in the browser's,
	// and reporting a phone that checked in a minute ago as hours out
	// wherever the two differ.
	//
	// A client nothing has ever been served to says so in words. It is the
	// row worth finding on this list: a phone that was set up and has never
	// come back is either not plugged in or not reaching the PBX, and an
	// empty cell would read as a column that had failed to load.
	function formatClientSeen(value, row) {
		if (!value) {
			return `<span class="text-muted" title="Nothing has been served to this client yet">${orykEscape(orykClientNeverSeen)}</span>`;
		}

		return `<span title="${orykEscape(value)}">${orykEscape(orykSince(row.last_seen_age))}</span>`;
	}

	// A number of seconds, as the largest unit that still says something
	// useful. Deliberately coarse: this is read to tell a phone that checked
	// in this morning from one that stopped answering in March, and
	// "3 days ago" does that where "3 days, 4 hours and 11 minutes ago" only
	// makes the column wider.
	//
	// A negative age is a clock that has been moved back under a row that was
	// written before it; it reads as just now rather than as the future.
	function orykSince(seconds) {
		const age = Math.max(0, Number(seconds) || 0);

		const units = [
			[31536000, 'year'],
			[2592000, 'month'],
			[86400, 'day'],
			[3600, 'hour'],
			[60, 'minute']
		];

		for (const [size, name] of units) {
			if (age >= size) {
				const count = Math.floor(age / size);

				return `${count} ${name}${count === 1 ? '' : 's'} ago`;
			}
		}

		return 'just now';
	}

	function formatProfileName(value, row) {
		if (!value) {
			return '-';
		}
		return `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>`;
	}

	function formatAssignedClients(value, row) {
		if (!value) {
			return '0';
		}
		return `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.id)}&tab=clients">${orykEscape(value)}</a>`;
	}

	// The switch, which is the same control on both tables: a client and a
	// profile are switched off by the same column and mean the same thing by
	// it, so one function draws it and one handler answers it. What tells
	// them apart is carried on the button -- the command to post and the
	// table to redraw -- rather than by there being two of everything.
	//
	// It is a button rather than a link for the reason Delete is one: it
	// changes the row it is on, and the row is already on screen. It draws
	// the state the row is *in* rather than the state it would put it in -- a
	// control that shows what is true reads the same whether you are reading
	// the list or using it -- so the title is what says what pressing it
	// does.
	// The consequence is said only by the press that causes it: switching a
	// row off is what needs explaining, and "Enable this client -- everything
	// it asks for is refused" would be the same sentence saying the opposite
	// of what it means. The hover title and the question asked before it
	// happens are built from that one clause rather than from two copies of
	// it that could come to disagree.
	//
	// It asks in both directions, though only one of them takes anything
	// away: the button is one press beside Edit and Delete, it is the only
	// control on either table that changes a row without opening it, and a
	// switch that silently stopped a floor of phones the moment it was
	// brushed would be the wrong kind of quick. The question is carried on
	// the button so the handler, which knows nothing about what it is
	// switching, has nothing to compose.
	function orykSwitch(row, command, table, noun, refused) {
		const enabled = Number(row.enabled);

		const title = enabled ? `Disable this ${noun} -- ${refused}` : `Enable this ${noun}`;

		const ask = enabled
			? `Disable this ${noun}? Until you switch it back on, ${refused}.`
			: `Enable this ${noun}? Provisioning resumes from the next request.`;

		return [
			`<button type="button" class="btn btn-sm ${enabled ? 'btn-success' : 'btn-default'}"`,
			` name="oryk_enabled" value="${row.id}" data-enabled="${enabled ? 1 : 0}"`,
			` data-command="${command}" data-table="${table}" data-confirm="${ask}"`,
			` title="${title}">`,
			`<i class="fa ${enabled ? 'fa-toggle-on' : 'fa-toggle-off'}" style="margin: 0;"></i>`,
			`</button>`
		].join('');
	}

	// What a row has to say about itself before anything has been read out of
	// it: that it has been switched off. Both tables are drawn with it, for the
	// reason they share the switch -- a client and a profile are switched off
	// the same way and have to read the same way.
	function orykRowClasses(row) {
		return Number(row.enabled) ? {} : { classes: 'oryk-disabled' };
	}

	// The phone's own web interface, at the private address written on the
	// client. Plain http, because that is what a handset answers on out of
	// the box, and the address is stored as a bare address rather than as a
	// URL so there is no field for a scheme to arrive through.
	//
	// The address is validated when it is saved -- see Clients::address() --
	// and it is checked again here, because this is where it becomes an href
	// and an href is the one place where being wrong about it would matter.
	// Anything that is not an address returns no URL and draws no button,
	// rather than a link that goes somewhere unintended.
	//
	// Nothing about this is a reachability check: the module has never
	// connected to a phone and does not here either. The button opens a tab
	// and the browser finds out, which is the only thing on this page that
	// is on the same network as the handset.
	function orykPhoneUrl(address) {
		if (!/^[0-9A-Fa-f.:]+$/.test(address || '')) {
			return '';
		}

		// An IPv6 address is bracketed in a URL, or its colons read as a port.
		return 'http://' + (address.indexOf(':') === -1 ? address : `[${address}]`);
	}

	// Editing a client is a page, not a dialog, so Edit is a link: the
	// row's id is the whole of what the editor needs, and it reads the
	// client back itself rather than being handed one. Beside it are the two
	// things that need no page -- the switch, and deletion -- and, on a
	// client somebody has written an address for, the way to the phone
	// itself. That one is drawn only when there is an address to draw it
	// from: a button that led nowhere on most rows would be worse than no
	// button, and the column says as much by being shorter.
	function formatClientActions(value, row) {
		const actions = [];

		const phone = orykPhoneUrl(row.private_ip);

		if (phone) {
			actions.push(
				`<a class="btn btn-default btn-sm" href="${phone}" target="_blank" rel="noopener noreferrer"` +
				` title="Open this phone's web interface at ${orykEscape(row.private_ip)}">` +
				`<i class="fa fa-external-link" style="margin: 0;"></i></a>`
			);
		}

		actions.push(orykSwitch(row, 'setClientEnabled', '#client_table', 'client', 'everything it asks for is refused'));
		actions.push(`<button type="button" class="btn btn-danger btn-sm" name="client_delete" value="${row.id}"><i class="fa fa-trash" style="margin: 0;"></i></button>`);
		actions.push(`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">Edit</a>`);

		return `<div class="flex gap-3" style="justify-content: flex-end;">${actions.join('')}</div>`;
	}

	// Editing a profile is a page too, and for the same reason. Its switch is
	// the client's one level up: switching a profile off stops every phone
	// assigned to it at once, which is why the title says so -- the row it is
	// on gives no other sign of how many that is until you read the Clients
	// column beside it.
	function formatProfileActions(value, row) {
		return [
			`<div class="flex gap-3" style="justify-content: flex-end;">`,
			orykSwitch(row, 'setProfileEnabled', '#profile_table', 'profile', 'every client assigned to it is refused'),
			`<button type="button" class="btn btn-danger btn-sm" name="profile_delete" value="${row.id}"><i class="fa fa-trash" style="margin: 0;"></i></button>`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&profile=${encodeURIComponent(row.id)}">Edit</a>`,
			`</div>`
		].join('');
	}

	// Deleting is the one action on either tab that needs no page of its own.
	//
	// Only one table is on the page -- the tab that was asked for is the only
	// pane rendered -- so only that one is refreshed.

	$(document).on('click', '[name="client_delete"]', function () {
		if (!window.confirm('Delete this client? Any logs it has sent, and its entries on the Logs tab, go with it.')) {
			return;
		}

		orykPost('deleteClient', { id: $(this).val() }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			$('#client_table').bootstrapTable('refresh');
			notie.alert(1, 'Deleted.', 2);
		});
	});

	// Both switches, answered once. Which row and which table are on the
	// button, so this handler has nothing to know about either.
	//
	// What is sent is the state the row is to be in, not an instruction to
	// flip it -- so pressing the button twice leaves it where the first press
	// put it, and a stale row cannot flip past whatever another tab has
	// already done. The answer says what the row now is, and that is what it
	// is redrawn from: refreshing the whole table to be told what we were
	// just told would lose the page and the scroll for nothing.
	$(document).on('click', '[name="oryk_enabled"]', function () {
		const button = $(this);
		const id = Number(button.val());
		const table = button.data('table');
		const enabled = Number(button.data('enabled')) ? 0 : 1;

		// Asked the way Delete asks, and before anything is disabled or
		// sent: the question is the button's, composed where the row was
		// drawn and knows which way it is going.
		if (!window.confirm(button.data('confirm'))) {
			return;
		}

		button.prop('disabled', true);

		orykPost(button.data('command'), { id: id, enabled: enabled }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				notie.alert(3, (response && response.message) || 'Could not change this.', 4);
				return;
			}

			$(table).bootstrapTable('updateByUniqueId', {
				id: id,
				row: { enabled: Number(response.enabled) }
			});

			notie.alert(1, Number(response.enabled) ? 'Enabled.' : 'Disabled.', 2);
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not change this.', 4);
		});
	});

	function formatUserExtension(value) {
		return value ? `<a class="oryk-name" href="?display=oryk_provisioner&user=${encodeURIComponent(value)}">${orykEscape(value)}</a>` : '-';
	}

	function formatUserClients(value, row) {
		return Number(value)
			? `<a href="?display=oryk_provisioner&user=${encodeURIComponent(row.extension)}&tab=clients">${orykEscape(value)}</a>`
			: '0';
	}

	function formatUserActions(value, row) {
		const extension = encodeURIComponent(row.extension);

		return [
			`<div class="flex gap-3" style="justify-content: flex-end;">`,
			`<a class="btn btn-default btn-sm" href="?display=extensions&extdisplay=${extension}" title="Open in Extensions"><i class="fa fa-external-link" style="margin: 0;"></i></a>`,
			`<button type="button" class="btn btn-danger btn-sm" name="user_delete" value="${orykEscape(row.extension)}" data-clients="${Number(row.clients) || 0}"><i class="fa fa-trash" style="margin: 0;"></i></button>`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&user=${extension}">Edit</a>`,
			`</div>`
		].join('');
	}

	// The Lobby and Expired filters ride in the list's query, so a refresh,
	// a sort or a page keeps them. The server whitelists the value.
	function orykUserQuery(params) {
		params.filter = $('#user_filter').val() || '';

		return params;
	}

	$(document).on('change', '#user_filter', function () {
		$('#user_delete_expired').toggleClass('hidden', $(this).val() !== 'expired');
		$('#user_table').bootstrapTable('refresh', { pageNumber: 1 });
	});

	// The context a user's calls are placed in, as it is named.
	function formatUserContext(value) {
		return value ? orykEscape(value) : '-';
	}

	// Raises FreePBX's Apply Config bar when an answer says something is pending,
	// on a page that is not reloaded to show it.
	function orykPending(response) {
		if (response && response.reload && typeof toggle_reload_button === 'function') {
			toggle_reload_button('show');
		}
	}

	// Asked with what the table shows: the server deletes only those it still
	// finds expired, and says how many it skipped.
	$(document).on('click', '#user_delete_expired', function () {
		const ids = $('#user_table').bootstrapTable('getData').map(function (row) {
			return row.extension;
		});

		if (!ids.length) {
			notie.alert(2, 'Nothing is listed.', 2);
			return;
		}

		if (!window.confirm(`Delete the ${ids.length} expired lobby user${ids.length === 1 ? '' : 's'} listed? Each extension, its account, voicemail, call history and clients are removed permanently. A user whose phone has been seen since is skipped.`)) {
			return;
		}

		const button = $(this).prop('disabled', true);

		orykPost('deleteExpiredUsers', { ids: ids }).done(function (response) {
			button.prop('disabled', false);

			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			orykPending(response);
			$('#user_table').bootstrapTable('refresh');
			notie.alert(1, `Deleted ${response.deleted}${response.skipped ? `, skipped ${response.skipped}` : ''}.`, 3);
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not delete.', 4);
		});
	});

	// Permanent, and it takes the call history and recordings with it, so it
	// says so -- and what happens to the clients pointing at it.
	$(document).on('click', '[name="user_delete"]', function () {
		const clients = Number($(this).data('clients')) || 0;
		let ask = 'Delete this user? The extension, its User Manager account, its voicemail and its call history and recordings are removed permanently. This cannot be undone.';

		if (clients) {
			ask += ` ${clients} client${clients === 1 ? ' points' : 's point'} at this user and will be deleted too, with the logs ${clients === 1 ? 'it' : 'they'} sent.`;
		}

		if (!window.confirm(ask)) {
			return;
		}

		const button = $(this).prop('disabled', true);

		orykPost('deleteUser', { id: button.val() }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			orykPending(response);
			$('#user_table').bootstrapTable('refresh');
			notie.alert(1, 'Deleted.', 2);
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not delete.', 4);
		});
	});

	function orykBanUrl(row) {
		return `?display=oryk_provisioner&ban=${encodeURIComponent(row.id)}`;
	}

	// A subject a ban leaves empty matches anything, and says so.
	const orykBanAny = '<span class="text-muted">any</span>';
	const orykBanRemote = <?php echo json_encode((string) ($remote ?? '')); ?>;

	// The address links to the ban; the client whose public address it is, when
	// one is, is its title.
	function formatBanIp(value, row) {
		if (!row.ip) {
			return orykBanAny;
		}

		const title = row.ip_client ? ` title="Public IP of ${orykEscape(row.ip_client)}"` : '';

		return `<a class="oryk-name" href="${orykBanUrl(row)}"${title}>${orykEscape(row.ip)}</a>`;
	}

	function formatBanMac(value, row) {
		if (!row.mac) {
			return orykBanAny;
		}

		return row.mac_client_id
			? `<a class="oryk-name" href="?display=oryk_provisioner&client=${encodeURIComponent(row.mac_client_id)}">${orykEscape(row.mac)}</a>`
			: `<span class="oryk-name">${orykEscape(row.mac)}</span>`;
	}

	function formatBanUser(value, row) {
		if (!row.extension) {
			return orykBanAny;
		}

		const name = row.user_name ? ` <span class="text-muted">${orykEscape(row.user_name)}</span>` : '';

		return row.user_device
			? `<a class="oryk-name" href="?display=oryk_provisioner&user=${encodeURIComponent(row.extension)}">${orykEscape(row.extension)}</a>${name}`
			: `<span class="oryk-name">${orykEscape(row.extension)}</span>`;
	}

	function formatBanClient(value, row) {
		return row.client_id
			? `<a href="?display=oryk_provisioner&client=${encodeURIComponent(row.client_id)}">${orykEscape(row.client_label)}</a>`
			: orykBanAny;
	}

	function formatBanProfile(value, row) {
		return row.profile_id
			? `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.profile_id)}">${orykEscape(row.profile_name || `#${row.profile_id}`)}</a>`
			: orykBanAny;
	}

	// What the State column offers. A Banned ban needs a length, so it is
	// offered at three; the ban's page takes any other.
	const orykBanStates = [
		{ state: 'banned', minutes: 60, label: 'Banned for 1 hour' },
		{ state: 'banned', minutes: 1440, label: 'Banned for 1 day' },
		{ state: 'banned', minutes: 10080, label: 'Banned for 1 week' },
		{ state: 'deny', label: 'Deny' },
		{ state: 'allow', label: 'Allow' }
	];

	// The state is a menu: the label says what the ban is, and opening it changes
	// it. An expired ban is kept until it is deleted, and says it is out of force.
	function formatBanState(value, row) {
		const labels = { banned: 'label-warning', deny: 'label-danger', allow: 'label-success' };
		const active = Number(row.active);
		const label = active
			? `<span class="label ${labels[value] || 'label-default'}">${orykEscape(value)} <i class="fa fa-caret-down"></i></span>`
			: `<span class="label label-default" title="Was ${orykEscape(value)}; no longer in force">expired <i class="fa fa-caret-down"></i></span>`;
		const items = orykBanStates
			.filter(function (choice) {
				// What it already is, in force, is not a change -- but a Banned
				// ban can always be given a new length.
				return !active || choice.state !== value || choice.state === 'banned';
			})
			.map(function (choice) {
				return `<li><a href="#" class="dropdown-item" data-ban="${orykEscape(row.id)}" data-state="${choice.state}" data-minutes="${choice.minutes || ''}">${orykEscape(choice.label)}</a></li>`;
			});

		return [
			'<div class="dropdown oryk-ban-state">',
			`<a href="#" data-toggle="dropdown" role="button" aria-haspopup="true" aria-expanded="false" title="Change the state">${label}</a>`,
			`<ul class="dropdown-menu">${items.join('')}</ul>`,
			'</div>'
		].join('');
	}

	function orykBanRowClasses(row) {
		return Number(row.active) ? {} : { classes: 'oryk-disabled' };
	}

	// Ages are the server's subtraction, on the database's clock -- see Bans.
	// Requests this ban decided, allowed or refused; when the last was is the title.
	// How many times the ban has been in force; when the current time began is the title.
	function formatBanTimes(value, row) {
		const times = Number(value) || 1;
		const title = row.started_at ? ` title="In force since ${orykEscape(row.started_at)}"` : '';

		return `<span${title}>${times}</span>`;
	}

	function formatBanHits(value, row) {
		const hits = Number(value) || 0;

		if (!hits) {
			return '<span class="text-muted">0</span>';
		}

		return `<span title="Last hit ${orykEscape(orykSince(row.last_hit_age))} (${orykEscape(row.last_hit_at)})">${hits}</span>`;
	}

	function formatBanCreated(value, row) {
		return value ? `<span title="${orykEscape(value)}">${orykEscape(orykSince(row.created_age))}</span>` : '-';
	}

	function formatBanExpires(value, row) {
		if (row.state !== 'banned') {
			return 'Never';
		}

		const when = Number(row.expires_in) <= 0 ? orykSince(-Number(row.expires_in)) : orykIn(row.expires_in);

		return `<span title="${orykEscape(value)}">${orykEscape(when)}</span>`;
	}

	// orykSince() the other way: how long until.
	function orykIn(seconds) {
		const left = Number(seconds) || 0;

		if (left < 60) {
			return 'any moment';
		}

		return orykSince(left).replace(/ ago$/, '').replace(/^/, 'in ');
	}

	// The note is read off the table's own row when the button is pressed, so it
	// is never written into an attribute. No note, no button to press.
	function formatBanActions(value, row) {
		const note = row.note
			? `<button type="button" class="btn btn-default btn-sm" name="ban_note" value="${orykEscape(row.id)}" title="Note">`
			: `<button type="button" class="btn btn-default btn-sm" disabled title="No note">`;

		return [
			`<div class="flex gap-3" style="justify-content: flex-end;">`,
			note,
			`<i class="fa fa-sticky-note-o" style="margin: 0;"></i></button>`,
			`<button type="button" class="btn btn-danger btn-sm" name="ban_delete" value="${orykEscape(row.id)}" title="Delete">`,
			`<i class="fa fa-trash" style="margin: 0;"></i></button>`,
			`<a class="btn btn-primary btn-sm" href="${orykBanUrl(row)}">Edit</a>`,
			`</div>`
		].join('');
	}

	// One ban's note, in a modal made the first time it is wanted and kept. No
	// close cross, which Bootstrap 3 and 4 place differently; Close, Escape and
	// the backdrop all dismiss it.
	$(document).on('click', '[name="ban_note"]', function () {
		const row = $('#ban_table').bootstrapTable('getRowByUniqueId', $(this).val());
		let modal = $('#oryk_ban_note');

		if (!row) {
			return;
		}

		if (!modal.length) {
			modal = $(
				'<div class="modal fade" id="oryk_ban_note" tabindex="-1" role="dialog">' +
					'<div class="modal-dialog" role="document">' +
						'<div class="modal-content">' +
							'<div class="modal-header"><h4 class="modal-title"></h4></div>' +
							'<div class="modal-body"><p class="oryk-ban-note" style="white-space: pre-wrap; word-break: break-word; margin: 0;"></p></div>' +
							'<div class="modal-footer">' +
								'<a class="btn btn-default oryk-ban-note-edit">Edit</a>' +
								'<button type="button" class="btn btn-primary" data-dismiss="modal">Close</button>' +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>'
			).appendTo('body');
		}

		modal.find('.modal-title').text(`Note on ban #${row.id}`);
		modal.find('.oryk-ban-note').text(row.note);
		modal.find('.oryk-ban-note-edit').attr('href', orykBanUrl(row));
		modal.modal('show');
	});

	// bootstrap-table scrolls its body, which would clip a menu opened near the
	// bottom, so an open state menu is placed against the window instead, and
	// closed when the page moves under it.
	$(document).on('shown.bs.dropdown', '.oryk-ban-state', function () {
		const box = this.getBoundingClientRect();
		const menu = $(this).find('.dropdown-menu');
		const height = menu.outerHeight();
		// Upwards when there is no room for it below.
		const top = box.bottom + 2 + height > window.innerHeight && box.top - 2 - height > 0
			? box.top - 2 - height
			: box.bottom + 2;

		menu.css({ position: 'fixed', top: top, left: box.left, right: 'auto', bottom: 'auto', transform: 'none' });
	});

	$(window).on('scroll resize', function () {
		$('.oryk-ban-state.open, .oryk-ban-state.show').find('[data-toggle="dropdown"]').dropdown('toggle');
	});

	// A state picked from the menu is saved at once, with everything else about
	// the ban as it is; refusing an address on its own that is yours, or a
	// client's, is asked first, as the ban's page does.
	$(document).on('click', '.oryk-ban-state [data-state]', function (event) {
		event.preventDefault();

		const choice = $(this);
		const row = $('#ban_table').bootstrapTable('getRowByUniqueId', choice.data('ban'));
		const state = String(choice.data('state'));

		if (!row) {
			return;
		}

		const alone = row.ip && !row.mac && !row.extension && !row.client_id && !row.profile_id;
		let ask = '';

		if (state !== 'allow' && alone && row.ip === orykBanRemote) {
			ask = `${row.ip} is the address you are connected from. Phones at your site will not be provisioned, and with Fail2ban Sync on, fail2ban blocks it on every port -- this page included. Change it anyway?`;
		} else if (state !== 'allow' && alone && row.ip_client) {
			ask = `${row.ip} is the public address of ${row.ip_client}. Every phone at that site will be refused. Change it anyway?`;
		}

		if (ask && !window.confirm(ask)) {
			return;
		}

		orykPost('setBanState', { id: row.id, state: state, minutes: choice.data('minutes') || '' }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not change the state.', 4);
				return;
			}

			$('#ban_table').bootstrapTable('refresh', { silent: true });
			notie.alert(1, response.escalated
				? `Ban #${row.id} has been in force as often as Deny After allows, so it is now deny.`
				: `Ban #${row.id} is now ${choice.text().toLowerCase()}.`, response.escalated ? 4 : 2);
		}).fail(function () {
			notie.alert(3, 'Could not change the state.', 4);
		});
	});

	$(document).on('click', '[name="ban_delete"]', function () {
		const button = $(this);

		if (!window.confirm('Delete this ban? What it matched is answered again from the next request.')) {
			return;
		}

		button.prop('disabled', true);

		orykPost('deleteBan', { id: button.val() }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			$('#ban_table').bootstrapTable('refresh');
			notie.alert(1, 'Deleted.', 2);
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not delete.', 4);
		});
	});

	$(document).on('click', '[name="profile_delete"]', function () {
		if (!window.confirm('Delete this profile? Its resources go with it.')) {
			return;
		}

		orykPost('deleteProfile', { id: $(this).val() }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			$('#profile_table').bootstrapTable('refresh');
			notie.alert(1, 'Deleted.', 2);
		});
	});

</script>
