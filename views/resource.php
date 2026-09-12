<?php
/**
 * views/resource.php -- one resource of one profile, over two tabs.
 *
 * Reached at ?display=oryk_provisioner&profile=<id>&resource=<id>, or with
 * `resource` present and empty to write a new one -- the same shape the
 * profile editor has, one level down.
 *
 * A new resource is its name and its type. The type is what the resource is
 * -- a template rendered for the client that asked, a file handed over as it
 * was stored, or a log the phone sends back -- and it is the one thing on this
 * page that decides anything: the control under it is whichever the type says,
 * and nothing on the page infers it from the other way round. Until 1.0.14 it
 * was inferred: a file hid the template and text in the template hid the file,
 * so a resource could not be a file before a file was on it and could not be a
 * log at all.
 *
 * The body of it -- the upload, or the template box -- is on the page once the
 * resource has been saved, because a file is stored under the resource's id and
 * an unsaved resource has not got one. So: name it, say what it is, save, then
 * write it.
 *
 * The placeholder list is under all of them and outlives any being hidden: a
 * filename is a template as much as the body is, and on a new resource the
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
 * &tab=clients. Each tab is a link and only the tab asked for is rendered --
 * see partials/tabs.php.
 *
 * @var array<string, mixed>                 $resource     id (0 when new), profile_id, name, type, template, file_size, file_uploaded_at
 * @var string                               $logPath      Where a log a phone PUTs is written
 * @var array<string, mixed>                 $profile      The profile it belongs to
 * @var array<string, array<string, string>> $placeholders What a template can refer to
 * @var array<string, int>                   $counts       Rows behind each tab -- see partials/counts.php
 * @var string                               $tab          Tab to open on: resource|clients
 * @var array<int, array<string, mixed>>  $navigator Levels the navigator draws -- see partials/navigator.php
 */

$resource = $resource ?? ['id' => 0, 'profile_id' => 0, 'name' => '', 'template' => ''];
$resource += ['type' => 'template', 'file_size' => null, 'file_uploaded_at' => null];
$logPath = $logPath ?? '/var/log/asterisk/provisioner';
$profile = $profile ?? ['id' => 0, 'name' => ''];
$placeholders = $placeholders ?? [];
$tab = ($tab ?? '') === 'clients' ? 'clients' : 'resource';

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$id = (int) $resource['id'];
$profileId = (int) $profile['id'];
$isNew = $id === 0;

// What this resource is, which is a column now and not a deduction. Anything
// the column does not recognise reads as a template, which is what the default
// is and what every resource written before the column had been.
$types = [
	'template' => _('Template'),
	'file' => _('File'),
	'log' => _('Log'),
];

$type = isset($types[(string) ($resource['type'] ?? '')]) ? (string) $resource['type'] : 'template';

// A resource is one thing, so the page offers one: the control the type names
// and not the others. Written here as well as in the script, so the page
// arrives in the state it would settle into rather than showing all of them
// for as long as it takes the script to run.
//
// The template is kept underneath whatever else the resource becomes rather
// than cleared by it, so putting the type back to Template finds the text
// where it was left.
$hasFile = $resource['file_size'] !== null;
$showFile = $type === 'file';
$showTemplate = $type === 'template';
$showLog = $type === 'log';

// A resource that has never been written has no name to render against a
// client, so its Clients tab is there but does not open -- hidden, it would
// look like something a resource does not have rather than something this one
// does not have yet.
$tab = $isNew ? 'resource' : $tab;

// The one tab with a table on it is the profile's client list, so the profile
// is what narrows this page's counts -- not the resource, which nothing is
// counted against.
$countScope = ['profile_id' => $profileId];

// The strip, as links. The first tab is the bare ?profile=<id>&resource=<id>,
// the way every other editor's first tab is the bare URL of the row it edits
// -- and on a resource that has not been written it is the key present and
// empty, since `resource=0` names a row that does not exist and is bounced
// back to the profile.
$resourceUrl = '?display=oryk_provisioner&profile=' . $profileId . '&resource=' . ($isNew ? '' : $id);

$tabs = [
	'resource' => [
		'label' => _('Resource'),
		'href' => $resourceUrl,
	],
	'clients' => [
		'label' => _('Clients'),
		'href' => $resourceUrl . '&tab=clients',
		'count' => 'clients',
		'disabled' => $isNew,
		'title' => $isNew ? _('Save the resource first -- the filename a client asks for is this one rendered.') : '',
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

					<?php if ($tab === 'resource'): ?>
					<div class="tab-pane oryk-tab-section active" id="oryk_resource">

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
											<?php echo _('Save it, and this page will then take what it holds -- a template to write, or a file to upload.'); ?>
										</span>
									<?php else: ?>
										<span class="help-block fpbx-help-block">
											<?php echo _('What that comes to for each client on this profile is on the Clients tab.'); ?>
										</span>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="resource_type">
											<?php echo _('Type'); ?>
											<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
										</label>
									</div>
									<div class="col-md-8">
										<select class="form-control" id="resource_type">
											<?php foreach ($types as $value => $label): ?>
												<option value="<?php echo $h($value); ?>"<?php echo $value === $type ? ' selected' : ''; ?>>
													<?php echo $h($label); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('What this resource is. <strong>Template</strong> is text rendered for the client that asks for it -- a configuration file, a directory, anything with placeholders in it. <strong>File</strong> is something uploaded here and handed over exactly as it was stored -- firmware, a ringtone, anything nothing should be rewriting. <strong>Log</strong> is the other direction: a file the phone sends back rather than one it fetches.'); ?>
									</span>
									<span class="help-block fpbx-help-block">
										<?php echo _('This is the only thing that says which. Changing it changes what a phone asking for this filename is answered with, and changing it away from File removes the uploaded file, since nothing would serve it afterwards.'); ?>
									</span>
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
										<?php echo _('What this resource serves, sent exactly as it was stored, under the filename above -- firmware, a ringtone, anything a phone fetches that nothing here should be rewriting. A resource set to File with nothing uploaded to it yet is answered as a missing file rather than as a template, so upload one or put the type back.'); ?>
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

						<div class="element-container<?php echo $showLog ? '' : ' hidden'; ?>" id="resource_log_container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label"><?php echo _('Log'); ?></label>
									</div>
									<div class="col-md-8">
										<p class="form-control-static">
											<code><?php echo $h($logPath); ?>/<span class="text-muted">[mac]</span>/</code>
										</p>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block">
										<?php echo _('A phone PUTs its boot and app logs back to the provisioning URL when it has finished starting up -- a Polycom sends <code>[mac]-boot.log</code>. Name a resource what it sends, set it to Log, and what arrives is written under <em>this</em> filename -- the resource\'s own name, rendered for the client that sent it -- in a directory named after that client\'s MAC. So a log belongs to a client, and everything one phone has ever sent is in one place.'); ?>
									</span>
									<span class="help-block fpbx-help-block">
										<?php echo _('There is nothing to write here -- the phone writes it. Fetching the same URL reads back what it last sent, so Render on the Clients tab shows one phone\'s log, exactly as it arrived and not rendered: a boot log with braces in it is a boot log, not a template. A client that has sent nothing yet has nothing to show.'); ?>
									</span>
									<span class="help-block fpbx-help-block">
										<?php echo _('A PUT to a filename no Log resource answers to is refused, which is what keeps this from being an open upload to the PBX. Every PUT is recorded on the Logs tab either way, taken or refused.'); ?>
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

					<?php endif; ?>

					<?php if ($tab === 'clients'): ?>
						<div class="tab-pane oryk-tab-section active" id="oryk_clients">

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
								data-row-style="formatClientRow"
								data-sort-name="mac"
								data-sort-order="asc">
								<thead>
									<tr>
										<th data-field="mac" data-formatter="formatClientMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
										<th data-field="device_id" data-formatter="formatDevice" data-sortable="true"><?php echo _('Device'); ?></th>
										<th data-field="extension" data-formatter="formatExtension" data-sortable="true"><?php echo _('Extension'); ?></th>
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

	// A client that has been switched off is greyed here too. The Render link
	// beside it still points where it always did, and following it now gets
	// the refusal the phone gets -- which is the honest answer to what this
	// client receives.
	function formatClientRow(row) {
		return Number(row.enabled) ? {} : { classes: 'oryk-disabled' };
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

	// Uploading and removing the file are requests of their own rather than
	// part of Save. The file is stored under the resource's id, so there has
	// to be a resource before there is anywhere to put one -- and a
	// forty-megabyte upload wants a progress bar rather than a Save button
	// that appears to have hung.
	let orykHasFile = <?php echo $hasFile ? 'true' : 'false'; ?>;

	// A resource is one thing, so one of the three is on the page, and the
	// select is what says which. Nothing here reads the template box or the
	// stored file to work it out -- that inference is what the type replaced.
	//
	// Read off the select on every path rather than toggled from each event,
	// so a reload lands on the same page the last change did.
	function orykShowKind() {
		const type = $('#resource_type').val();

		$('#resource_file_present').toggleClass('hidden', !orykHasFile);
		$('#resource_file_absent').toggleClass('hidden', orykHasFile);
		$('#resource_file_container').toggleClass('hidden', type !== 'file');
		$('#resource_template_container').toggleClass('hidden', type !== 'template');
		$('#resource_log_container').toggleClass('hidden', type !== 'log');
	}

	$('#resource_type').on('change', orykShowKind);

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
			orykNavText('resource', response.name);
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
		// The type goes with it, for the reason the name does: the upload
		// saves the resource, and a type changed to File on the way to
		// choosing a file is what made the control appear at all. Sending it
		// is also what lets saveResource() refuse an upload to something this
		// page no longer thinks is a file.
		form.append('type', $('#resource_type').val());
		form.append('file', input.files[0]);

		const progress = $('#resource_file_progress');

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

		if (!window.confirm('Remove the uploaded file? Nothing is served under this filename until another is uploaded, or the type is changed.')) {
			return;
		}

		orykPost('deleteResourceFile', {
			id: orykResourceId,
			profile_id: orykProfileId,
			name: $('#resource_name').val(),
			type: $('#resource_type').val()
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
				id: orykResourceId,
				profile_id: orykProfileId,
				name: $('#resource_name').val(),
				type: $('#resource_type').val(),
				// A new resource is its name and its type: the box is not on the
				// page yet, and val() of nothing is undefined, which jQuery would
				// post as the nine letters of it.
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
