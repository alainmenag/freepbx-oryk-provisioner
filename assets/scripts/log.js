// assets/scripts/log.js -- one provisioning log entry (views/log.php).
// The orykX constants it reads are written by that view, from PHP.

orykEditor({
	save: '',
	remove: 'deleteLog',
	confirm: 'Delete this log entry?',
	values: function () {
		return { id: orykLogId };
	},
	page: function () {
		return '?display=oryk_provisioner&log=' + orykLogId;
	},
	closed: '?display=oryk_provisioner&tab=logs'
});
