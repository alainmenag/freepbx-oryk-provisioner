// assets/scripts/ban.js -- the ban editor (views/ban.php).
// The orykX constants it reads are written by that view, from PHP.

// Seconds as a coarse age, the way the lists say it.
function orykBanAge(seconds) {
	const units = [[31536000, 'year'], [2592000, 'month'], [86400, 'day'], [3600, 'hour'], [60, 'minute']];
	const age = Math.abs(Number(seconds) || 0);

	for (const [size, name] of units) {
		if (age >= size) {
			const count = Math.floor(age / size);

			return `${count} ${name}${count === 1 ? '' : 's'}`;
		}
	}

	return '';
}

$('[data-oryk-since]').each(function () {
	const age = orykBanAge($(this).data('oryk-since'));
	$(this).text(age ? `(${age} ago)` : '(just now)');
});

$('[data-oryk-in]').each(function () {
	const seconds = Number($(this).data('oryk-in')) || 0;
	const left = orykBanAge(seconds);

	if (seconds <= 0) {
		$(this).text(left ? `(${left} ago)` : '(just now)');
		return;
	}

	$(this).text(left ? `(in ${left})` : '(any moment)');
});

// Only a temporary ban has a length.
$('#ban_state').on('change', function () {
	$('#ban_minutes-container').toggle($(this).val() === 'banned');
});

// Registered before orykEditor(), so a declined warning stops its Save. Only
// an address on its own refuses a whole site.
$(document).on('click', '#oryksave', function (event) {
	const narrowed = ['#ban_mac', '#ban_user', '#ban_client', '#ban_profile'].some((field) => $.trim($(field).val()) !== '');

	if (narrowed || $('#ban_state').val() === 'allow') {
		return;
	}

	const ip = $.trim($('#ban_ip').val()).toLowerCase();
	const client = orykBanAddresses[ip];
	let ask = '';

	if (ip !== '' && ip === orykBanRemote) {
		ask = `${ip} is the address you are connected from. Phones at your site will not be provisioned, and with Fail2ban Sync on, fail2ban bans it too -- a Deny on every port, this page included. Ban it anyway?`;
	} else if (client) {
		ask = `${ip} is the public address of ${client.label}${client.count > 1 ? ` and ${client.count - 1} more` : ''}. Every phone at that site will be refused. Ban it anyway?`;
	}

	if (ask && !window.confirm(ask)) {
		event.preventDefault();
		event.stopImmediatePropagation();
	}
});

orykEditor({
	save: 'saveBan',
	remove: 'deleteBan',
	confirm: 'Delete this ban? What it matched is answered again from the next request.',
	values: function () {
		return {
			id: orykBanId || '',
			ip: $('#ban_ip').val(),
			mac: $('#ban_mac').val(),
			user: $('#ban_user').val(),
			client: $('#ban_client').val(),
			profile: $('#ban_profile').val(),
			state: $('#ban_state').val(),
			minutes: $('#ban_minutes').val(),
			note: $('#ban_note').val(),
			source: $('#ban_source').val(),
			jail: $('#ban_jail').val()
		};
	},
	page: function (id) {
		return '?display=oryk_provisioner&ban=' + encodeURIComponent(id);
	},
	closed: '?display=oryk_provisioner&tab=bans'
});
