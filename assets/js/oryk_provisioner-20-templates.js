/**
 * Oryk Provisioner - templates tab.
 */
/* global jQuery, OrykProvisioner */
(function ($, App) {
	'use strict';

	var Templates = {};

	var columns = [
		{
			field: 'name',
			formatter: function (row) {
				var builtin = row.builtin ? ' <span class="label label-info">bundled</span>' : '';

				return '<strong>' + App.esc(row.name) + '</strong>' + builtin
					+ (row.family ? '<div class="text-muted small">' + App.esc(row.family) + '</div>' : '');
			}
		},
		{
			field: 'slug',
			formatter: function (row) {
				return '<code>' + App.esc(row.slug) + '</code>';
			}
		},
		{ field: 'vendor' },
		{ field: 'outputs' },
		{ field: 'parameters' },
		{ field: 'deviceCount' },
		{
			field: 'actions',
			className: 'oryk-actions-col',
			formatter: function (row) {
				return '<div class="btn-group btn-group-xs">'
					+ button('oryk-template-edit', row.id, 'pencil', 'Edit')
					+ button('oryk-template-clone', row.id, 'copy', 'Duplicate')
					+ '<a class="btn btn-default" title="Export" target="_blank" '
					+ 'href="ajax.php?module=' + App.module + '&command=exportTemplate&id=' + App.esc(row.id) + '">'
					+ '<i class="fa fa-download"></i></a>'
					+ button('oryk-template-delete', row.id, 'trash', 'Delete', 'btn-danger')
					+ '</div>';
			}
		}
	];

	function button(cssClass, id, icon, title, style) {
		return '<button type="button" class="btn ' + (style || 'btn-default') + ' ' + cssClass + '" '
			+ 'data-id="' + App.esc(id) + '" title="' + App.esc(title) + '">'
			+ '<i class="fa fa-' + icon + '"></i></button>';
	}

	Templates.load = function () {
		return App.api('getTemplates', {
			search: $('#oryk-template-search').val() || '',
			vendor: $('#oryk-template-vendor-filter').val() || ''
		}, 'GET').done(function (response) {
			App.renderTable('#oryk-templates-table', response.rows, columns,
				'No templates yet. Add one, import a definition, or restore the bundled templates.');
		}).fail(App.fail);
	};

	Templates.edit = function (id) {
		App.api('templateForm', { id: id || 0 }, 'GET').done(function (response) {
			App.openModal({
				title: id ? 'Edit Template' : 'Add Template',
				html: response.html,
				saveLabel: 'Save Template',
				onSave: function () {
					Templates.save(id || 0);
				}
			});
		}).fail(App.fail);
	};

	/**
	 * Build the template payload: general fields, outputs, defaults and schema.
	 */
	Templates.save = function (id) {
		var $form = $('#oryk-template-form');
		var payload = App.serializeForm($form);

		payload.id = id;
		payload.outputs = collectOutputs($form);
		payload.defaults = collectDefaults($form);
		payload.parameters = collectSchema($form);

		if (!payload.outputs.length) {
			App.modalMessage('Add at least one output file.');
			return;
		}

		App.api('saveTemplate', { payload: JSON.stringify(payload) }).done(function (response) {
			App.closeModal();
			App.toast(response.message, 'success');
			Templates.load();
			App.refreshSummary();
		}).fail(function (error) {
			App.modalMessage(error.message);
			App.showFieldErrors(error.errors);
		});
	};

	function collectOutputs($form) {
		var outputs = [];

		$form.find('.oryk-output-row').each(function () {
			var $row = $(this);
			var filename = $.trim($row.find('.oryk-output-filename').val() || '');

			if (filename === '') {
				return;
			}

			outputs.push({
				id: parseInt($row.find('.oryk-output-id').val() || '0', 10),
				filename: filename,
				contentType: $row.find('.oryk-output-contenttype').val(),
				template: $row.find('.oryk-output-body').val()
			});
		});

		return outputs;
	}

	function collectDefaults($form) {
		var defaults = {};

		$form.find('.oryk-default-row').each(function () {
			var key = $.trim($(this).find('.oryk-default-key').val() || '');

			if (key === '') {
				return;
			}

			defaults[key] = $(this).find('.oryk-default-value').val();
		});

		return defaults;
	}

	function collectSchema($form) {
		var schema = {};

		$form.find('.oryk-schema-row').each(function () {
			var $row = $(this);
			var name = $.trim($row.find('.oryk-schema-name').val() || '');

			if (name === '') {
				return;
			}

			var allowed = $.trim($row.find('.oryk-schema-allowed').val() || '');
			var definition = {
				type: $row.find('.oryk-schema-type').val(),
				required: $row.find('.oryk-schema-required').is(':checked'),
				secret: $row.find('.oryk-schema-secret').is(':checked')
			};

			var defaultValue = $.trim($row.find('.oryk-schema-default').val() || '');

			if (defaultValue !== '') {
				definition['default'] = defaultValue;
			}

			if (allowed !== '') {
				definition.allowed = $.map(allowed.split(','), function (value) {
					return $.trim(value);
				});
			}

			schema[name] = definition;
		});

		return schema;
	}

	Templates.clone = function (id) {
		App.api('cloneTemplate', { id: id }).done(function (response) {
			App.toast(response.message, 'success');
			Templates.load();
		}).fail(App.fail);
	};

	Templates.remove = function (id) {
		App.confirmAction('Delete this template?', function () {
			App.api('deleteTemplate', { id: id }).done(function (response) {
				App.toast(response.message, 'success');
				Templates.load();
				App.refreshSummary();
			}).fail(App.fail);
		});
	};

	Templates.importDialog = function () {
		var html = '<p>Paste a template export below.</p>'
			+ '<textarea class="form-control oryk-code-editor" id="oryk-import-json" rows="16" '
			+ 'spellcheck="false" placeholder=\'{ "name": "...", "slug": "...", "outputs": [ ... ] }\'></textarea>';

		App.openModal({
			title: 'Import Template',
			html: html,
			saveLabel: 'Import',
			onSave: function () {
				App.api('importTemplate', { json: $('#oryk-import-json').val() }).done(function (response) {
					App.closeModal();
					App.toast(response.message, 'success');
					Templates.load();
				}).fail(function (error) {
					App.modalMessage(error.message);
				});
			}
		});
	};

	Templates.seed = function () {
		App.confirmAction(
			'Reinstall the bundled templates? Bundled templates you have edited will be overwritten.',
			function () {
				App.api('seedTemplates', { force: 1 }).done(function (response) {
					App.toast(response.message, 'success');
					Templates.load();
				}).fail(App.fail);
			}
		);
	};

	Templates.bind = function () {
		var $root = $(document);

		$root.on('click', '#oryk-template-add', function () {
			Templates.edit(0);
		});

		$root.on('click', '.oryk-template-edit', function () {
			Templates.edit($(this).data('id'));
		});

		$root.on('click', '.oryk-template-clone', function () {
			Templates.clone($(this).data('id'));
		});

		$root.on('click', '.oryk-template-delete', function () {
			Templates.remove($(this).data('id'));
		});

		$root.on('click', '#oryk-template-import', function () {
			Templates.importDialog();
		});

		$root.on('click', '#oryk-template-seed', function () {
			Templates.seed();
		});

		$root.on('change', '#oryk-template-vendor-filter', function () {
			Templates.load();
		});

		$root.on('input', '#oryk-template-search', function () {
			clearTimeout(Templates._timer);
			Templates._timer = setTimeout(Templates.load, 300);
		});

		// Keep the output panel headings in step with the filename being typed.
		$root.on('input', '.oryk-output-filename', function () {
			$(this).closest('.oryk-output-row').find('.oryk-output-title').text($(this).val());
		});
	};

	App.templates = Templates;
})(jQuery, OrykProvisioner);
