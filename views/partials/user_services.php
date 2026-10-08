<?php
/**
 * views/partials/user_services.php -- a user's Services tab.
 *
 * Two lists, packs and single services, each service a box. **A tick is
 * staged, not saved**: the action bar's Save (`#orykservicessave`, from
 * Pages::getActionBar()) asks userServicesImpact what the staged ticks would
 * change, shows that, and only then posts them all to setUserServices as one
 * change. Reset puts the boxes back. Neither button is orykEditor()'s, which
 * would post the User tab's fields.
 *
 * A pack opens on everything it gives, flat; the line saying so, above the
 * list, is a button that draws it as the tree it is built as, and back.
 * "included in", a half-filled box (held through a pack, not assigned
 * itself) and an open pack's ticks all follow the staged boxes, so a change
 * shows what it means before it is saved.
 *
 * A change to what the user holds is a job (ARCHITECTURE.md, "Jobs"): each
 * service whose newest step is waiting, running or failed says so, kept
 * current while any is waiting or running; a failed one has Retry beside it.
 *
 * Included by views/user.php, after partials/editor.php (orykPost(),
 * orykEscape()).
 *
 * @var string                              $extension   The user
 * @var array<int, array<string, mixed>>    $services    Services::userServices()
 * @var array<string, array<string, mixed>> $serviceJobs Jobs::statusFor()
 */

$serviceLabels = [
	'included' => _('included in %s'),
	'toAssign' => _('to assign'),
	'toUnassign' => _('to unassign'),
	'of' => _('%1$s of %2$s'),
	'none' => _('None.'),
	'gives' => _('What this pack gives'),
	'flat' => _('Everything this pack gives'),
	'built' => _('How this pack is built'),
	'asTree' => _('Show as a tree'),
	'asFlat' => _('Show as a flat list'),
	'pack' => _('pack'),
	'has' => _('has it'),
	'staged' => _('Unsaved changes: %s'),
	'unsaved' => _('There are unsaved changes to this user\'s services.'),
	'title' => _('Save services for %s?'),
	'assign' => _('Assign'),
	'unassign' => _('Unassign'),
	'gains' => _('The user gains'),
	'loses' => _('The user loses'),
	'kept' => _('Unassigned but kept'),
	'keptVia' => _('%1$s (still included in %2$s)'),
	'job' => _('This is one job for the user, run at once.'),
	'noJob' => _('What the user holds does not change, so no job is run.'),
	'save' => _('Save'),
	'saved' => _('Saved.'),
	'refused' => _('Could not save.'),
	'unreachable' => _('The server could not be reached.'),
	'queued' => _('queued'),
	'running' => _('running'),
	'failed' => _('failed'),
	'retry' => _('Retry'),
];
?>
<div class="tab-pane oryk-tab-section active" id="oryk_user_services">

	<?php if (!$services): ?>
	<p class="text-muted">
		<?php echo _('There are no services yet.'); ?>
		<a href="?display=oryk_provisioner&amp;tab=services"><?php echo _('Services'); ?></a>
	</p>
	<?php else: ?>
	<div class="oryk-service-lists">
		<div>
			<div class="oryk-service-list">
				<div class="oryk-service-list-title"><span><?php echo _('Service Packs'); ?></span><span id="oryk_user_packs_count"></span></div>
				<div id="oryk_user_packs"></div>
			</div>
		</div>
		<div>
			<div class="oryk-service-list">
				<div class="oryk-service-list-title"><span><?php echo _('Services'); ?></span><span id="oryk_user_singles_count"></span></div>
				<div id="oryk_user_singles"></div>
			</div>
		</div>
	</div>

	<p><strong class="text-warning" id="oryk_services_staged"></strong></p>
	<?php endif; ?>

</div>

<script>

	(function () {
		const user = <?php echo json_encode((string) $extension); ?>;
		const L = <?php echo json_encode($serviceLabels, JSON_HEX_TAG); ?>;

		let rows = [];
		let bySlug = {};
		let saved = new Set();
		let staged = new Set();
		let jobs = <?php echo json_encode((object) $serviceJobs, JSON_HEX_TAG); ?>;
		let poll = null;

		// Which packs are open, and how: slug -> 'flat' | 'tree'.
		const open = {};

		const say = function (text, ...values) {
			return values.reduce((said, value, at) => said.replace('%' + (at + 1) + '$s', () => value).replace('%s', () => value), text);
		};

		// Rows as Services::userServices() answers them; what is assigned is
		// both what is saved and, until a box changes, what is staged.
		function load(list) {
			rows = list || [];
			bySlug = {};
			rows.forEach((service) => { bySlug[service.slug] = service; });
			saved = new Set(rows.filter((service) => service.assigned).map((service) => service.slug));
			staged = new Set(saved);
		}

		const name = (slug) => (bySlug[slug] ? bySlug[slug].name : slug);
		const byName = (a, b) => name(a).localeCompare(name(b), undefined, { sensitivity: 'base' });
		const kids = (slug) => (bySlug[slug] ? bySlug[slug].children.filter((kid) => bySlug[kid]) : []).sort(byName);

		// Everything under a service, at any depth; a loop is walked once.
		function under(slug, into) {
			into = into || new Set();

			kids(slug).forEach(function (kid) {
				if (!into.has(kid)) {
					into.add(kid);
					under(kid, into);
				}
			});

			return into;
		}

		function held(assigned) {
			const all = new Set(assigned);

			assigned.forEach((slug) => under(slug, all));

			return all;
		}

		// The staged services one is somewhere under, by name.
		function via(slug) {
			return [...staged].filter((over) => over !== slug && under(over).has(slug)).sort(byName).map(name);
		}

		function changes() {
			return {
				assign: rows.map((service) => service.slug).filter((slug) => staged.has(slug) && !saved.has(slug)),
				unassign: rows.map((service) => service.slug).filter((slug) => !staged.has(slug) && saved.has(slug))
			};
		}

		function given(slug, has, nested) {
			const list = nested ? kids(slug) : [...under(slug)].sort(byName);

			return '<ul>' + list.map((kid) => [
				'<li>',
				orykEscape(name(kid)),
				bySlug[kid].pack ? ` <span class="text-muted">(${orykEscape(L.pack)})</span>` : '',
				has.has(kid) ? ` <span class="oryk-service-has text-success">${orykIcon('check')} ${orykEscape(L.has)}</span>` : '',
				nested && bySlug[kid].pack ? given(kid, has, true) : '',
				'</li>'
			].join('')).join('') + '</ul>';
		}

		function row(service, has) {
			const slug = service.slug;
			const on = staged.has(slug);
			const was = saved.has(slug);
			const through = via(slug);
			const mode = open[slug];
			const tree = mode === 'tree';

			return [
				`<div class="oryk-service-row${on !== was ? ' oryk-service-staged' : ''}">`,
				'<div class="oryk-service-head">',
				`<label class="oryk-check"><input type="checkbox" name="user_service" value="${orykEscape(slug)}"${on ? ' checked' : ''}${!on && through.length ? ' data-held="1"' : ''}> ${orykEscape(service.name)}</label>`,
				through.length ? `<span class="oryk-service-via text-muted">${orykEscape(say(L.included, through.join(', ')))}</span>` : '',
				on !== was ? `<span class="oryk-service-change ${on ? 'text-success' : 'text-danger'}">${orykEscape(on ? L.toAssign : L.toUnassign)}</span>` : '',
				`<span class="oryk-service-job" data-slug="${orykEscape(slug)}"></span>`,
				service.pack
					? `<button type="button" class="btn btn-default btn-xs oryk-service-open${mode ? ' active' : ''}" name="user_service_open" value="${orykEscape(slug)}" title="${orykEscape(L.gives)}" aria-label="${orykEscape(L.gives)}" aria-expanded="${mode ? 'true' : 'false'}">
							<span class="oryk-service-count text-muted">${under(slug).size}</span>
						</button>`
					: '',
				'</div>',
				service.pack && mode
					? `<div class="oryk-service-gives"><button type="button" class="oryk-service-tree text-muted" name="user_service_tree" value="${orykEscape(slug)}" title="${orykEscape(tree ? L.asFlat : L.asTree)}">${orykIcon(tree ? 'minus' : 'plus')} ${orykEscape(tree ? L.built : L.flat)}</button>${given(slug, has, tree)}</div>`
					: '',
				'</div>'
			].join('');
		}

		function draw() {
			const has = held(staged);

			[['#oryk_user_packs', true], ['#oryk_user_singles', false]].forEach(function (list) {
				const mine = rows.filter((service) => Boolean(service.pack) === list[1]);

				$(list[0]).html(mine.length ? mine.map((service) => row(service, has)).join('') : `<p class="oryk-service-none text-muted">${orykEscape(L.none)}</p>`);
				$(list[0] + '_count').text(say(L.of, mine.filter((service) => staged.has(service.slug)).length, mine.length));
			});

			// Half-filled: held through a pack, not assigned itself.
			$('#oryk_user_services [data-held]').prop('indeterminate', true);

			const change = changes();
			const count = change.assign.length + change.unassign.length;

			$('#oryk_services_staged').text(count ? say(L.staged, count) : '');
			$('#orykservicessave, #orykservicesreset').prop('disabled', !count);

			showJobs();
		}

		// Where each service's newest job step stands: waiting, running, or
		// failed with a link to its job. One with nothing outstanding says nothing.
		function showJobs() {
			let busy = false;

			$('#oryk_user_services .oryk-service-job').each(function () {
				const status = (jobs || {})[$(this).attr('data-slug')];

				if (!status) {
					$(this).empty().attr('class', 'oryk-service-job');
					return;
				}

				busy = busy || status.state !== 'failed';

				const link = $('<a>')
					.attr('href', '?display=oryk_provisioner&job=' + encodeURIComponent(status.job))
					.text(L[status.state] || status.state);

				if (status.error) {
					link.attr('title', status.error);
				}

				$(this).empty().append(link)
					.attr('class', 'oryk-service-job ' + (status.state === 'failed' ? 'text-danger' : 'text-muted'));

				if (status.state === 'failed') {
					$(this).append(' ', $('<button type="button" class="btn btn-default btn-xs" name="user_service_retry"></button>')
						.val(status.job).attr({ title: L.retry, 'aria-label': L.retry }).html(orykIcon('refresh')));
				}
			});

			window.clearTimeout(poll);

			// Asked again while anything is still to run; a job takes a moment.
			if (busy) {
				poll = window.setTimeout(function () {
					orykPost('userServiceJobs', { extension: user }).done(function (response) {
						if (response && response.status) {
							jobs = response.jobs;
							showJobs();
						}
					});
				}, 1500);
			}
		}

		$(document).on('change', '[name="user_service"]', function () {
			if (this.checked) {
				staged.add(this.value);
			} else {
				staged.delete(this.value);
			}

			draw();
		});

		// The chevron opens a pack flat, or shuts it; the line over what it
		// gives turns that into the tree, and back.
		$(document).on('click', '[name="user_service_open"]', function () {
			open[this.value] = open[this.value] ? undefined : 'flat';
			draw();
		});

		$(document).on('click', '[name="user_service_tree"]', function () {
			open[this.value] = open[this.value] === 'tree' ? 'flat' : 'tree';
			draw();
		});

		// A failed job is put back in the user's queue from here, as from its own page.
		$(document).on('click', '[name="user_service_retry"]', function () {
			$('[name="user_service_retry"]').prop('disabled', true);

			orykPost('retryJob', { id: this.value }).done(function (response) {
				if (!response || !response.status) {
					notie.alert(3, (response && response.message) || L.refused, 4);
				}
			}).fail(function () {
				notie.alert(3, L.unreachable, 4);
			}).always(function () {
				orykPost('userServiceJobs', { extension: user }).done(function (response) {
					if (response && response.status) {
						jobs = response.jobs;
					}

					showJobs();
				});
			});
		});

		$(document).on('click', '#orykservicesreset', function (event) {
			event.preventDefault();
			staged = new Set(saved);
			draw();
		});

		// Asked first, with what the server says the staged boxes would change;
		// the same request is then the save.
		$(document).on('click', '#orykservicessave', function (event) {
			event.preventDefault();

			const change = changes();

			if (!change.assign.length && !change.unassign.length) {
				return;
			}

			const button = $(this).prop('disabled', true);
			const request = { extension: user, assign: change.assign.join(','), unassign: change.unassign.join(',') };
			const refused = function (message) {
				button.prop('disabled', false);
				notie.alert(3, message || L.refused, 4);
			};
			// A gain or loss the module's own job acts on says what that does.
			const named = (list) => (list || []).map((service) => service.name + (service.pack ? ` (${L.pack})` : '') + (service.effect ? ': ' + service.effect : ''));

			orykPost('userServicesImpact', request).done(function (impact) {
				if (!impact || !impact.status) {
					refused(impact && impact.message);
					return;
				}

				orykAsk(impact.job ? L.job : L.noJob, {
					title: say(L.title, user),
					sections: [
						{ title: L.assign, items: named(impact.assign) },
						{ title: L.unassign, items: named(impact.unassign) },
						{ title: L.gains, items: named(impact.granted) },
						{ title: L.loses, items: named(impact.revoked) },
						{ title: L.kept, items: (impact.kept || []).map((service) => say(L.keptVia, service.name, service.via.join(', '))) }
					],
					choices: [{ label: L.save, value: true, style: 'btn-primary' }]
				}).done(function () {
					orykPost('setUserServices', request).done(function (response) {
						if (!response || !response.status) {
							refused(response && response.message);
							return;
						}

						load(response.services);
						jobs = response.jobs;
						draw();
						notie.alert(1, L.saved, 2);
					}).fail(function () {
						refused(L.unreachable);
					});
				}).fail(function () {
					button.prop('disabled', false);
				});
			}).fail(function () {
				refused(L.unreachable);
			});
		});

		// The other tabs are links, so leaving one is leaving the page.
		$(window).on('beforeunload', function () {
			const change = changes();

			if (change.assign.length || change.unassign.length) {
				return L.unsaved;
			}
		});

		load(<?php echo json_encode(array_values($services), JSON_HEX_TAG); ?>);
		$(draw);
	})();

</script>
