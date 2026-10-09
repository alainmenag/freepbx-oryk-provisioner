/*
 * assets/oryk_dialog.js -- the module's one way to ask before it does something.
 *
 * Put on every page by Pages::view(). orykAsk() draws a FreePBX (Bootstrap)
 * modal in place of window.confirm(), and can offer more than one answer.
 *
 *   orykAsk('Delete this ban?').done(() => { ... });
 *
 *   orykAsk('Delete this device?', {
 *       title: 'Delete device',
 *       choices: [
 *           { label: 'Device Only', value: 'device' },
 *           { label: 'Device + Client', value: 'both' }
 *       ]
 *   }).done((answer) => { ... });
 *
 * The promise resolves with the value of the choice pressed, and is rejected
 * by Cancel, Escape or a click outside -- so `.done()` is "they said yes" and
 * nothing has to handle "no". An empty message asks nothing and resolves at
 * once, for a question that is only sometimes worth asking.
 *
 * message  Text, never markup: it is set with .text().
 * options  title    heading; "Are you sure?" when absent
 *          choices  [{ label, value, style }], drawn left to right after
 *                   Cancel; style is a Bootstrap button class and defaults to
 *                   btn-danger, since nearly every question here is a delete.
 *                   One choice, { label: 'OK', value: true }, when absent.
 *          sections [{ title, items }], each a heading over a list of
 *                   texts, drawn above the message; one with no items is
 *                   left out
 *          cancel   Cancel's label
 */
function orykAsk(message, options) {
	const asked = $.Deferred();

	if (message === undefined || message === null || message === '') {
		return asked.resolve(true).promise();
	}

	options = options || {};

	const choices = (options.choices && options.choices.length) ? options.choices : [{ label: 'OK', value: true }];
	let answered = false;

	const modal = $(
		'<div class="modal fade oryk-ask" tabindex="-1" role="dialog">' +
			'<div class="modal-dialog" role="document">' +
				'<div class="modal-content">' +
					'<div class="modal-header"><h4 class="modal-title"></h4></div>' +
					'<div class="modal-body"><p class="oryk-ask-message"></p></div>' +
					'<div class="modal-footer"></div>' +
				'</div>' +
			'</div>' +
		'</div>'
	);

	modal.find('.modal-title').text(options.title || 'Are you sure?');
	modal.find('.oryk-ask-message').text(String(message));

	(options.sections || []).forEach(function (section) {
		if (!section.items || !section.items.length) {
			return;
		}

		const list = $('<ul class="oryk-ask-list"></ul>');

		section.items.forEach(function (item) {
			list.append($('<li></li>').text(String(item)));
		});

		modal.find('.oryk-ask-message').before($('<h5 class="oryk-ask-section"></h5>').text(section.title), list);
	});

	const footer = modal.find('.modal-footer');

	footer.append($('<button type="button" class="btn btn-default oryk-ask-cancel" data-dismiss="modal"></button>').text(options.cancel || 'Cancel'));

	choices.forEach(function (choice) {
		footer.append(
			$('<button type="button" class="btn"></button>')
				.addClass(choice.style || 'btn-danger')
				.text(choice.label)
				.on('click', function () {
					answered = true;
					modal.modal('hide');
					asked.resolve(choice.value);
				})
		);
	});

	modal
		.on('shown.bs.modal', function () {
			// Enter on a question about deleting something should not delete it.
			modal.find('.oryk-ask-cancel').trigger('focus');
		})
		.on('hidden.bs.modal', function () {
			modal.remove();

			if (!answered) {
				asked.reject();
			}
		})
		.appendTo('body')
		.modal('show');

	return asked.promise();
}

/*
 * What saving or deleting a service would change for its users, as a
 * sentence to ask with before it is done: '' when it changes nobody's, or
 * when the module could not say.
 *
 * values  action (save|delete) and slug; for a save, name, parents and
 *         children as the save would post them.
 *
 * Returns a promise of the sentence; it is never rejected.
 */
function orykServiceImpact(values) {
	const said = $.Deferred();

	$.ajax({
		url: 'ajax.php?module=oryk_provisioner&command=serviceImpact',
		type: 'POST',
		data: values,
		dataType: 'json'
	}).done(function (response) {
		if (!response || !response.status || !response.users) {
			said.resolve('');
			return;
		}

		const parts = []
			.concat((response.revoked || []).map((row) => `${row.name} is revoked from ${row.users}` + (row.effect ? ` (${row.effect})` : '')))
			.concat((response.granted || []).map((row) => `${row.name} is granted to ${row.users}` + (row.effect ? ` (${row.effect})` : '')));

		said.resolve(`This changes the services of ${response.users} ${response.users === 1 ? 'user' : 'users'}: ${parts.join('; ')}. Each gets a job, run at once.`);
	}).fail(function () {
		said.resolve('');
	});

	return said.promise();
}

/*
 * What deleting a FreePBX device asks, on the Devices list and on the
 * device's page alike: orykDeviceDeleteQuestion() is the message,
 * orykDeviceDeleteChoices() the options for orykAsk(). The answer is truthy
 * when the clients on the device go with it.
 *
 * id       the device
 * own      whether it is a user's own device
 * clients  how many clients are on it
 */
function orykDeviceDeleteQuestion(id, own, clients) {
	const both = clients === 1 ? 'Device + Client' : 'Device + Clients';
	let ask = `Delete device ${id}?`;

	if (own) {
		ask += ' It is a user\'s own device: the user stays, with no device, until you save it to give it one back.';
	}

	if (clients) {
		ask += ` ${clients} client${clients === 1 ? ' uses' : 's use'} it. Device Only keeps ${clients === 1 ? 'it' : 'them'}, with no device assigned; ${both} deletes ${clients === 1 ? 'it' : 'them'} too, with ${clients === 1 ? 'its' : 'their'} logs.`;
	}

	return ask + ' This cannot be undone.';
}

function orykDeviceDeleteChoices(clients) {
	return {
		title: 'Delete device',
		choices: clients
			? [{ label: 'Device Only', value: 0 }, { label: clients === 1 ? 'Device + Client' : 'Device + Clients', value: 1 }]
			: [{ label: 'Delete', value: 0 }]
	};
}
