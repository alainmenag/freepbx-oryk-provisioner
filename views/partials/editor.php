<?php
/**
 * views/partials/editor.php -- what the editors share.
 *
 * views/client.php, views/profile.php, views/resource.php and views/user.php
 * are one page bound to different rows: fields, and an action bar of Save,
 * Delete and Close that posts to ajax.php rather than submitting anything. Everything
 * alike about them is here -- orykPost(), orykShowError(), orykEscape(),
 * orykBytes(), formatResourceKind(), orykRenderButtons(), the placeholder chips
 * and orykEditor();
 * what is left in each view is its own fields and where Save goes next.
 *
 * Neither page contains a <form>. The module page is itself rendered inside
 * the FreePBX page form, and a nested form is dropped by the browser, which
 * left the Save button submitting the page as a GET to config.php and losing
 * display=oryk_provisioner. Values are read by id and posted explicitly.
 *
 * Which is why a tab being a link matters here. Only the pane that was asked
 * for is rendered, so on any tab but the editor's own the fields Save posts
 * are not on the page -- and $('#client_mac').val() of nothing is undefined,
 * which jQuery posts as the nine letters of it. Pages::getActionBar() draws
 * Save only on the editor's own tab, so the binding below has nothing to
 * bind to on the others. Delete and Close stay: both act on the row rather
 * than on the fields, and the row's id is printed into every one of its
 * tabs as a constant rather than read out of a hidden input in the first
 * pane.
 *
 * Both views render an alert with id `oryk_error` for orykShowError() to fill.
 *
 * A field's help follows FreePBX's own convention, which is the only one its
 * CSS shows: a `.fpbx-help-block` is hidden until the
 * `<i class="fa fa-question-circle fpbx-help-icon" data-for="<id>">` after the
 * label is hovered, and then the element with id `<id>-help` is shown.
 *
 * The placeholder chips below the template are copied by clicking one, which
 * is wired here because both editors include the same list.
 */
?>

<script>

	// Said by the copied-placeholder label, which CSS draws and so cannot
	// translate itself.
	var orykCopied = <?php echo json_encode(_('copied')); ?>;
	var orykCopyFailed = <?php echo json_encode(_('could not copy')); ?>;
	var orykRenderText = <?php echo json_encode([
		'loading' => _('Rendering...'),
		'copy' => _('Copy'),
		'copied' => _('Copied'),
		'close' => _('Close'),
		'failed' => _('The server could not be reached.'),
		'empty' => _('(empty)'),
		'unreadable' => _('This file is %s, binary or too large to show here. Open fetches it as the phone does.'),
	]); ?>;

	// Every call to the module is a POST to ajax.php with the command in the
	// query string, which is what FreePBX dispatches on.
	function orykPost(command, data) {
		return $.ajax({
			url: 'ajax.php?module=oryk_provisioner&command=' + command,
			type: 'POST',
			data: data,
			dataType: 'json'
		});
	}

	function orykShowError(message) {
		$('#oryk_error')
			.text(message || 'Something went wrong.')
			.removeClass('hidden');

		$('html, body').animate({ scrollTop: 0 }, 150);
	}

	function orykEscape(value) {
		return $('<div>').text(value === null || value === undefined ? '' : value).html();
	}

	// A byte count as somebody reads it. Firmware is why there is one: a
	// resource's file is measured in tens of megabytes, and a column of raw
	// byte counts is a column nobody reads.
	function orykBytes(bytes) {
		var size = Number(bytes);

		if (!isFinite(size) || size < 0) {
			return '';
		}

		var units = ['B', 'KB', 'MB', 'GB'];
		var unit = 0;

		while (size >= 1024 && unit < units.length - 1) {
			size = size / 1024;
			unit++;
		}

		return (unit === 0 ? size : size.toFixed(1)) + ' ' + units[unit];
	}

	// The resource's `type` column, which since 1.0.14 is the whole of what
	// says which kind it is. A size used to mean a file and its absence a
	// template, so there was no way to be a file with nothing uploaded yet
	// and no way to be a log at all. The size is still shown beside a file,
	// as what is stored rather than as the thing that decides -- and a file
	// with nothing behind it says so, because that is a resource somebody
	// has not finished and the endpoint answers it as one.
	//
	// Here rather than in the two pages with a resource table on them, for
	// the reason everything else in this partial is: one reading of a row,
	// wherever the row is drawn.
	function formatResourceKind(value, row) {
		const size = row ? row.file_size : null;

		switch (value) {
			case 'file':
				return size === null || size === undefined
					? `File <span class="text-danger">nothing uploaded</span>`
					: `File <span class="text-muted">${orykEscape(orykBytes(size))}</span>`;

			case 'log':
				return 'Log';

			default:
				return 'Template';
		}
	}

	// A row's Render, Download and Open buttons, on the two tabs where a
	// client and a resource meet. All are drawn only when the row has a URL:
	// see Previews, and why a name carrying another phone's MAC gets none.
	//
	// Open is the endpoint URL, so the browser is asked for the client's
	// token when it has one, and the fetch counts as the client being seen.
	// Render and Download read the same body through the admin's own session
	// instead.
	function orykRenderButtons(row, clientId, resourceId) {
		if (!row.url) {
			return [];
		}

		const download = 'ajax.php?module=oryk_provisioner&command=downloadResource' +
			'&client_id=' + encodeURIComponent(clientId) +
			'&resource_id=' + encodeURIComponent(resourceId);

		return [
			`<button type="button" class="btn btn-default btn-sm oryk-render" data-client="${orykEscape(clientId)}" data-resource="${orykEscape(resourceId)}" title="Render: show this resource as this client receives it" aria-label="Render">${orykIcon('eye')}</button>`,
			`<a class="btn btn-default btn-sm" href="${orykEscape(download)}" title="Download: save this resource as this client receives it" aria-label="Download">${orykIcon('download')}</a>`,
			`<a class="btn btn-default btn-sm" href="${orykEscape(row.url)}" target="_blank" title="Open: fetch this resource from the endpoint, as this client does" aria-label="Open">${orykIcon('open')}</a>`
		];
	}

	// A row button's symbol: a 24-unit outline in the button's own colour,
	// so it follows the theme. The name is ours, never a request's.
	function orykIcon(name) {
		const paths = {
			eye: '<path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>',
			download: '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 17v3h16v-3"/>',
			open: '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v6H4V6h6"/>'
		};

		return '<svg class="oryk-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor"' +
			' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths[name] + '</svg>';
	}

	/**
	 * Show one resource as one client receives it, in a modal.
	 *
	 * The modal is made the first time it is wanted and kept. It has no close
	 * cross, which Bootstrap 3 and 4 place differently; Close, Escape and the
	 * backdrop all dismiss it.
	 *
	 * @param {*} clientId   Client id.
	 * @param {*} resourceId Resource id.
	 */
	function orykRender(clientId, resourceId) {
		var modal = $('#oryk_render');

		if (!modal.length) {
			modal = $(
				'<div class="modal fade" id="oryk_render" tabindex="-1" role="dialog">' +
					'<div class="modal-dialog modal-lg" role="document">' +
						'<div class="modal-content">' +
							'<div class="modal-header"><h4 class="modal-title oryk-name"></h4></div>' +
							'<div class="modal-body"><pre class="oryk-template oryk-render-body"></pre></div>' +
							'<div class="modal-footer">' +
								'<button type="button" class="btn btn-default oryk-render-copy"></button>' +
								'<button type="button" class="btn btn-primary oryk-render-close"></button>' +
							'</div>' +
						'</div>' +
					'</div>' +
				'</div>'
			).appendTo('body');

			modal.find('.oryk-render-close').text(orykRenderText.close).on('click', function () {
				modal.modal('hide');
			});

			modal.find('.oryk-render-copy').on('click', function () {
				var button = $(this);

				orykCopy(modal.data('body') || '').done(function (copied) {
					button.text(copied ? orykRenderText.copied : orykCopyFailed);

					window.setTimeout(function () {
						button.text(orykRenderText.copy);
					}, 1000);
				});
			});
		}

		var title = modal.find('.modal-title').text(orykRenderText.loading);
		var body = modal.find('.oryk-render-body').removeClass('text-danger text-muted').text('');
		var copy = modal.find('.oryk-render-copy').text(orykRenderText.copy).addClass('hidden');

		modal.data('body', '').modal('show');

		orykPost('previewResource', { client_id: clientId, resource_id: resourceId }).done(function (response) {
			if (!response || !response.status) {
				title.text('');
				body.addClass('text-danger').text((response && response.message) || orykRenderText.failed);
				return;
			}

			title.text(response.filename);

			if (typeof response.body !== 'string') {
				body.addClass('text-muted').text(orykRenderText.unreadable.replace('%s', orykBytes(response.size)));
				return;
			}

			if (response.body === '') {
				body.addClass('text-muted').text(orykRenderText.empty);
				return;
			}

			modal.data('body', response.body);
			body.text(response.body);
			copy.removeClass('hidden');
		}).fail(function () {
			title.text('');
			body.addClass('text-danger').text(orykRenderText.failed);
		});
	}

	$(document).on('click', '.oryk-render', function (event) {
		event.preventDefault();
		orykRender($(this).data('client'), $(this).data('resource'));
	});

	/**
	 * Put text on the clipboard, whichever way this browser allows.
	 *
	 * navigator.clipboard exists only in a secure context, and a FreePBX GUI
	 * is as often reached over plain http on the LAN as over https, so the
	 * old execCommand path is the one that usually runs and is not a
	 * fallback for old browsers so much as for unencrypted ones.
	 */
	function orykCopy(text) {
		var done = $.Deferred();

		if (window.navigator && navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(function () {
				done.resolve(true);
			}, function () {
				done.resolve(false);
			});

			return done.promise();
		}

		var field = $('<textarea>')
			.val(text)
			.css({ position: 'fixed', top: 0, left: 0, opacity: 0 })
			.appendTo('body');

		var copied = false;

		try {
			field[0].select();
			field[0].setSelectionRange(0, text.length);
			copied = document.execCommand('copy');
		} catch (error) {
			copied = false;
		}

		field.remove();

		return done.resolve(copied).promise();
	}

	// A placeholder is only ever wanted in the template above it, so clicking
	// one copies its name. The label is drawn by CSS off the attribute rather
	// than by inserting anything, which would move the chips around.
	$(document).on('click', '.oryk-placeholder-group code', function () {
		var chip = $(this);

		orykCopy(chip.text()).done(function (copied) {
			chip.attr('data-oryk-copied', copied ? orykCopied : orykCopyFailed);

			window.setTimeout(function () {
				chip.removeAttr('data-oryk-copied');
			}, 1000);
		});
	});

	/**
	 * Wire the action bar to one editor.
	 *
	 * The buttons are ours rather than the submit/delete names core wires to
	 * a `form.fpbx-submit`: this page has no form, and a save here is an AJAX
	 * post, not a page submit.
	 *
	 * Each handler is delegated off the document, so a button the action bar
	 * did not draw -- Save on a tab that has no fields, Delete on a row that
	 * has never been written -- is simply one that never fires.
	 *
	 * Save stays on the row it wrote. For a row that already existed that URL
	 * is the one in the address bar, so the page reloads showing what was
	 * written; for a new one it is the id the save handed back, which is the
	 * first address that row has ever had. Either way the editor open
	 * afterwards is the editor that was open before -- which is what the
	 * fields are for, since half of each editor exists only once the row does:
	 * a resource's file box and template, a profile's Resources tab.
	 *
	 * editor.values()  what to post, including the row id
	 * editor.save      command that writes it
	 * editor.remove    command that deletes it
	 * editor.confirm   what Delete asks before it does
	 * editor.page(id)  this editor's own URL for a row
	 * editor.closed    where Close and a finished Delete go
	 */
	function orykEditor(editor) {
		$(document).on('click', '#oryksave', function (event) {
			event.preventDefault();

			// Held down until the answer: a user's save runs a full reload,
			// and a second press would post the form again underneath it.
			var button = $(this).prop('disabled', true);

			orykPost(editor.save, editor.values()).done(function (response) {
				if (!response || !response.status) {
					button.prop('disabled', false);
					orykShowError(response && response.message);
					return;
				}

				window.location = editor.page(response.id);
			}).fail(function () {
				button.prop('disabled', false);
				orykShowError('The server could not be reached.');
			});
		});

		$(document).on('click', '#orykdelete', function (event) {
			event.preventDefault();

			if (!window.confirm(editor.confirm)) {
				return;
			}

			orykPost(editor.remove, { id: editor.values().id }).done(function (response) {
				if (!response || !response.status) {
					orykShowError(response && response.message);
					return;
				}

				window.location = editor.closed;
			}).fail(function () {
				orykShowError('The server could not be reached.');
			});
		});

		$(document).on('click', '#orykclose', function (event) {
			event.preventDefault();
			window.location = editor.closed;
		});
	}

</script>
