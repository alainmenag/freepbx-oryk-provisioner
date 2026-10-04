// assets/scripts/settings.js -- the Settings tab (views/partials/settings.php).

// The values are read off the fields by keyword, so this knows no setting
// by name. A radioset is read by its checked button.
function orykSettingsValues() {
	const values = {};

	$('[data-oryk-setting]').each(function () {
		const field = $(this);

		values[field.attr('data-oryk-setting')] = field.is('.radioset')
			? field.find('input:checked').val()
			: field.val();
	});

	return { settings: values };
}

$(document).on('click', '#oryksave', function (event) {
	event.preventDefault();

	const button = $(this).prop('disabled', true);

	const failed = function (message) {
		button.prop('disabled', false);
		$('#oryk_error').text(message || 'Something went wrong.').removeClass('hidden');
		$('html, body').animate({ scrollTop: 0 }, 150);
	};

	orykPost('saveSettings', orykSettingsValues()).done(function (response) {
		if (!response || !response.status) {
			failed(response && response.message);
			return;
		}

		// Saved already: said before the reload, which would wipe a notice.
		if (response.warnings && response.warnings.length) {
			window.alert(response.warnings.join('\n\n'));
		}

		window.location = '?display=oryk_provisioner&tab=settings';
	}).fail(function () {
		failed('The server could not be reached.');
	});
});
