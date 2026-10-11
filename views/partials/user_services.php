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
 * shows what it means before it is saved. A row is its box, the withhold
 * toggle, one message -- how it is held, what else it gives, what is staged,
 * its job -- then a pack's count: a service included
 * through a pack can be withheld from this user -- it and everything under
 * it, as Services::held() reads a withheld row -- and a withheld one given
 * back; both staged like a tick. A service held only because another
 * requires it says which, with no toggle -- Services::held() would give it
 * straight back.
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
	'withheldFrom' => _('withheld from %s'),
	'withheldWith' => _('withheld with %s'),
	'withholdIt' => _('Withhold from this user'),
	'restoreIt' => _('Give back to this user'),
	'toWithhold' => _('to withhold'),
	'toRestore' => _('to restore'),
	'withhold' => _('Withhold'),
	'restore' => _('Restore'),
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
	'required' => _('required'),
	'alsoGives' => _('also gives %s'),
	'requiredBy' => _('required by %s'),
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
			<div class="oryk-box oryk-mb-3 oryk-service-list">
				<div class="oryk-flex oryk-between oryk-pad oryk-service-list-title"><span><?php echo _('Service Packs'); ?></span><span id="oryk_user_packs_count"></span></div>
				<div id="oryk_user_packs"></div>
			</div>
		</div>
		<div>
			<div class="oryk-box oryk-mb-3 oryk-service-list">
				<div class="oryk-flex oryk-between oryk-pad oryk-service-list-title"><span><?php echo _('Services'); ?></span><span id="oryk_user_singles_count"></span></div>
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
		let savedWithheld = new Set();
		let stagedWithheld = new Set();
		let jobs = <?php echo json_encode((object) $serviceJobs, JSON_HEX_TAG); ?>;
		let poll = null;

		// Which packs are open, and how: slug -> 'flat' | 'tree'.
		const open = {};

		const say = function (text, ...values) {
			return values.reduce((said, value, at) => said.replace('%' + (at + 1) + '$s', () => value).replace('%s', () => value), text);
		};

		// Rows as Services::userServices() answers them; what is assigned and
		// withheld is both what is saved and, until a box changes, what is staged.
		function load(list) {
			rows = list || [];
			bySlug = {};
			rows.forEach((service) => { bySlug[service.slug] = service; });
			saved = new Set(rows.filter((service) => service.assigned).map((service) => service.slug));
			staged = new Set(saved);
			savedWithheld = new Set(rows.filter((service) => service.withheld).map((service) => service.slug));
			stagedWithheld = new Set(savedWithheld);
		}

		const name = (slug) => (bySlug[slug] ? bySlug[slug].name : slug);
		const byName = (a, b) => name(a).localeCompare(name(b), undefined, { sensitivity: 'base' });
		const kids = (slug) => (bySlug[slug] ? bySlug[slug].children.filter((kid) => bySlug[kid]) : []).sort(byName);
		const needs = (slug) => (bySlug[slug] ? (bySlug[slug].requires || []).filter((one) => bySlug[one]) : []).sort(byName);

		// Everything a service gives, at any depth: what is under it and, unless
		// only the pack tree is asked for, what it requires. A loop is walked once.
		function under(slug, into, packsOnly) {
			into = into || new Set();

			kids(slug).concat(packsOnly ? [] : needs(slug)).forEach(function (kid) {
				if (!into.has(kid)) {
					into.add(kid);
					under(kid, into, packsOnly);
				}
			});

			return into;
		}

		// Everything some services reach: themselves and all they give.
		function reached(from, packsOnly) {
			const all = new Set(from);

			from.forEach((slug) => under(slug, all, packsOnly));

			return all;
		}

		// What the staged boxes amount to, as Services::held() reads them: a
		// withhold takes a service and everything under it in the pack tree,
		// never one ticked itself, and never one that something held requires.
		function held(assigned, withheld) {
			const all = reached(assigned);

			reached(withheld, true).forEach(function (slug) {
				if (!assigned.has(slug)) {
					all.delete(slug);
				}
			});

			[...all].forEach(function (slug) {
				needs(slug).forEach(function (one) {
					if (!all.has(one)) {
						all.add(one);
						under(one, all);
					}
				});
			});

			return all;
		}

		// The staged services that require one, at any remove, by name.
		function requiredBy(slug, has) {
			return [...has].filter((other) => other !== slug && (bySlug[other].requires || []).length && reached(needs(other)).has(slug)).sort(byName).map(name);
		}

		// The staged withholds that still mean something: of services a
		// staged pack includes. One left outside every pack is dropped with it.
		function withholds() {
			const through = reached(staged);

			return new Set([...stagedWithheld].filter((slug) => through.has(slug) && !staged.has(slug)));
		}

		// The staged services one is somewhere under, by name.
		function via(slug) {
			return [...staged].filter((over) => over !== slug && under(over).has(slug)).sort(byName).map(name);
		}

		// The staged withholds one is under, by name.
		function takenWith(slug) {
			return [...withholds()].filter((over) => over !== slug && under(over).has(slug)).sort(byName).map(name);
		}

		function changes() {
			const slugs = rows.map((service) => service.slug);
			const withheld = withholds();

			return {
				assign: slugs.filter((slug) => staged.has(slug) && !saved.has(slug)),
				unassign: slugs.filter((slug) => !staged.has(slug) && saved.has(slug)),
				withhold: slugs.filter((slug) => withheld.has(slug) && !savedWithheld.has(slug)),
				restore: slugs.filter((slug) => !withheld.has(slug) && savedWithheld.has(slug) && reached(staged).has(slug) && !staged.has(slug))
			};
		}

		function changeCount() {
			const change = changes();

			return change.assign.length + change.unassign.length + change.withhold.length + change.restore.length;
		}

		function given(slug, has, nested) {
			const list = nested ? kids(slug) : [...under(slug)].sort(byName);

			const required = nested ? needs(slug) : [];

			return '<ul class="oryk-tree">' + list.concat(required).map((kid) => [
				'<li>',
				orykEscape(name(kid)),
				bySlug[kid].pack ? ` <span class="text-muted">(${orykEscape(L.pack)})</span>` : '',
				required.includes(kid) ? ` <span class="text-muted">(${orykEscape(L.required)})</span>` : '',
				has.has(kid) ? ` <span class="oryk-small text-success">${orykIcon('check')} ${orykEscape(L.has)}</span>` : '',
				nested && bySlug[kid].pack ? given(kid, has, true) : '',
				'</li>'
			].join('')).join('') + '</ul>';
		}

		function row(service, has, withheld) {
			const slug = service.slug;
			const on = staged.has(slug);
			const was = saved.has(slug);
			const through = via(slug);
			const taken = withheld.has(slug);
			const wasTaken = savedWithheld.has(slug);
			const takenBy = !on && !taken ? takenWith(slug) : [];
			const needed = !on && !taken ? requiredBy(slug, has) : [];
			const gives = needs(slug).length ? [...reached(needs(slug))].sort(byName).map(name) : [];
			const mode = open[slug];
			const tree = mode === 'tree';
			const stagedTag = on !== was ? (on ? L.toAssign : L.toUnassign) : (taken !== wasTaken ? (taken ? L.toWithhold : L.toRestore) : '');

			// How the row is held, if not by its own tick: one part of its message.
			const held = needed.length
				? ['text-muted', say(L.requiredBy, needed.join(', '))]
				: taken
				? ['text-danger', say(L.withheldFrom, through.join(', '))]
				: takenBy.length
				? ['text-danger', say(L.withheldWith, takenBy.join(', '))]
				: through.length
				? ['text-muted', say(L.included, through.join(', '))]
				: null;

			// Withhold or give back, right after the name. None while it is
			// assigned, withheld with something above it, or required by another.
			const toggle = taken
				? `<button type="button" class="oryk-bare oryk-dim" name="user_service_restore" value="${orykEscape(slug)}" title="${orykEscape(L.restoreIt)}" aria-label="${orykEscape(L.restoreIt)}">${orykIcon('check')}</button>`
				: (through.length && !on && !takenBy.length && !needed.length
					? `<button type="button" class="oryk-bare oryk-dim" name="user_service_withhold" value="${orykEscape(slug)}" title="${orykEscape(L.withholdIt)}" aria-label="${orykEscape(L.withholdIt)}">${orykIcon('ban')}</button>`
					: '');

			return [
				`<div class="oryk-service-row${stagedTag ? ' oryk-service-staged' : ''}">`,
				'<div class="oryk-flex oryk-center oryk-gap-3 oryk-service-head">',
				`<label class="oryk-check oryk-nowrap${taken || takenBy.length ? ' oryk-struck' : ''}"><input type="checkbox" name="user_service" value="${orykEscape(slug)}"${on ? ' checked' : ''}${!on && through.length && has.has(slug) ? ' data-held="1"' : ''}> ${orykEscape(service.name)}</label>`,
				toggle,
				'<span class="oryk-small oryk-grow oryk-service-note">',
				held ? `<span class="${held[0]}">${orykEscape(held[1])}</span>` : '',
				gives.length ? `<span class="text-muted">${orykEscape(say(L.alsoGives, gives.join(', ')))}</span>` : '',
				stagedTag ? `<span class="${stagedTag === L.toAssign || stagedTag === L.toRestore ? 'text-success' : 'text-danger'}">${orykEscape(stagedTag)}</span>` : '',
				`<span class="oryk-service-job oryk-small" data-slug="${orykEscape(slug)}"></span>`,
				'</span>',
				service.pack
					? `<button type="button" class="btn btn-default btn-xs${mode ? ' active' : ''}" name="user_service_open" value="${orykEscape(slug)}" title="${orykEscape(L.gives)}" aria-label="${orykEscape(L.gives)}" aria-expanded="${mode ? 'true' : 'false'}">
							<span class="oryk-small text-muted">${under(slug).size}</span>
						</button>`
					: '',
				'</div>',
				service.pack && mode
					? `<div class="oryk-service-gives"><button type="button" class="oryk-service-tree oryk-bare oryk-block text-muted" name="user_service_tree" value="${orykEscape(slug)}" title="${orykEscape(tree ? L.asFlat : L.asTree)}">${orykIcon(tree ? 'minus' : 'plus')} ${orykEscape(tree ? L.built : L.flat)}</button>${given(slug, has, tree)}</div>`
					: '',
				'</div>'
			].join('');
		}

		function draw() {
			const withheld = withholds();
			const has = held(staged, withheld);

			[['#oryk_user_packs', true], ['#oryk_user_singles', false]].forEach(function (list) {
				const mine = rows.filter((service) => Boolean(service.pack) === list[1]);

				$(list[0]).html(mine.length ? mine.map((service) => row(service, has, withheld)).join('') : `<p class="oryk-pad oryk-m-0 text-muted">${orykEscape(L.none)}</p>`);
				$(list[0] + '_count').text(say(L.of, mine.filter((service) => staged.has(service.slug)).length, mine.length));
			});

			// Half-filled: held through a pack, not assigned itself.
			$('#oryk_user_services [data-held]').prop('indeterminate', true);

			const count = changeCount();

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
					$(this).empty().attr('class', 'oryk-service-job oryk-small');
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
					.attr('class', 'oryk-service-job oryk-small ' + (status.state === 'failed' ? 'text-danger' : 'text-muted'));

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

		// A tick is an assignment, which replaces a withhold; an untick puts a
		// saved withhold back as it was.
		$(document).on('change', '[name="user_service"]', function () {
			if (this.checked) {
				staged.add(this.value);
				stagedWithheld.delete(this.value);
			} else {
				staged.delete(this.value);

				if (savedWithheld.has(this.value)) {
					stagedWithheld.add(this.value);
				}
			}

			draw();
		});

		$(document).on('click', '[name="user_service_withhold"]', function () {
			stagedWithheld.add(this.value);
			draw();
		});

		$(document).on('click', '[name="user_service_restore"]', function () {
			stagedWithheld.delete(this.value);
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
			stagedWithheld = new Set(savedWithheld);
			draw();
		});

		// Asked first, with what the server says the staged boxes would change;
		// the same request is then the save.
		$(document).on('click', '#orykservicessave', function (event) {
			event.preventDefault();

			const change = changes();

			if (!changeCount()) {
				return;
			}

			const button = $(this).prop('disabled', true);
			const request = { extension: user, assign: change.assign.join(','), unassign: change.unassign.join(','), withhold: change.withhold.join(','), restore: change.restore.join(',') };
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
						{ title: L.withhold, items: named(impact.withhold) },
						{ title: L.restore, items: named(impact.restore) },
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
			if (changeCount()) {
				return L.unsaved;
			}
		});

		load(<?php echo json_encode(array_values($services), JSON_HEX_TAG); ?>);
		$(draw);
	})();

</script>
