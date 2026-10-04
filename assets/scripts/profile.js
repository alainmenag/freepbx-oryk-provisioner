// assets/scripts/profile.js -- the profile editor (views/profile.php).
// The orykX constants it reads are written by that view, from PHP.

const orykList = '?display=oryk_provisioner&tab=profiles';

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
		`<div class="flex gap-3" style="justify-content: flex-end;">`,
		`<button type="button" class="btn btn-danger btn-sm" name="resource_delete" value="${row.id}"><i class="fa fa-trash" style="margin: 0;"></i></button>`,
		`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&profile=${orykProfileId}&resource=${encodeURIComponent(row.id)}">Edit</a>`,
		`</div>`
	].join('');
}

function formatClientText(value) {
	return value ? orykEscape(value) : '-';
}

function formatClientMac(value, row) {
	return value ? `<a href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>` : '-';
}

// Switched off is said by the row rather than by a column of its own: it
// is switched on the list or on the client's own page, and this tab is
// neither -- what it owes the reader is that the phone it is looking at
// is not being served.
function formatClientRow(row) {
	return Number(row.enabled) ? {} : { classes: 'oryk-disabled' };
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

// Edit is the client's own page, the same link the list draws: a client
// is one row with one editor, wherever it is reached from, and that
// editor is also where a client is moved to another profile and so off
// this tab. There is no per-row Render here, because what a client is
// served is a file rather than a profile -- those links are one level
// down, on the resource editor's Clients tab and the client editor's
// Resources tab, where a resource and a client meet.
function formatClientActions(value, row) {
	return [
		`<div class="flex gap-3" style="justify-content: flex-end;">`,
		`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">Edit</a>`,
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
			name: $('#profile_name').val(),
			enabled: $('#profile_enabled').val()
		};
	},
	// This profile's own page: the same address on a save that changed it,
	// the new row's first address on a save that wrote it -- which is the
	// load that brings Resources and Clients on to the page, both being
	// tabs a profile has only once it has been written.
	page: function (id) {
		return '?display=oryk_provisioner&profile=' + encodeURIComponent(id);
	},
	closed: orykList
});
