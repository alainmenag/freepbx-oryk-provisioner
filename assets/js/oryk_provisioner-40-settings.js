/**
 * Oryk Provisioner - settings tab.
 */
/* global jQuery, OrykProvisioner */
(function ($, App) {
	'use strict';

	var Settings = {};

	Settings.save = function () {
		var payload = App.serializeForm($('#oryk-settings-form'));

		App.api('saveSettings', { payload: JSON.stringify(payload) }).done(function (response) {
			App.toast(response.message, 'success');
		}).fail(function (error) {
			App.fail(error);
			App.showFieldErrors(error.errors);
		});
	};

	Settings.installFriendlyUrl = function () {
		App.api('installFriendlyUrl').done(function (response) {
			App.toast(response.message, 'success');
			window.location.reload();
		}).fail(App.fail);
	};

	Settings.removeFriendlyUrl = function () {
		App.confirmAction('Remove the friendly URL shim from the web root?', function () {
			App.api('removeFriendlyUrl').done(function (response) {
				App.toast(response.message, 'success');
				window.location.reload();
			}).fail(App.fail);
		});
	};

	Settings.bind = function () {
		var $root = $(document);

		$root.on('click', '#oryk-settings-save', function () {
			Settings.save();
		});

		$root.on('click', '#oryk-friendly-install', function () {
			Settings.installFriendlyUrl();
		});

		$root.on('click', '#oryk-friendly-remove', function () {
			Settings.removeFriendlyUrl();
		});
	};

	App.settings = Settings;
})(jQuery, OrykProvisioner);
