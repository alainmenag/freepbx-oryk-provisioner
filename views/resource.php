<?php
/**
 * views/resource.php -- one resource of one profile, over two tabs.
 *
 * Reached at ?display=oryk_provisioner&profile=<id>&resource=<id>, or with
 * `resource` present and empty to write a new one -- the same shape the
 * profile editor has, one level down.
 *
 * A new resource is its name and nothing else. What it serves -- an uploaded
 * file, or a template -- is on the page once it has been saved, because a file
 * is stored under the resource's id and an unsaved resource has not got one.
 * Rather than one control explaining its own absence while the other works,
 * both arrive together: name it, save it, then say what it serves. Only one of
 * the two is ever on the page after that -- a file hides the template and a
 * written template hides the file, because a resource serves one thing.
 *
 * The placeholder list is under both of them and outlives either being hidden:
 * a filename is a template as much as the body is, and on a new resource the
 * filename is the only field there is.
 *
 * A resource is a file the profile serves: [mac].cfg, [mac]-phone.cfg,
 * [mac]-directory.xml. Since 1.0.7 that is all of them -- the profile used to
 * carry the main config as a template of its own and resources were the files
 * beside it, and now the main config is a resource named `.cfg` or
 * `{{device.mac}}.cfg` like the rest. So this page is where every byte a phone
 * receives is written, and the profile editor has no template box left.
 *
 * Clients is who this file is served to: the profile's own client table,
 * asked for again with this resource's id, so each row can say which filename
 * that client asks this resource for. A resource's name is a template, so
 * that filename is a different string per client -- which is why a resource
 * has no one preview URL of its own and why the preview belongs here, on the
 * client row, one link per phone.
 *
 * The bare ?profile=<id>&resource=<id> *is* the Resource tab, the way
 * ?profile=<id> is the profile editor's first tab; Clients names itself with
 * &tab=clients, and the shown.bs.tab handler keeps the address in step.
 *
 * @var array<string, mixed>                 $resource     id (0 when new), profile_id, name, template, file_size, file_uploaded_at
 * @var array<string, mixed>                 $profile      The profile it belongs to
 * @var array<string, array<string, string>> $placeholders What a template can refer to
 * @var int                                  $assigned     Clients on the profile, for the tab's count
 * @var string                               $tab          Tab to open on: resource|clients
 */

$resource = $resource ?? ['id' => 0, 'profile_id' => 0, 'name' => '', 'template' => ''];
$resource += ['file_size' => null, 'file_uploaded_at' => null];
$profile = $profile ?? ['id' => 0, 'name' => ''];
$placeholders = $placeholders ?? [];
$assigned = (int) ($assigned ?? 0);
$tab = ($tab ?? '') === 'clients' ? 'clients' : 'resource';

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$id = (int) $resource['id'];
$profileId = (int) $profile['id'];
$isNew = $id === 0;

// There is no column saying which kind of resource this is. An upload is the
// only thing that sets a size, so a size is what says one happened -- and
// removing the file clears it again and leaves the template underneath, which
// was never touched.
$hasFile = $resource['file_size'] !== null;

// And a resource serves one thing, so the page offers one. A file puts the
// template away and a template puts the file away -- written here as well as
// in the script, so the page arrives in the state it would settle into rather
// than showing both for as long as it takes the script to run.
//
// The template is kept underneath a file rather than cleared by it, so a
// resource can have both stored while only one of them is what it serves. That
// is why the file wins: it is the thing being served.
$hasTemplate = trim((string) $resource['template']) !== '';
$showFile = $hasFile || !$hasTemplate;
$showTemplate = !$hasFile;

// A resource that has never been written has no name to render against a
// client, so its Clients tab is there but does not open -- hidden, it would
// look like something a resource does not have rather than something this one
// does not have yet.
$tab = $isNew ? 'resource' : $tab;
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<div class="section-title">
				<h2>
					<span class="title">
						<a class="title" href="?display=oryk_provisioner&tab=profiles">Provisioner</a>
						<span>:: Profile</span>
						<a class="title" href="?display=oryk_provisioner&profile=<?php echo $profileId; ?>&tab=resources">
							<span><?php echo $h($profile['name']); ?></span>
						</a>
						<span>:: Resource</span>
						<?php if (isset($resource['name'])): ?>
							<code id="resource_crumb"><?php echo $h($resource['name']); ?></code>
						<?php endif; ?>
					</span>
				</h2>
			</div>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<ul class="nav nav-tabs" role="tablist">
					<li role="presentation" class="<?php echo $tab === 'resource' ? 'active' : ''; ?>">
						<a href="#oryk_resource" aria-controls="oryk_resource" role="tab" data-toggle="tab">
							<?php echo _('Resource'); ?>
						</a>
					</li>
					<li role="presentation" class="<?php echo $tab === 'clients' ? 'active' : ($isNew ? 'disabled' : ''); ?>">
						<?php if ($isNew): ?>
							<a href="#" title="<?php echo _('Save the resource first -- the filename a client asks for is this one rendered.'); ?>"
								onclick="return false;">
								<?php echo _('Clients'); ?>
							</a>
						<?php else: ?>
							<a href="#oryk_clients" aria-controls="oryk_clients" role="tab" data-toggle="tab">
								<?php echo _('Clients'); ?>
								<span class="badge"><?php echo $assigned; ?></span>
							</a>
						<?php endif; ?>
					</li>
				</ul>

				<div class="tab-content">

					<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'resource' ? 'active' : ''; ?>" id="oryk_resource">

						<!-- Not a form: see the note in partials/editor.php. -->
						<input type="hidden" id="resource_row_id" value="<?php echo $id; ?>">
						<input type="hidden" id="resource_profile_id" value="<?php echo $profileId; ?>">

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="resource_name">
											<?php echo _('Filename'); ?>
											<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
										</label>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control oryk-name" id="resource_name"
											autocomplete="off" placeholder="{{device.mac}}-phone.cfg"
											value="<?php echo $h($resource['name']); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('The filename a phone asks for. It is a template like the body below, so <code>{{device.mac}}-phone.cfg</code> covers every client on this profile, and a vendor that names its files some other way can be matched exactly. A name with no placeholders in it -- <code>phone.cfg</code> -- is matched against the request with the client\'s MAC taken off the front, so either way of writing it works. Unique within this profile.'); ?>
									</span>
									<span class="help-block fpbx-help-block">
										<?php echo _('The main configuration file is a resource like any other. Name it <code>.cfg</code>: written that way it is matched against the request with the MAC taken off the front, so it answers whichever separator style the phone asks in. <code>{{device.mac}}.cfg</code> works too, but it renders without separators and so only answers a phone that asks that way. A profile with neither serves nothing for <code>[mac].cfg</code>.'); ?>
									</span>
									<?php if ($isNew): ?>
										<span class="help-block fpbx-help-block">
											<?php echo _('Save it, and this page will then take what it serves: an uploaded file, or a template.'); ?>
										</span>
									<?php else: ?>
										<span class="help-block fpbx-help-block">
											<?php echo _('What that comes to for each client on this profile is on the Clients tab.'); ?>
										</span>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<?php if (!$isNew): ?>

						<div class="element-container<?php echo $showFile ? '' : ' hidden'; ?>" id="resource_file_container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="resource_file"><?php echo _('File'); ?></label>
									</div>
									<div class="col-md-8">
										<div id="resource_file_present" class="<?php echo $hasFile ? '' : 'hidden'; ?>">
											<p class="form-control-static">
												<span id="resource_file_meta"
													data-size="<?php echo $hasFile ? (int) $resource['file_size'] : ''; ?>"
													data-uploaded="<?php echo $h((string) $resource['file_uploaded_at']); ?>"></span>
												<button type="button" class="btn btn-default btn-sm" id="resource_file_remove">
													<?php echo _('Remove'); ?>
												</button>
											</p>
										</div>
										<div id="resource_file_absent" class="<?php echo $hasFile ? 'hidden' : ''; ?>">
											<input type="file" id="resource_file">
											<div class="progress hidden" id="resource_file_progress" style="margin-top: 8px;">
												<div class="progress-bar" role="progressbar" style="width: 0%;"></div>
											</div>
										</div>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('A resource can serve an uploaded file instead of a template -- firmware, a ringtone, anything a phone fetches that nothing here should be rewriting. It is sent exactly as it was stored, under the filename above. There is nothing else to set: a resource with a file serves the file, and one without serves its template.'); ?>
									</span>
									<span class="help-block fpbx-help-block">
										<?php echo _('A phone fetching firmware sends no MAC address at all, so an uploaded file is also found by its name alone, across every profile. Name it exactly what the vendor asks for -- <code>3111-44500-001.sip.ld</code> -- and it will be served to a request that says nothing about who is asking, which also means to anyone who can reach the provisioning URL and knows that name.'); ?>
									</span>
									<span class="help-block fpbx-help-block">
										<?php echo sprintf(
											_('This server takes a file of up to %1$s, in a request body of up to %2$s -- <code>upload_max_filesize</code> and <code>post_max_size</code> in its php.ini, which have to be raised together. A firmware image is larger than either default.'),
											$h(ini_get('upload_max_filesize')),
											$h(ini_get('post_max_size'))
										); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container<?php echo $showTemplate ? '' : ' hidden'; ?>" id="resource_template_container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="resource_template"><?php echo _('Template'); ?></label>
									</div>
									<div class="col-md-8">
										<textarea class="form-control oryk-template" id="resource_template"
											rows="24" spellcheck="false" wrap="off">
<?php echo $h($resource['template']); ?></textarea>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('What is served under that filename, stored as typed. Names in double braces are replaced when a client asks for the file; a name nothing answers to is replaced with nothing. The content type is taken from the extension: .xml is served as XML, .json as JSON, anything else as plain text.'); ?>
									</span>
								</div>
							</div>
						</div>

						<?php endif; ?>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label"><?php echo _('Placeholders'); ?></label>
									</div>
									<div class="col-md-8">
										<span class="help-block fpbx-help-block">
											<?php echo _('Filled in when a client asks for the file -- in the filename above as much as in a template, which is how one resource covers every client on the profile. Click one to copy it.'); ?>
										</span>
										<?php include __DIR__ . '/partials/placeholders.php'; ?>
									</div>
								</div>
							</div>
						</div>

					</div>

					<?php if (!$isNew): ?>
						<div role="tabpanel" class="tab-pane oryk-tab-section <?php echo $tab === 'clients' ? 'active' : ''; ?>" id="oryk_clients">

							<p class="help-block fpbx-help-block">
								<?php echo _('Clients assigned to the profile that owns this resource.'); ?>
							</p>

							<table
								id="client_table"
								data-toggle="table"
								data-url="ajax.php?module=oryk_provisioner&command=listClients&profile_id=<?php echo $profileId; ?>&resource_id=<?php echo $id; ?>"
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

	const orykProfileId = <?php echo $profileId; ?>;
	const orykResourceId = <?php echo $id; ?>;
	const orykResources = '?display=oryk_provisioner&profile=<?php echo $profileId; ?>&tab=resources';

	function formatClientMac(value, row) {
		return value ? `<a href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>` : '-';
	}

	// The Device column names the FreePBX device the client points at,
	// and links to it; the extension is its own column beside it.
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

	// Edit is the client's own page, the same link the profile's Clients
	// tab and the list both draw. Render is this resource as that client
	// receives it -- absent when the rendered name carries somebody else's
	// MAC (000000000000-directory.xml and the like), since the endpoint reads
	// the client out of the path and such a URL would answer for the wrong
	// phone.
	function formatClientActions(value, row) {
		const actions = [
			`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">Edit</a>`
		];

		if (row.url) {
			actions.push(`<a class="btn btn-default btn-sm" href="${orykEscape(row.url)}" target="_blank" title="View this resource as this client receives it">Render</a>`);
		}

		return `<div class="flex gap-3">${actions.join('')}</div>`;
	}

	// A table drawn while its tab is hidden has no width to lay itself out
	// against, so it is told to measure again once the tab is on screen. The
	// URL is kept in step at the same time, so a reload or a bookmark comes
	// back to the tab that is open.
	$(document).on('shown.bs.tab', 'a[data-toggle="tab"]', function () {
		const pane = $(this).attr('href');

		$(pane).find('table[data-toggle="table"]').bootstrapTable('resetView');

		if (window.history && window.history.replaceState) {
			const tab = pane === '#oryk_clients' ? '&tab=clients' : '';
			window.history.replaceState(null, '', `?display=oryk_provisioner&profile=${orykProfileId}&resource=${orykResourceId}${tab}`);
		}
	});

	// Uploading and removing the file are requests of their own rather than
	// part of Save. The file is stored under the resource's id, so there has
	// to be a resource before there is anywhere to put one -- and a
	// forty-megabyte upload wants a progress bar rather than a Save button
	// that appears to have hung.
	let orykHasFile = <?php echo $hasFile ? 'true' : 'false'; ?>;

	// A resource serves one thing, so only one of the two is on the page: a
	// file hides the template, and text in the template hides the file. Which
	// also says how to change your mind -- empty the box, or remove the file.
	//
	// Read off the two states rather than toggled from each event, so every
	// path through here agrees and a reload lands on the same page the last
	// keystroke did.
	function orykShowKind() {
		const written = ($('#resource_template').val() ?? '').trim() !== '';

		$('#resource_file_present').toggleClass('hidden', !orykHasFile);
		$('#resource_file_absent').toggleClass('hidden', orykHasFile);
		$('#resource_file_container').toggleClass('hidden', !orykHasFile && written);
		$('#resource_template_container').toggleClass('hidden', orykHasFile);
	}

	$('#resource_template').on('input', orykShowKind);

	// The label beside Remove, written in one place: the page renders the size
	// and the date as data and this says them, so a file that has just been
	// uploaded and one that was there when the page loaded read the same.
	function orykFileMeta(size, uploaded) {
		$('#resource_file_meta').text(size ? `${orykBytes(size)}, uploaded ${uploaded}` : '');
	}

	orykFileMeta($('#resource_file_meta').data('size'), $('#resource_file_meta').data('uploaded'));

	// Both file actions save the resource, so the heading has to follow a
	// rename that has already been written without a page load behind it.
	function orykSaved(response) {
		if (response && response.name) {
			$('#resource_crumb').text(response.name);
		}
	}

	$('#resource_file').on('change', function () {
		const input = this;

		if (!input.files || !input.files.length) {
			return;
		}

		// The name goes with it: uploading saves the resource, so a filename
		// typed on the way to choosing a file is written with the file rather
		// than left behind unsaved. The template is deliberately not sent --
		// what is not posted is not written, and the text under a file stays
		// as it was.
		const form = new FormData();
		form.append('id', orykResourceId);
		form.append('profile_id', orykProfileId);
		form.append('name', $('#resource_name').val());
		form.append('file', input.files[0]);

		const progress = $('#resource_file_progress');

		// Straight away, not when the upload lands: the answer to what this
		// resource serves was given by choosing the file, and a template box
		// sitting there through a forty-megabyte upload invites typing into
		// something that is on its way out.
		$('#resource_template_container').addClass('hidden');

		progress.removeClass('hidden').find('.progress-bar').css('width', '0%');
		$(input).prop('disabled', true);

		$.ajax({
			url: 'ajax.php?module=oryk_provisioner&command=uploadResourceFile',
			type: 'POST',
			data: form,
			// FormData writes its own multipart boundary, so jQuery must be told
			// not to serialise the body or set a content type over the top of it.
			processData: false,
			contentType: false,
			dataType: 'json',
			xhr: function () {
				const request = $.ajaxSettings.xhr();

				if (request.upload) {
					request.upload.addEventListener('progress', function (event) {
						if (event.lengthComputable) {
							progress.find('.progress-bar').css('width', Math.round((event.loaded / event.total) * 100) + '%');
						}
					});
				}

				return request;
			}
		}).done(function (response) {
			if (!response || !response.status) {
				orykShowError(response && response.message);
				return;
			}

			orykHasFile = true;
			orykFileMeta(response.file_size, response.file_uploaded_at);
			orykSaved(response);
		}).fail(function () {
			orykShowError('The upload did not reach the server.');
		}).always(function () {
			// Whatever happened, the page goes back to describing what is
			// actually there -- an upload that failed leaves no file, so the
			// template comes back.
			orykShowKind();
			progress.addClass('hidden');
			$(input).prop('disabled', false).val('');
		});
	});

	$('#resource_file_remove').on('click', function (event) {
		event.preventDefault();

		if (!window.confirm('Remove the uploaded file? This resource goes back to serving its template.')) {
			return;
		}

		orykPost('deleteResourceFile', {
			id: orykResourceId,
			profile_id: orykProfileId,
			name: $('#resource_name').val()
		}).done(function (response) {
			if (!response || !response.status) {
				orykShowError(response && response.message);
				return;
			}

			orykHasFile = false;
			orykFileMeta(0, '');
			orykShowKind();
			orykSaved(response);
		}).fail(function () {
			orykShowError('The server could not be reached.');
		});
	});

	orykEditor({
		save: 'saveResource',
		remove: 'deleteResource',
		confirm: 'Delete this resource?',
		values: function () {
			return {
				id: $('#resource_row_id').val(),
				profile_id: $('#resource_profile_id').val(),
				name: $('#resource_name').val(),
				// A new resource is only its name: the box is not on the page yet,
				// and val() of nothing is undefined, which jQuery would post as the
				// six letters of it.
				template: $('#resource_template').val() ?? ''
			};
		},
		// Back to the profile's Resources tab, which is re-rendered on
		// arrival, with the row that was just written picked out.
		//
		// Except for the first save of a new resource, which lands on the
		// resource's own page instead. A new one is its name and nothing else,
		// and saving it is what brings the file and the template on to the page
		// -- going back to the list here would mean saving, arriving somewhere
		// else, and clicking Edit to reach the half of the page the save was for.
		saved: function (response) {
			if (!orykResourceId) {
				return `?display=oryk_provisioner&profile=${orykProfileId}&resource=${encodeURIComponent(response.id)}`;
			}

			return orykResources + '&saved=' + encodeURIComponent(response.id);
		},
		closed: orykResources
	});

</script>
