/*
 * assets/oryk_notices.js -- a notice's dismissal.
 *
 * Put on every page by Pages::view(). The notice is drawn by
 * views/partials/notice.php; the answer carries the markup of the notice that
 * takes its place, when its category has another, so nothing reloads and an
 * editor's unsaved fields are left alone.
 */
$(document).on('click', '.oryk-notice-dismiss', function () {
	var button = $(this).prop('disabled', true);
	var notice = button.closest('.oryk-notice');

	$.ajax({
		url: 'ajax.php?module=oryk_provisioner&command=dismissNotice',
		type: 'POST',
		dataType: 'json',
		data: {
			id: notice.attr('data-id'),
			version: notice.attr('data-version'),
			// The page's own query: a notice has no action on the page it leads to.
			at: window.location.search
		}
	}).done(function (response) {
		if (!response || !response.status) {
			button.prop('disabled', false);
			return;
		}

		if (response.html) {
			notice.replaceWith(response.html);
		} else {
			notice.remove();
		}
	}).fail(function () {
		button.prop('disabled', false);
	});
});
