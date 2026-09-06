/**
 * Oryk Provisioner - provisioning log tab.
 */
/* global jQuery, OrykProvisioner */
(function ($, App) {
	'use strict';

	var Logs = {};

	var statusLabels = {
		success: 'label-success',
		notfound: 'label-warning',
		denied: 'label-danger',
		error: 'label-danger'
	};

	var columns = [
		{ field: 'created_at' },
		{
			field: 'identifier',
			formatter: function (row) {
				return row.identifier
					? App.esc(row.identifier)
					: '<span class="text-muted">unknown</span>';
			}
		},
		{
			field: 'filename',
			formatter: function (row) {
				return row.filename ? '<code>' + App.esc(row.filename) + '</code>' : '';
			}
		},
		{
			field: 'status',
			formatter: function (row) {
				var style = statusLabels[row.status] || 'label-default';
				var text = App.esc(row.status) + ' ' + App.esc(row.http_code);

				return '<span class="label ' + style + '">' + text + '</span>'
					+ (row.message ? ' <span class="text-muted small">' + App.esc(row.message) + '</span>' : '');
			}
		},
		{ field: 'ip' },
		{
			field: 'user_agent',
			formatter: function (row) {
				return '<span class="oryk-ua" title="' + App.esc(row.user_agent) + '">'
					+ App.esc(row.user_agent) + '</span>';
			}
		}
	];

	Logs.load = function () {
		return App.api('getLogs', {
			status: $('#oryk-log-status').val() || '',
			search: $('#oryk-log-search').val() || '',
			limit: 250
		}, 'GET').done(function (response) {
			App.renderTable('#oryk-logs-table', response.rows, columns,
				'No provisioning requests recorded yet.');
		}).fail(App.fail);
	};

	Logs.clear = function () {
		App.confirmAction('Delete every provisioning log entry?', function () {
			App.api('clearLogs').done(function (response) {
				App.toast(response.message, 'success');
				Logs.load();
			}).fail(App.fail);
		});
	};

	Logs.bind = function () {
		var $root = $(document);

		$root.on('click', '#oryk-log-refresh', function () {
			Logs.load();
		});

		$root.on('click', '#oryk-log-clear', function () {
			Logs.clear();
		});

		$root.on('change', '#oryk-log-status', function () {
			Logs.load();
		});

		$root.on('input', '#oryk-log-search', function () {
			clearTimeout(Logs._timer);
			Logs._timer = setTimeout(Logs.load, 300);
		});
	};

	App.logs = Logs;
})(jQuery, OrykProvisioner);
