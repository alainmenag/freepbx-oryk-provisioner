// assets/scripts/logs.js -- the provisioning log table (views/partials/logs.php).
// The orykX constants it reads are written by that view, from PHP.

// The time is the link to the entry's own page.
function formatLogTime(value, row) {
	const time = value ? orykEscape(value) : '-';

	return `<a class="oryk-log-time" href="?display=oryk_provisioner&log=${encodeURIComponent(row.id)}">${time}</a>`;
}

// The MAC links to the client it belongs to *now*. One that belongs to
// nothing is the row worth having a log for at all, and it is drawn as
// plain text rather than a dead link.
function formatLogMac(value, row) {
	if (!value) {
		return '-';
	}

	const mac = orykEscape(value);

	return row.client_id
		? `<a href="?display=oryk_provisioner&client=${encodeURIComponent(row.client_id)}">${mac}</a>`
		: `<code>${mac}</code>`;
}

function formatLogClient(value, row) {
	if (!row.client_id) {
		return `<span class="text-muted">${orykEscape(orykLogUnknown)}</span>`;
	}

	return value ? orykEscape(value) : '-';
}

// The reason a request failed sits under the filename it failed for,
// which is the pair anyone reading this log is reading it for. An empty
// filename is /provisioner/?mac=..., a caller with no name to give, and
// the module reads that as the main config.
function formatLogFilename(value, row) {
	const name = value
		? `<code>${orykEscape(value)}</code>`
		: `<span class="text-muted">${orykEscape(orykLogMainConfig)}</span>`;

	return row.message
		? `${name}<span class="oryk-log-detail">${orykEscape(row.message)}</span>`
		: name;
}

// The method is on the status rather than in a column of its own: all but
// a handful of rows are a fetch, so GET and HEAD say so in the tooltip
// alone and anything else is written out beside the code. A phone PUTting
// a log is the reason that is worth doing -- a 200 for something received
// and a 200 for something served are the same number and not the same
// event, and the filename beside them does not always say which.
function formatLogStatus(value, row) {
	const code = Number(value) || 0;
	const style = code >= 200 && code < 300 ? 'label-success' : 'label-danger';
	const method = row.method ? orykEscape(row.method) : '';
	const shown = method && method !== 'GET' && method !== 'HEAD' ? `${method} ` : '';

	return `<span class="label ${style}" title="${method}">${shown}${code || '-'}</span>`;
}

// Which phone, in the two ways a phone says so without being asked: the
// address it came from and the User-Agent it announced, which is where
// the model and the firmware are.
function formatLogSource(value, row) {
	const ip = value ? orykEscape(value) : '-';

	if (!row.user_agent) {
		return ip;
	}

	const agent = orykEscape(row.user_agent);

	return `${ip}<span class="oryk-log-agent" title="${agent}">${agent}</span>`;
}

$(document).on('click', '[name="log_clear"]', function () {
	if (!window.confirm(orykLogClearConfirm)) {
		return;
	}

	orykPost('clearLogs', orykLogClear).done(function (response) {
		if (!response || !response.status) {
			notie.alert(3, (response && response.message) || 'Could not clear the log.', 4);
			return;
		}

		$('#log_table').bootstrapTable('refresh');
		notie.alert(1, 'Cleared.', 2);
	});
});
