// assets/scripts/resource.js -- the resource editor (views/resource.php).
// The orykX constants it reads are written by that view, from PHP.

function formatClientMac(value, row) {
	return value ? `<a href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>` : '-';
}

// A client that has been switched off is greyed here too. Open beside it
// now gets the refusal the phone gets; Render still shows what it would
// be sent.
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

// Render, Download and Open: see orykRenderButtons(). Edit is the client's own
// page, the same link the profile's Clients tab and the list both draw.
function formatClientActions(value, row) {
	const actions = orykRenderButtons(row, row.id, orykResourceId);

	actions.push(`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">Edit</a>`);

	return `<div class="flex gap-3" style="justify-content: flex-end;">${actions.join('')}</div>`;
}

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
	// This resource's own page: the same address on a save that changed it,
	// the new row's first address on a save that wrote it. A new resource
	// is its name and its type, and saving it is what brings the file box
	// and the template on to the page, so the row's own page is where the
	// rest of the work is.
	page: function (id) {
		return `?display=oryk_provisioner&profile=${orykProfileId}&resource=${encodeURIComponent(id)}`;
	},
	closed: orykResources
});
