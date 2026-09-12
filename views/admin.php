<?php
/**
 * The module page: a Clients tab, a Profiles tab and a Logs tab.
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
 * -- so both are drawn and handled by the same three functions below.
 *
 * Every tab is a link and only the tab asked for is rendered -- see
 * partials/tabs.php. Each tab names itself rather than one of them being the
 * bare URL: none is the others' default, though a bare
 * ?display=oryk_provisioner still opens Clients.
 *
 * @var string             $tab    Tab to open on: clients|profiles|logs
 * @var int                $saved  Row just written on that tab, highlighted here
 * @var array<string, int> $counts Rows behind each tab -- see partials/counts.php
 * @var array<int, array<string, mixed>>  $navigator Levels the navigator draws -- see partials/navigator.php
 */

$tab = in_array($tab ?? '', ['profiles', 'logs'], true) ? $tab : 'clients';
$saved = (int) ($saved ?? 0);

// Nothing above this page narrows them: every client, every profile, every
// request the endpoint has answered.
$countScope = [];

// One `saved` in the URL, and the tab it arrives on says which table it means:
// each editor comes back to its own tab, so there is never a saved client and
// a saved profile to tell apart.
$savedClient = $tab === 'clients' ? $saved : 0;
$savedProfile = $tab === 'profiles' ? $saved : 0;

// The strip, as links. Nothing on this page is ever behind a tab that cannot
// be opened: an empty table is still a table, and Add lives on it.
$tabs = [
	'clients' => [
		'label' => _('Clients'),
		'href' => '?display=oryk_provisioner&tab=clients',
		'count' => 'clients',
	],
	'profiles' => [
		'label' => _('Profiles'),
		'href' => '?display=oryk_provisioner&tab=profiles',
		'count' => 'profiles',
	],
	'logs' => [
		'label' => _('Logs'),
		'href' => '?display=oryk_provisioner&tab=logs',
		'count' => 'logs',
	],
];
?>
<?php include __DIR__ . '/partials/counts.php'; ?>
<style>
	.flex {
		display: flex;
	}
	.gap-3 {
		gap: 3px;
	}
	/*
	 * A disabled row stays on the list -- a client or a profile that has been
	 * switched off is still one, and the whole point of the switch is that
	 * nothing about the row is lost -- so it says what it is by being greyed
	 * rather than by being somewhere else. The buttons keep their own
	 * colours: they are what is done to the row, not what the row says.
	 */
	tr.oryk-disabled > td,
	tr.oryk-disabled > td a:not(.btn) {
		color: #999;
	}
</style>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<?php include __DIR__ . '/partials/navigator.php'; ?>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<?php include __DIR__ . '/partials/tabs.php'; ?>

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
							data-url="ajax.php?module=oryk_provisioner&command=listClients"
							data-toolbar="#client_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="id"
							data-row-style="formatClientRow"
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
									<th data-field="actions" data-formatter="formatClientActions"><?php echo _('Actions'); ?></th>
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
							data-url="ajax.php?module=oryk_provisioner&command=listProfiles"
							data-toolbar="#profile_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="id"
							data-row-style="formatProfileRow"
							data-sort-name="name"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="name" data-formatter="formatProfileName" data-sortable="true" ><?php echo _('Name'); ?></th>
									<th data-field="assigned" data-formatter="formatAssignedClients" data-sortable="true"><?php echo _('Clients'); ?></th>
									<th data-field="actions" data-formatter="formatProfileActions"><?php echo _('Actions'); ?></th>
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

	const orykAjax = 'ajax.php?module=oryk_provisioner&command=';

	// The row each editor has just written, so the one it landed on can say so
	// rather than the page looking unchanged after coming back.
	const orykSavedClient = <?php echo $savedClient; ?>;
	const orykSavedProfile = <?php echo $savedProfile; ?>;

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

	function orykEscape(value) {
		return $('<div>').text(value === null || value === undefined ? '' : value).html();
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
		return `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.id)}">${value}</a>`;
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
	// it: that it was just written, that it has been switched off, or both.
	// Shared for the same reason the switch is.
	function orykRowClasses(row, saved) {
		const classes = [];

		if (saved && Number(row.id) === saved) {
			classes.push('success');
		}

		if (!Number(row.enabled)) {
			classes.push('oryk-disabled');
		}

		return classes.length ? { classes: classes.join(' ') } : {};
	}

	// Editing a client is a page, not a dialog, so Edit is a link: the
	// row's id is the whole of what the editor needs, and it reads the
	// client back itself rather than being handed one. Beside it are the two
	// things that need no page -- the switch, and deletion.
	function formatClientActions(value, row) {
		return [
			`<div class="flex gap-3">`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">Edit</a>`,
			orykSwitch(row, 'setClientEnabled', '#client_table', 'client', 'everything it asks for is refused'),
			`<button type="button" class="btn btn-danger btn-sm" name="client_delete" value="${row.id}"><i class="fa fa-trash" style="margin: 0;"></i></button>`,
			`</div>`
		].join('');
	}

	function formatClientRow(row) {
		return orykRowClasses(row, orykSavedClient);
	}

	// Editing a profile is a page too, and for the same reason. Its switch is
	// the client's one level up: switching a profile off stops every phone
	// assigned to it at once, which is why the title says so -- the row it is
	// on gives no other sign of how many that is until you read the Clients
	// column beside it.
	function formatProfileActions(value, row) {
		return [
			`<div class="flex gap-3">`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&profile=${encodeURIComponent(row.id)}">Edit</a>`,
			orykSwitch(row, 'setProfileEnabled', '#profile_table', 'profile', 'every client assigned to it is refused'),
			`<button type="button" class="btn btn-danger btn-sm" name="profile_delete" value="${row.id}"><i class="fa fa-trash" style="margin: 0;"></i></button>`,
			`</div>`
		].join('');
	}

	function formatProfileRow(row) {
		return orykRowClasses(row, orykSavedProfile);
	}

	// Deleting is the one action on either tab that needs no page of its own.
	//
	// Only one table is on the page -- the tab that was asked for is the only
	// pane rendered -- so only that one is refreshed. What a delete changes on
	// the other tab is its badge, and the badges are re-read whole.

	$(document).on('click', '[name="client_delete"]', function () {
		if (!window.confirm('Delete this client? Any logs it has sent go with it.')) {
			return;
		}

		orykPost('deleteClient', { id: $(this).val() }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			$('#client_table').bootstrapTable('refresh');
			orykCounts();
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
			orykCounts();
			notie.alert(1, 'Deleted.', 2);
		});
	});

</script>
