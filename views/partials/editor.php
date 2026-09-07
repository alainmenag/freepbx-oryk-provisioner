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
	.oryk-assigned-macs {
		padding-top: 4px;
	}
	.oryk-assigned-device {
		display: inline-block;
		margin-right: 10px;
		white-space: nowrap;
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
		margin: 2px 4px 0 0;
		cursor: help;
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
