// assets/scripts/client.js -- the client editor (views/client.php).
// The orykX constants it reads are written by that view, from PHP.

const orykClients = '?display=oryk_provisioner&tab=clients';

function formatResourceText(value) {
	return value ? orykEscape(value) : '-';
}

// The resource as it is written on the profile: a filename template, which
// is why it is worth showing beside what it comes to here.
function formatResourceName(value, row) {
	return value ? `<a href="?display=oryk_provisioner&profile=${orykClientProfileId}&resource=${encodeURIComponent(row.id)}">${orykEscape(value)}</a>` : '-';
}

// Render, Download and Open: see orykRenderButtons(). Edit is the resource's own
// page under its profile, the same link that profile's Resources tab draws
// -- a resource is edited in one place wherever it is reached from.
function formatResourceActions(value, row) {
	const actions = orykRenderButtons(row, orykClientId, row.id);

	actions.push(`<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&profile=${orykClientProfileId}&resource=${encodeURIComponent(row.id)}">Edit</a>`);

	return `<div class="flex gap-3" style="justify-content: flex-end;">${actions.join('')}</div>`;
}

orykEditor({
	save: 'saveClient',
	remove: 'deleteClient',
	confirm: 'Delete this client? Any logs it has sent, and its entries on the Logs tab, go with it.',
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
	// A generated token is in the clear only in this response, so it is
	// shown -- in a prompt, to be copied -- before the reload loses it.
	saved: function (response) {
		if (response.token) {
			window.prompt(orykClientTokenShown, response.token);
		}
	},
	// This client's own page: the same address on a save that changed it,
	// the new row's first address on a save that wrote it.
	page: function (id) {
		return '?display=oryk_provisioner&client=' + encodeURIComponent(id);
	},
	closed: orykClients
});
