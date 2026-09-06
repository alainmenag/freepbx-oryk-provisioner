/**
 * Oryk Provisioner - shared admin helpers.
 *
 * Everything the interface does goes through OrykProvisioner.api(), so all
 * requests share one response contract, one error path and one toast.
 *
 * Files in assets/js are loaded alphabetically by FreePBX, which is why they
 * are numbered: 00 (core) first, then the feature modules, then 90 (init).
 */
/* global jQuery, fpbxToast */
var OrykProvisioner = (function ($) {
	'use strict';

	var MODULE = 'oryk_provisioner';
	var AJAX_URL = 'ajax.php';

	/**
	 * Call a module AJAX command.
	 *
	 * @param {string} command
	 * @param {object} [data]
	 * @param {string} [method] POST by default.
	 * @returns {jQuery.Promise} Resolved with the payload, rejected with an Error.
	 */
	function api(command, data, method) {
		var deferred = $.Deferred();
		var payload = $.extend({ module: MODULE, command: command }, data || {});

		$.ajax({
			url: AJAX_URL,
			type: method || 'POST',
			data: payload,
			dataType: 'json'
		}).done(function (response) {
			if (!response || typeof response !== 'object') {
				deferred.reject(new Error('Unexpected response from the server.'));
				return;
			}

			if (response.status === false) {
				var error = new Error(response.message || 'The request failed.');
				error.errors = response.errors || {};
				deferred.reject(error);
				return;
			}

			deferred.resolve(response);
		}).fail(function (xhr) {
			deferred.reject(new Error(describeFailure(xhr)));
		});

		return deferred.promise();
	}

	function describeFailure(xhr) {
		if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
			return xhr.responseJSON.message;
		}

		if (xhr && xhr.status === 403) {
			return 'Your session has expired. Reload the page and sign in again.';
		}

		return 'The request could not be completed.';
	}

	/**
	 * Notify the administrator, preferring the FreePBX toast when present.
	 */
	function toast(message, level) {
		if (!message) {
			return;
		}

		if (typeof fpbxToast === 'function') {
			fpbxToast(message, '', level === 'error' ? 'error' : (level || 'info'));
			return;
		}

		var $host = $('#oryk-inline-toast');

		if (!$host.length) {
			$host = $('<div id="oryk-inline-toast" class="oryk-toast"></div>').appendTo('body');
		}

		$host.attr('class', 'oryk-toast oryk-toast-' + (level || 'info')).text(message).stop(true, true)
			.fadeIn(120).delay(3200).fadeOut(300);
	}

	function fail(error) {
		toast(error && error.message ? error.message : 'Something went wrong.', 'error');
	}

	/**
	 * HTML escape a value for insertion into generated markup.
	 */
	function esc(value) {
		if (value === null || value === undefined) {
			return '';
		}

		return String(value)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	/**
	 * Render rows into a table body.
	 *
	 * @param {string} selector Table selector.
	 * @param {Array}  rows
	 * @param {Array}  columns [{ field, formatter(row) }]
	 * @param {string} emptyMessage
	 */
	function renderTable(selector, rows, columns, emptyMessage) {
		var $body = $(selector).find('tbody');
		var html = '';

		if (!rows || !rows.length) {
			$body.html('<tr class="oryk-empty"><td colspan="' + columns.length + '">'
				+ esc(emptyMessage || 'Nothing to show yet.') + '</td></tr>');
			return;
		}

		$.each(rows, function (index, row) {
			html += '<tr data-id="' + esc(row.id) + '">';

			$.each(columns, function (position, column) {
				var value = column.formatter
					? column.formatter(row)
					: esc(row[column.field]);

				html += '<td' + (column.className ? ' class="' + column.className + '"' : '') + '>' + value + '</td>';
			});

			html += '</tr>';
		});

		$body.html(html);
	}

	/**
	 * Collect [data-oryk-field] inputs into a plain object.
	 */
	function serializeForm($form) {
		var data = {};

		$form.find('[data-oryk-field]').each(function () {
			var $field = $(this);
			var name = $field.data('oryk-field');

			if ($field.is(':checkbox')) {
				data[name] = $field.is(':checked') ? 1 : 0;
				return;
			}

			data[name] = $field.val();
		});

		return data;
	}

	/**
	 * Open the shared modal.
	 *
	 * @param {object} options title, html, saveLabel, showSave, onSave, size
	 */
	function openModal(options) {
		var settings = $.extend({
			title: '',
			html: '',
			saveLabel: 'Save',
			showSave: true,
			size: 'modal-lg',
			onSave: null
		}, options || {});

		var $modal = $('#oryk-modal');

		$modal.find('.modal-dialog').attr('class', 'modal-dialog ' + settings.size);
		$('#oryk-modal-title').text(settings.title);
		$('#oryk-modal-body').html(settings.html);
		$('#oryk-modal-message').text('');

		var $save = $('#oryk-modal-save').text(settings.saveLabel).off('click');

		if (settings.showSave && typeof settings.onSave === 'function') {
			$save.show().on('click', function () {
				settings.onSave($modal, $save);
			});
		} else {
			$save.hide();
		}

		$modal.modal('show');

		return $modal;
	}

	function closeModal() {
		$('#oryk-modal').modal('hide');
	}

	function modalMessage(message) {
		$('#oryk-modal-message').text(message || '');
	}

	/**
	 * Highlight the fields a validation error refers to.
	 */
	function showFieldErrors(errors) {
		$('.oryk-form .has-error').removeClass('has-error');

		if (!errors) {
			return;
		}

		$.each(errors, function (field) {
			$('[data-oryk-field="' + field + '"]').closest('.form-group').addClass('has-error');
		});
	}

	/**
	 * Ask before doing something destructive.
	 */
	function confirmAction(message, onConfirm) {
		openModal({
			title: 'Please confirm',
			html: '<p>' + esc(message) + '</p>',
			saveLabel: 'Confirm',
			size: 'modal-sm',
			onSave: function () {
				closeModal();
				onConfirm();
			}
		});
	}

	/**
	 * Copy text to the clipboard, with a fallback for older browsers.
	 */
	function copyText(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				toast('Copied to clipboard.', 'info');
			}, function () {
				legacyCopy(text);
			});
			return;
		}

		legacyCopy(text);
	}

	function legacyCopy(text) {
		var $temp = $('<textarea>').css({ position: 'fixed', opacity: 0 }).val(text).appendTo('body');

		$temp[0].select();

		try {
			document.execCommand('copy');
			toast('Copied to clipboard.', 'info');
		} catch (e) {
			toast('Copy failed - select the text manually.', 'error');
		}

		$temp.remove();
	}

	function formatDate(value) {
		if (!value) {
			return '<span class="text-muted">never</span>';
		}

		return esc(value);
	}

	function badge(enabled) {
		return enabled
			? '<span class="label label-success">Enabled</span>'
			: '<span class="label label-default">Disabled</span>';
	}

	/**
	 * Wire the generic repeater controls (add/remove rows) once.
	 */
	function bindRepeaters(scope) {
		$(scope || document)
			.off('click.orykRepeater')
			.on('click.orykRepeater', '.oryk-repeater-add', function () {
				var $button = $(this);
				var markup = $('#' + $button.data('row')).html();

				$($button.data('repeater')).append(markup);
			})
			.on('click.orykRepeater', '.oryk-repeater-remove', function () {
				$(this).closest('.oryk-repeater-row, .oryk-output-row, .oryk-schema-row').remove();
			})
			.on('click.orykRepeater', '.oryk-copy', function () {
				var $button = $(this);
				var target = $button.data('copy-target');

				copyText(target ? $(target).text() : String($button.data('copy')));
			});
	}

	return {
		module: MODULE,
		api: api,
		toast: toast,
		fail: fail,
		esc: esc,
		renderTable: renderTable,
		serializeForm: serializeForm,
		openModal: openModal,
		closeModal: closeModal,
		modalMessage: modalMessage,
		showFieldErrors: showFieldErrors,
		confirmAction: confirmAction,
		copyText: copyText,
		formatDate: formatDate,
		badge: badge,
		bindRepeaters: bindRepeaters
	};
})(jQuery);
