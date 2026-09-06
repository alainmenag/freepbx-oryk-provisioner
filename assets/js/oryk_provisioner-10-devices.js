/**
 * Oryk Provisioner - devices tab.
 */
/* global jQuery, OrykProvisioner */
(function ($, App) {
	'use strict';

	var Devices = {};

	var columns = [
		{
			field: 'name',
			formatter: function (row) {
				return '<strong>' + App.esc(row.name) + '</strong>'
					+ '<div class="text-muted small">' + App.esc(row.identifier) + '</div>';
			}
		},
		{ field: 'extension' },
		{ field: 'templateName' },
		{
			field: 'mac',
			formatter: function (row) {
				return row.macDisplay ? '<code>' + App.esc(row.macDisplay) + '</code>' : '';
			}
		},
		{
			field: 'enabled',
			formatter: function (row) {
				return '<a href="#" class="oryk-device-toggle" data-id="' + App.esc(row.id) + '" '
					+ 'data-enabled="' + (row.enabled ? 1 : 0) + '">' + App.badge(row.enabled) + '</a>';
			}
		},
		{
			field: 'lastProvisionedAt',
			formatter: function (row) {
				return App.formatDate(row.lastProvisionedAt);
			}
		},
		{
			field: 'actions',
			className: 'oryk-actions-col',
			formatter: function (row) {
				return '<div class="btn-group btn-group-xs">'
					+ button('oryk-device-edit', row.id, 'pencil', 'Edit')
					+ button('oryk-device-preview', row.id, 'eye', 'Preview configuration')
					+ button('oryk-device-urls', row.id, 'link', 'Provisioning URLs')
					+ button('oryk-device-token', row.id, 'refresh', 'Regenerate token')
					+ button('oryk-device-delete', row.id, 'trash', 'Delete', 'btn-danger')
					+ '</div>';
			}
		}
	];

	function button(cssClass, id, icon, title, style) {
		return '<button type="button" class="btn ' + (style || 'btn-default') + ' ' + cssClass + '" '
			+ 'data-id="' + App.esc(id) + '" title="' + App.esc(title) + '">'
			+ '<i class="fa fa-' + icon + '"></i></button>';
	}

	/**
	 * Load and draw the device table.
	 */
	Devices.load = function () {
		return App.api('getDevices', {
			search: $('#oryk-device-search').val() || '',
			templateId: $('#oryk-device-template-filter').val() || ''
		}, 'GET').done(function (response) {
			App.renderTable('#oryk-devices-table', response.rows, columns,
				'No devices yet. Add one to generate its provisioning URL.');
		}).fail(App.fail);
	};

	/**
	 * Open the add/edit form.
	 */
	Devices.edit = function (id) {
		App.api('deviceForm', { id: id || 0 }, 'GET').done(function (response) {
			App.openModal({
				title: id ? 'Edit Device' : 'Add Device',
				html: response.html,
				saveLabel: 'Save Device',
				onSave: function () {
					Devices.save(id || 0);
				}
			});
		}).fail(App.fail);
	};

	/**
	 * Serialize the form (fields + schema parameters + extra parameters) and save.
	 */
	Devices.save = function (id) {
		var $form = $('#oryk-device-form');
		var payload = App.serializeForm($form);

		payload.id = id;
		payload.parameters = collectParameters($form);

		App.api('saveDevice', { payload: JSON.stringify(payload) }).done(function (response) {
			App.closeModal();
			App.toast(response.message, 'success');
			Devices.load();
			App.refreshSummary();
		}).fail(function (error) {
			App.modalMessage(error.message);
			App.showFieldErrors(error.errors);
		});
	};

	/**
	 * Schema driven inputs plus free form rows, empty values omitted so the
	 * resolution order keeps working.
	 */
	function collectParameters($form) {
		var parameters = {};

		$form.find('.oryk-param').each(function () {
			var $field = $(this);
			var value = $field.val();

			if (value === null || value === '') {
				return;
			}

			parameters[$field.data('param-name')] = value;
		});

		$form.find('.oryk-extra-row').each(function () {
			var key = $.trim($(this).find('.oryk-extra-key').val() || '');

			if (key === '') {
				return;
			}

			parameters[key] = $(this).find('.oryk-extra-value').val();
		});

		return parameters;
	}

	/**
	 * Reload the parameter section when the template changes.
	 */
	Devices.reloadParameters = function (templateId) {
		if (!templateId) {
			return;
		}

		App.api('templateSchema', { id: templateId }, 'GET').done(function (response) {
			$('#oryk-parameters-host').html(response.html);
		}).fail(App.fail);
	};

	Devices.preview = function (id, reveal) {
		App.api('previewDevice', { id: id, reveal: reveal ? 1 : 0 }, 'GET').done(function (response) {
			App.openModal({
				title: 'Configuration Preview',
				html: response.html,
				showSave: false
			});
		}).fail(App.fail);
	};

	Devices.urls = function (id) {
		App.api('deviceUrls', { id: id }, 'GET').done(function (response) {
			var html = '<div class="oryk-urls-modal"></div>';

			App.openModal({ title: 'Provisioning URLs', html: html, showSave: false });
			renderUrls($('#oryk-modal-body .oryk-urls-modal'), response.urls);
		}).fail(App.fail);
	};

	function renderUrls($host, urls) {
		if (!urls || !urls.length) {
			$host.html('<p class="text-muted">This device produces no files yet.</p>');
			return;
		}

		var html = '';

		$.each(urls, function (index, entry) {
			html += '<div class="oryk-url-block"><div class="oryk-url-file"><i class="fa fa-file-o"></i> <strong>'
				+ App.esc(entry.filename) + '</strong> <span class="text-muted">'
				+ App.esc(entry.contentType) + '</span></div>';

			$.each(entry.urls, function (type, url) {
				html += '<div class="input-group input-group-sm oryk-url-row">'
					+ '<span class="input-group-addon">' + App.esc(type) + '</span>'
					+ '<input type="text" class="form-control oryk-url-input" readonly value="' + App.esc(url) + '">'
					+ '<span class="input-group-btn"><button type="button" class="btn btn-default oryk-copy" '
					+ 'data-copy="' + App.esc(url) + '"><i class="fa fa-clipboard"></i></button></span></div>';
			});

			html += '</div>';
		});

		$host.html(html);
	}

	Devices.toggle = function (id, enabled) {
		App.api('toggleDevice', { id: id, enabled: enabled ? 1 : 0 }).done(function (response) {
			App.toast(response.message, 'success');
			Devices.load();
		}).fail(App.fail);
	};

	Devices.regenerate = function (id) {
		App.confirmAction(
			'Issue a new provisioning token? The current URLs stop working immediately.',
			function () {
				App.api('regenerateToken', { id: id }).done(function (response) {
					App.toast(response.message, 'success');
					Devices.load();
				}).fail(App.fail);
			}
		);
	};

	Devices.remove = function (id) {
		App.confirmAction('Delete this device? Its provisioning URLs stop working.', function () {
			App.api('deleteDevice', { id: id }).done(function (response) {
				App.toast(response.message, 'success');
				Devices.load();
				App.refreshSummary();
			}).fail(App.fail);
		});
	};

	/**
	 * Event wiring, all delegated so re-rendered tables keep working.
	 */
	Devices.bind = function () {
		var $root = $(document);

		$root.on('click', '#oryk-device-add', function () {
			Devices.edit(0);
		});

		$root.on('click', '#oryk-device-refresh', function () {
			Devices.load();
		});

		$root.on('click', '.oryk-device-edit', function () {
			Devices.edit($(this).data('id'));
		});

		$root.on('click', '.oryk-device-preview', function () {
			Devices.preview($(this).data('id'), false);
		});

		$root.on('change', '#oryk-preview-reveal', function () {
			Devices.preview($(this).data('id'), $(this).is(':checked'));
		});

		$root.on('click', '.oryk-device-urls', function () {
			Devices.urls($(this).data('id'));
		});

		$root.on('click', '.oryk-device-token, #oryk-device-regenerate', function () {
			Devices.regenerate($(this).data('id'));
		});

		$root.on('click', '.oryk-device-delete', function () {
			Devices.remove($(this).data('id'));
		});

		$root.on('click', '.oryk-device-toggle', function (event) {
			event.preventDefault();
			var $link = $(this);
			Devices.toggle($link.data('id'), $link.data('enabled') ? 0 : 1);
		});

		$root.on('change', '#oryk-device-template', function () {
			Devices.reloadParameters($(this).val());
		});

		$root.on('input', '#oryk-device-search', debounce(function () {
			Devices.load();
		}, 300));

		$root.on('change', '#oryk-device-template-filter', function () {
			Devices.load();
		});
	};

	function debounce(callback, wait) {
		var timer = null;

		return function () {
			var context = this;
			var args = arguments;

			clearTimeout(timer);
			timer = setTimeout(function () {
				callback.apply(context, args);
			}, wait);
		};
	}

	App.devices = Devices;
})(jQuery, OrykProvisioner);
