<?php
/**
 * views/profile.php -- one profile, over two tabs.
 *
 * Reached at ?display=oryk_provisioner&profile=<id> to edit an existing
 * profile, or ?display=oryk_provisioner&profile= (present, empty) to write a
 * new one. `profile` present but empty is deliberate rather than a degenerate
 * case: it is the same page doing the same thing, minus a row to replace.
 *
 * Profile is the main config -- the name and the one template every profile
 * has. Resources are the other files a phone asks that profile for, each its
 * own page at &resource=<id>, for the reason a profile is its own page: a
 * block of configuration text wants room and a monospace column.
 *
 * Two tabs rather than two pages because they are one thing being edited. The
 * Resources tab is inert until the profile has been saved -- a resource hangs
 * off a profile_id, and a profile that has never been written has none.
 *
 * Save, Delete and Close are the action bar's, drawn by FreePBX from
 * getActionBar() and bound by views/partials/editor.php.
 *
 * @var array<string, mixed>                 $profile      id (0 when new), name, template
 * @var array<int, array<string, mixed>>     $assigned     Device associations using it
 * @var array<string, array<string, string>> $placeholders What a template can refer to
 * @var string                               $tab          Tab to open on: profile|resources
 * @var int                                  $saved        Resource just written, highlighted here
 */

$profile = $profile ?? ['id' => 0, 'name' => '', 'template' => ''];
$assigned = $assigned ?? [];
$placeholders = $placeholders ?? [];
$tab = ($tab ?? '') === 'resources' ? 'resources' : 'profile';
$saved = (int) ($saved ?? 0);

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$id = (int) $profile['id'];
$isNew = $id === 0;

// A new profile has nothing for a resource to belong to, so the tab is there
// but does not open: hidden, it would look like a feature this profile does
// not have rather than one it does not have yet.
$tab = $isNew ? 'profile' : $tab;
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<div class="section-title">
				<h2>
					<span class="title">
						<?php if ($isNew): ?>
							<?php echo _('New Profile'); ?>
						<?php else: ?>
							<?php echo _('Edit Profile'); ?>
							<code><?php echo $h($profile['name']); ?></code>
						<?php endif; ?>
					</span>
				</h2>
			</div>

			<div class="section">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<ul class="nav nav-tabs" role="tablist">
					<li role="presentation" class="<?php echo $tab === 'profile' ? 'active' : ''; ?>">
						<a href="#oryk_profile" aria-controls="oryk_profile" role="tab" data-toggle="tab">
							<?php echo _('Profile'); ?>
						</a>
					</li>
					<li role="presentation" class="<?php echo $tab === 'resources' ? 'active' : ($isNew ? 'disabled' : ''); ?>">
						<?php if ($isNew): ?>
							<a href="#" title="<?php echo _('Save the profile first -- a resource belongs to one.'); ?>"
								onclick="return false;">
								<?php echo _('Resources'); ?>
							</a>
						<?php else: ?>
							<a href="#oryk_resources" aria-controls="oryk_resources" role="tab" data-toggle="tab">
								<?php echo _('Resources'); ?>
							</a>
						<?php endif; ?>
					</li>
				</ul>

				<div class="tab-content">

					<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'profile' ? 'active' : ''; ?>" id="oryk_profile">

						<?php if (!empty($assigned)): ?>
							<div class="alert alert-info oryk-assigned">
								<strong>
									<?php
									echo sprintf(
										_('Assigned to %s device(s).'),
										count($assigned)
									);
									?>
								</strong>
								<?php echo _('Whatever is saved here is what they provision with.'); ?>
								<div class="oryk-assigned-macs">
									<?php foreach ($assigned as $device): ?>
										<span class="oryk-assigned-device">
											<code><?php echo $h($device['mac']); ?></code>
											<?php if (($device['extension'] ?? '') !== ''): ?>
												<a href="?display=extensions&amp;extdisplay=<?php echo rawurlencode((string) $device['extension']); ?>">(<?php echo $h($device['extension']); ?>)</a>
											<?php endif; ?>
										</span>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endif; ?>

						<!-- Not a form: see the note in partials/editor.php. -->
						<input type="hidden" id="profile_row_id" value="<?php echo $id; ?>">

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
										<?php echo _('How the profile is named in the device list. Must be unique.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="profile_template"><?php echo _('Template'); ?></label>
									</div>
									<div class="col-md-8">
										<textarea class="form-control oryk-template" id="profile_template"
											rows="24" spellcheck="false" wrap="off">
<?php echo $h($profile['template']); ?></textarea>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('The main configuration file, served for <mac>.cfg. Stored as typed. Names in double braces are replaced when a device asks for its configuration; a name nothing answers to is replaced with nothing.'); ?>
									</span>
									<?php include __DIR__ . '/partials/placeholders.php'; ?>
								</div>
							</div>
						</div>

					</div>

					<?php if (!$isNew): ?>
						<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'resources' ? 'active' : ''; ?>" id="oryk_resources">

							<p class="help-block fpbx-help-block">
								<?php echo _('The other files a phone asks this profile for -- <mac>-phone.cfg, <mac>-web.cfg, a directory, anything the vendor fetches alongside the main config. Each is rendered the same way the template above is.'); ?>
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
								data-unique-id="id"
								data-row-style="formatResourceRow"
								data-sort-name="name"
								data-sort-order="asc">
								<thead>
									<tr>
										<th data-field="name" data-formatter="formatResourceName" data-sortable="true"><?php echo _('Filename'); ?></th>
										<th data-field="updated_at" data-formatter="formatResourceText" data-sortable="true"><?php echo _('Updated'); ?></th>
										<th data-field="actions" data-formatter="formatResourceActions"><?php echo _('Actions'); ?></th>
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

	function formatResourceName(value) {
		return value ? `<code>${orykEscape(value)}</code>` : '-';
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

	// A table drawn while its tab is hidden has no width to lay itself out
	// against, so it is told to measure again once the tab is on screen. The
	// URL is kept in step at the same time, so a reload -- and the Add
	// Resource link on the tab -- come back to the tab that is open.
	$(document).on('shown.bs.tab', 'a[data-toggle="tab"]', function () {
		const pane = $(this).attr('href');

		$(pane).find('table[data-toggle="table"]').bootstrapTable('resetView');

		if (window.history && window.history.replaceState) {
			const tab = pane === '#oryk_resources' ? '&tab=resources' : '';
			window.history.replaceState(null, '', `?display=oryk_provisioner&profile=${orykProfileId}${tab}`);
		}
	});

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
			notie.alert(1, 'Deleted.', 2);
		});
	});

	orykEditor({
		save: 'saveProfile',
		remove: 'deleteProfile',
		confirm: 'Delete this profile? Its resources go with it.',
		values: function () {
			return {
				id: $('#profile_row_id').val(),
				name: $('#profile_name').val(),
				template: $('#profile_template').val()
			};
		},
		// The list is re-rendered on arrival, so the saved profile is in its
		// table and in the device dialog's dropdown without anything here
		// having to put it there.
		saved: function (response) {
			return orykList + '&saved=' + encodeURIComponent(response.id);
		},
		closed: orykList
	});

</script>
