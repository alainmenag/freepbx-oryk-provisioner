// assets/scripts/user.js -- the user editor (views/user.php).
// The orykX constants it reads are written by that view, from PHP.

function orykUserClientRow(row) {
	return Number(row.enabled) ? {} : { classes: 'oryk-disabled' };
}

function formatUserClientText(value) {
	return value ? orykEscape(value) : '-';
}

function formatUserClientMac(value, row) {
	return `<a href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">${value ? orykEscape(value) : '-'}</a>`;
}

function formatUserClientProfile(value, row) {
	return value ? `<a href="?display=oryk_provisioner&profile=${encodeURIComponent(row.profile_id)}">${orykEscape(value)}</a>` : '-';
}

function formatUserClientActions(value, row) {
	return `<a class="btn btn-primary btn-sm" href="?display=oryk_provisioner&client=${encodeURIComponent(row.id)}">Edit</a>`;
}

// Asks first, like every one-press change: it opens outbound calling.
$(document).on('click', '#user_promote', function () {
	if (!window.confirm(orykUserPromoteConfirm)) {
		return;
	}

	const button = $(this).prop('disabled', true);

	orykPost('promoteUser', { id: orykUserId }).done(function (response) {
		if (!response || !response.status) {
			button.prop('disabled', false);
			orykShowError(response && response.message);
			return;
		}

		window.location = '?display=oryk_provisioner&user=' + encodeURIComponent(response.id);
	}).fail(function () {
		button.prop('disabled', false);
		orykShowError('The server could not be reached.');
	});
});

orykEditor({
	save: 'saveUser',
	remove: 'deleteUser',
	confirm: orykUserDeleteConfirm,
	values: function () {
		return {
			id: orykUserId,
			extension: $('#user_extension').val(),
			name: $('#user_name').val(),
			email: $('#user_email').val(),
			from_domain: $('#user_from_domain').val(),
			secret: $('#user_secret').val()
		};
	},
	// The number the save answered with: the same one, or the new one a
	// renumbering moved it to.
	page: function (id) {
		return '?display=oryk_provisioner&user=' + encodeURIComponent(id);
	},
	closed: '?display=oryk_provisioner&tab=users'
});
