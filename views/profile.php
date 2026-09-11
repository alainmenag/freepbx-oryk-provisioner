<?php
/**
 * views/profile.php -- one profile, over two tabs.
 *
 * Reached at ?display=oryk_provisioner&profile=<id> to edit an existing
 * profile, or ?display=oryk_provisioner&profile= (present, empty) to write a
 * new one. `profile` present but empty is deliberate rather than a degenerate
 * case: it is the same page doing the same thing, minus a row to replace.
 *
 * Profile is the name, and since 1.0.7 that is all it is. The profile used to
 * carry a template of its own -- the main config, served for [mac].cfg -- with
 * resources as the other files alongside it. There is no "alongside" any more:
 * every file a profile serves is a resource, the main config included, named
 * `.cfg` or `{{device.mac}}.cfg` like any other. One kind of thing to edit,
 * one path through the renderer, and no field on this tab that is really a
 * file in disguise.
 *
 * Resources are those files, each its own page at &resource=<id>, because a
 * block of configuration text wants room and a monospace column. Clients is
 * who this is all for: the clients assigned to the profile, the same
 * table the module page draws, narrowed to this one.
 *
 * Profile stays the first tab even at one field. It is the bare ?profile=<id>
 * URL the way every other editor's first tab is, it is where a profile is
 * named and renamed, and it is the only tab a new profile can open on.
 *
 * Tabs rather than pages because they are one thing being edited. Resources
 * and Clients are both inert until the profile has been saved -- a resource
 * hangs off a profile_id, and nothing can have been assigned to a profile
 * that has never been written.
 *
 * Each tab is a link and only the tab asked for is rendered -- see
 * partials/tabs.php. The bare ?profile=<id> is the Profile tab; the other two
 * name themselves with &tab=.
 *
 * Save, Delete and Close are the action bar's, drawn by FreePBX from
 * getActionBar() and bound by views/partials/editor.php.
 *
 * @var array<string, mixed> $profile id (0 when new), name
 * @var array<string, int>   $counts  Rows behind each tab -- see partials/counts.php
 * @var string               $tab     Tab to open on: profile|resources|clients
 * @var int                  $saved   Resource just written, highlighted here
 * @var array<int, array<string, mixed>>  $navigator Levels the navigator draws -- see partials/navigator.php
 */

$profile = $profile ?? ['id' => 0, 'name' => ''];
$tab = in_array($tab ?? '', ['resources', 'clients'], true) ? $tab : 'profile';
$saved = (int) ($saved ?? 0);

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$id = (int) $profile['id'];
$isNew = $id === 0;

// A new profile has nothing for a resource to belong to and nothing assigned
// to it, so those tabs are there but do not open: hidden, they would look
// like features this profile does not have rather than ones it does not have
// yet.
$tab = $isNew ? 'profile' : $tab;

// Both tabs on this page are this profile's: its files and its clients.
$countScope = ['profile_id' => $id];

// The strip, as links. The first tab is the bare ?profile=<id>, the way every
// other editor's first tab is the bare URL of the row it edits -- and on a
// profile that has not been written it is the key present and empty, since
// `profile=0` names a row that does not exist and is bounced to the list.
$profileUrl = '?display=oryk_provisioner&profile=' . ($isNew ? '' : $id);

$tabs = [
	'profile' => [
		'label' => _('Profile'),
		'href' => $profileUrl,
	],
	'resources' => [
		'label' => _('Resources'),
		'href' => $profileUrl . '&tab=resources',
		'count' => 'resources',
		'disabled' => $isNew,
		'title' => $isNew ? _('Save the profile first -- a resource belongs to one.') : '',
	],
	'clients' => [
		'label' => _('Clients'),
		'href' => $profileUrl . '&tab=clients',
		'count' => 'clients',
		'disabled' => $isNew,
		'title' => $isNew ? _('Save the profile first -- nothing can be assigned to one that has not been written.') : '',
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

					<?php if ($tab === 'profile'): ?>
					<div class="tab-pane oryk-tab-section active" id="oryk_profile">

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="profile_name">
											<?php echo _('Name'); ?>
											<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
										</label>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control" id="profile_name"
											autocomplete="off" placeholder="<?php echo _('Yealink T54W'); ?>"
											value="<?php echo $h($profile['name']); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('How the profile is named in the client list. Must be unique.'); ?>
									</span>
								</div>
							</div>
						</div>

					</div>

					<?php endif; ?>

					<?php if ($tab === 'resources'): ?>
						<div class="tab-pane oryk-tab-section active" id="oryk_resources">

							<p class="help-block fpbx-help-block">
								<?php echo _('Files a client asks this profile for -- .cfg, [mac]-phone.cfg, [mac]-web.cfg. ** Firmware is not yet supported.'); ?>
							</p>

							<div id="resource_toolbar" class="oryk-toolbar">
								<a class="btn btn-primary" href="?display=oryk_provisioner&amp;profile=<?php echo $id; ?>&amp;resource=">
									<i class="fa fa-plus"></i> <?php echo _('Add Resource'); ?>
								</a>
							</div>

							<table
								id="resource_table"
								data-toggle="table"
								data-url="ajax.php?module=oryk_provisioner&command=listResources&profile_id=<?php echo $id; ?>"
								data-toolbar="#resource_toolbar"
								class="table table-striped"
								data-side-pagination="server"
								data-pagination="true"
								data-search="true"
								data-show-refresh="true"
								data-unique-id="id"
								data-row-style="formatResourceRow"
								data-sort-name="name"
								data-sort-order="asc">
								<thead>
									<tr>
										<th data-field="name" data-formatter="formatResourceName" data-sortable="true"><?php echo _('Filename'); ?></th>
										<th data-field="file_size" data-formatter="formatResourceKind" data-sortable="true"><?php echo _('Type'); ?></th>
										<th data-field="updated_at" data-formatter="formatResourceText" data-sortable="true"><?php echo _('Updated'); ?></th>
										<th data-field="actions" data-formatter="formatResourceActions"><?php echo _('Actions'); ?></th>
									</tr>
								</thead>
							</table>

						</div>

					<?php endif; ?>

					<?php if ($tab === 'clients'): ?>
						<div class="tab-pane oryk-tab-section active" id="oryk_clients">

							<p class="help-block fpbx-help-block">
								<?php echo _('Clients assigned to this profile.'); ?>
							</p>

							<table
								id="client_table"
								data-toggle="table"
								data-url="ajax.php?module=oryk_provisioner&command=listClients&profile_id=<?php echo $id; ?>"
								class="table table-striped"
								data-side-pagination="server"
								data-pagination="true"
								data-search="true"
								data-show-refresh="true"
								data-unique-id="id"
								data-sort-name="mac"
								data-sort-order="asc">
								<thead>
									<tr>
										<th data-field="mac" data-formatter="formatClientMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
										<th data-field="device_id" data-formatter="formatDevice" data-sortable="true"><?php echo _('Device'); ?></th>
										<th data-field="device_extension" data-formatter="formatExtension" data-sortable="true"><?php echo _('Extension'); ?></th>
										<th data-field="description" data-formatter="formatClientText" data-sortable="true"><?php echo _('Description'); ?></th>
										<th data-field="actions" data-formatter="formatClientActions"><?php echo _('Actions'); ?></th>
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

	const orykProfileId = <?php echo $id; ?>;
	const orykList = '?display=oryk_provisioner&tab=profiles';

	// The resource just written by its editor, so the row it landed on says so
	// rather than the tab looking unchanged after coming back to it.
	const orykSavedResource = <?php echo $saved; ?>;

	function formatResourceText(value) {
		return value ? orykEscape(value) : '-';
	}

	function formatResourceName(value, row) {
		return value ? `<a href="?display=oryk_provisioner&profile=${orykProfileId}&resource=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>` : '-';
	}

	// Editing a resource is a page, not a dialog, for the reason editing a
	// profile is: the row's id is the whole of what the editor needs, and it
	// reads the resource back itself rather than being handed one.
	function formatResourceActions(value, row) {
		return [
			`<div class="flex gap-3">`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&profile=${orykProfileId}&resource=${encodeURIComponent(row.id)}">Edit</a>`,
			`<button type="button" class="btn btn-danger btn-sm" name="resource_delete" value="${row.id}"><i class="fa fa-trash" style="margin: 0;"></i></button>`,
			`</div>`
		].join('');
	}

	function formatResourceRow(row) {
		return orykSavedResource && Number(row.id) === orykSavedResource ? { classes: 'success' } : {};
	}

	function formatClientText(value) {
		return value ? orykEscape(value) : '-';
	}

	function formatClientMac(value, row) {
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

	// Edit is the client's own page, the same link the list draws: an
	// client is one row with one editor, wherever it is reached from,
	// and that editor is also where a client is moved to another profile and
	// so off this tab. Config is the URL a phone is given; every row here has
	// a profile by definition, so every row has one.
	function formatClientActions(value, row) {
		return [
			`<div class="flex gap-3">`,
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">Edit</a>`,
			`<a class="btn btn-default btn-sm" href="/provisioner/${encodeURIComponent(row.mac)}.cfg" target="_blank" title="View the rendered configuration">Render</a>`,
			`</div>`
		].join('');
	}

	// Deleting a resource is the one action that needs no page of its own.
	$(document).on('click', '[name="resource_delete"]', function () {
		if (!window.confirm('Delete this resource?')) {
			return;
		}

		orykPost('deleteResource', { id: $(this).val() }).done(function (response) {
			if (!response || !response.status) {
				notie.alert(3, (response && response.message) || 'Could not delete.', 4);
				return;
			}

			$('#resource_table').bootstrapTable('refresh');
			orykCounts();
			notie.alert(1, 'Deleted.', 2);
		});
	});

	orykEditor({
		save: 'saveProfile',
		remove: 'deleteProfile',
		confirm: 'Delete this profile? Its resources go with it.',
		values: function () {
			return {
				id: orykProfileId,
				name: $('#profile_name').val()
			};
		},
		// The list is re-rendered on arrival, so the saved profile is in
		// its table without anything here having to put it there.
		saved: function (response) {
			return orykList + '&saved=' + encodeURIComponent(response.id);
		},
		closed: orykList
	});

</script>
