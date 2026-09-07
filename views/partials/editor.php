<?php
/**
 * views/partials/editor.php -- what the profile and resource editors share.
 *
 * The two are the same page bound to different columns: a name, a block of
 * configuration text, and an action bar of Save, Delete and Close that posts
 * to ajax.php rather than submitting anything. Everything alike about them is
 * here; what is left in each view is its own fields and where Save goes next.
 *
 * Neither page contains a <form>. The module page is itself rendered inside
 * the FreePBX page form, and a nested form is dropped by the browser, which
 * left the Save button submitting the page as a GET to config.php and losing
 * display=oryk_provisioner. Values are read by id and posted explicitly.
 *
 * Both views render an alert with id `oryk_error` for orykShowError() to fill.
 *
 * The placeholder chips below the template are copied by clicking one, which
 * is wired here because both editors include the same list.
 */
?>
<style>
	.oryk-template {
		font-family: monospace;
		white-space: pre;
	}
	.oryk-name {
		font-family: monospace;
	}
	.oryk-placeholders {
		padding-top: 6px;
	}
	.oryk-placeholder-group {
		margin-bottom: 4px;
	}
	.oryk-placeholder-group strong {
		display: block;
		font-weight: normal;
		color: #777;
	}
	.oryk-placeholder-group code {
		display: inline-block;
		position: relative;
		margin: 2px 4px 0 0;
		cursor: pointer;
	}
	.oryk-placeholder-group code[data-oryk-copied]::after {
		content: attr(data-oryk-copied);
		position: absolute;
		left: 50%;
		bottom: 100%;
		transform: translateX(-50%);
		margin-bottom: 2px;
		padding: 0 4px;
		border-radius: 2px;
		background: #333;
		color: #fff;
		font-family: sans-serif;
		font-size: 11px;
		line-height: 16px;
		white-space: nowrap;
		pointer-events: none;
	}
	.oryk-toolbar {
		padding-bottom: 5px;
	}
	.oryk-tab-section {
		padding-top: 15px;
	}
	.oryk-crumb {
		padding-bottom: 10px;
	}
	.flex {
		display: flex;
	}
	.gap-3 {
		gap: 3px;
	}
</style>

<script>

	// Said by the copied-placeholder label, which CSS draws and so cannot
	// translate itself.
	var orykCopied = <?php echo json_encode(_('copied')); ?>;
	var orykCopyFailed = <?php echo json_encode(_('could not copy')); ?>;

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
	 * editor.values()  what to post, including the row id
	 * editor.save      command that writes it
	 * editor.remove    command that deletes it
	 * editor.confirm   what Delete asks before it does
	 * editor.saved(r)  where to go once it is written
	 * editor.closed    where Close and a finished Delete go
	 */
	function orykEditor(editor) {
		$(document).on('click', '#oryksave', function (event) {
			event.preventDefault();

			orykPost(editor.save, editor.values()).done(function (response) {
				if (!response || !response.status) {
					orykShowError(response && response.message);
					return;
				}

				window.location = editor.saved(response);
			}).fail(function () {
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
