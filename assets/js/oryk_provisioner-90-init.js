/**
 * Oryk Provisioner - bootstrap.
 *
 * Loaded last (assets/js is included alphabetically), so every feature module
 * has registered itself on the OrykProvisioner namespace by now.
 */
/* global jQuery, OrykProvisioner */
(function ($, App) {
	'use strict';

	/**
	 * Refresh the counters in the page header.
	 */
	App.refreshSummary = function () {
		App.api('getSummary', {}, 'GET').done(function (response) {
			var log = response.log || {};

			setStat('devices', response.devices + (response.enabled === response.devices
				? ''
				: ' (' + response.enabled + ' on)'));
			setStat('templates', response.templates);
			setStat('success', log.success || 0);
		});
	};

	function setStat(name, value) {
		$('#oryk-summary [data-stat="' + name + '"] strong').text(value);
	}

	/**
	 * Load a tab's data the first time it is shown, then on demand.
	 */
	function activateTab(target) {
		switch (target) {
			case 'devices':
				App.devices.load();
				break;
			case 'templates':
				App.templates.load();
				break;
			case 'logs':
				App.logs.load();
				break;
			default:
				break;
		}
	}

	$(function () {
		if (!$('#oryk-provisioner').length) {
			return;
		}

		App.bindRepeaters(document);
		App.devices.bind();
		App.templates.bind();
		App.logs.bind();
		App.settings.bind();

		$('#oryk-tabs a[data-toggle="tab"]').on('shown.bs.tab', function (event) {
			activateTab($(event.target).data('target'));
		});

		// Nested tabs inside the modal (forms, preview) must not bubble up to
		// the page level tab handler.
		$(document).on('shown.bs.tab', '#oryk-modal a[data-toggle="tab"]', function (event) {
			event.stopPropagation();
		});

		activateTab('devices');
		App.templates.load();
		App.refreshSummary();
	});
})(jQuery, OrykProvisioner);
