<?php
/**
 * views/service.php -- one service, or a new one.
 *
 * Reached at ?display=oryk_provisioner&service=<slug>, or &service= (present,
 * empty) for a new one. A name, and its links both ways: the services
 * it is under and the services under it, each a list of every other service
 * to tick. One that would close a loop is drawn disabled, and says why. The
 * slug is in the address and on no field.
 *
 * One of the module's own (Services::DEFAULTS) is drawn with every field
 * disabled, and Pages::getActionBar() gives it Close and nothing else.
 *
 * @var array<string, mixed>             $service   name, slug ('' when new), managed
 * @var array<int, array<string, mixed>> $choices   Services::serviceChoices(): every service, each marked managed
 * @var array<string, array<int, string>> $related  Services::related(): the slugs ticked, and those that cannot be
 * @var array<int, array<string, mixed>> $navigator Levels the navigator draws -- see partials/navigator.php
 * @var array<int, array<string, mixed>> $sections  Navigator::sections() -- see partials/sections.php
 * @var string                           $version   Module version -- see partials/sections.php
 */

$service = ($service ?? []) + ['name' => '', 'slug' => ''];
$choices = isset($choices) && is_array($choices) ? $choices : [];
$related = (isset($related) && is_array($related) ? $related : [])
	+ ['parents' => [], 'children' => [], 'barredParents' => [], 'barredChildren' => []];

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$slug = (string) $service['slug'];
$managed = !empty($service['managed']);
$locked = $managed ? ' disabled' : '';

$tab = 'service';
$tabs = [
	'service' => [
		'label' => _('Service'),
		'href' => '?display=oryk_provisioner&service=' . rawurlencode($slug),
	],
];

// One side of the links: every other service as a checkbox named $name,
// its value the service's slug.
$checks = function ($name, array $ticked, array $barred, $why) use ($choices, $slug, $h, $managed) {
	$html = '';

	foreach ($choices as $choice) {
		$other = (string) ($choice['slug'] ?? '');

		if ($other === $slug) {
			continue;
		}

		$said = '';

		if ($managed) {
			$said = _('Managed by the module.');
		} elseif (in_array($other, $barred, true)) {
			$said = $why;
		}

		$off = $said !== '';
		$html .= '<label class="oryk-check' . ($off ? ' disabled' : '') . '"' . ($off ? ' title="' . $h($said) . '"' : '') . '>'
			. '<input type="checkbox" name="' . $h($name) . '" value="' . $h($other) . '"'
			. (in_array($other, $ticked, true) ? ' checked' : '') . ($off ? ' disabled' : '') . '> '
			. $h($choice['name']) . '</label>';
	}

	return $html !== ''
		? '<div class="oryk-checks">' . $html . '</div>'
		: '<p class="form-control-static text-muted">' . $h(_('There are no other services yet.')) . '</p>';
};
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="provisioner container-fluid">
	<div class="fpbx-container">
		<div class="display no-border">

			<?php include __DIR__ . '/partials/sections.php'; ?>
			<?php include __DIR__ . '/partials/navigator.php'; ?>

			<div class="section no-border" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<?php include __DIR__ . '/partials/tabs.php'; ?>

				<div class="tab-content">
					<div class="tab-pane oryk-tab-section active" id="oryk_service">

						<?php if ($managed): ?>
						<div class="alert alert-info">
							<?php echo _('This service is managed by the module, so it cannot be edited or deleted here. A service of your own can still be put under it or over it, on that service\'s page.'); ?>
						</div>
						<?php endif; ?>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="service_name">
											<?php echo _('Name'); ?>
											<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
										</label> <i class="fpbx-help-icon" data-for="service_name"><?php echo $icon('help'); ?></i>
									</div>
									<div class="col-md-8">
										<input type="text" class="form-control" id="service_name"
											autocomplete="off" maxlength="191"<?php echo $locked; ?>
											value="<?php echo $h($service['name']); ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="service_name-help">
										<?php echo _('What the service is called. Must be unique.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="service_parents"><?php echo _('Parents'); ?></label> <i class="fpbx-help-icon" data-for="service_parents"><?php echo $icon('help'); ?></i>
									</div>
									<div class="col-md-8" id="service_parents">
										<?php echo $checks('service_parent', $related['parents'], $related['barredParents'], _('Already under this service, so it cannot also be over it.')); ?>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="service_parents-help">
										<?php echo _('The services this one is under. Tick as many as it belongs to, or none.'); ?>
									</span>
								</div>
							</div>
						</div>

						<div class="element-container">
							<div class="row">
								<div class="form-group">
									<div class="col-md-4">
										<label class="control-label" for="service_children"><?php echo _('Services'); ?></label> <i class="fpbx-help-icon" data-for="service_children"><?php echo $icon('help'); ?></i>
									</div>
									<div class="col-md-8" id="service_children">
										<?php echo $checks('service_child', $related['children'], $related['barredChildren'], _('Already over this service, so it cannot also be under it.')); ?>
									</div>
								</div>
							</div>
							<div class="row">
								<div class="col-md-12">
									<span class="help-block fpbx-help-block" id="service_children-help">
										<?php echo _('The services under this one. A service can be under any number of others: ticking it here does not take it from anywhere else.'); ?>
									</span>
								</div>
							</div>
						</div>

					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<script>

	// The service as stored, '' for a new one: what a save and a delete name it by.
	let orykServiceSlug = <?php echo json_encode($slug); ?>;

	// One side's ticked slugs, as one string: jQuery posts nothing at all for
	// an empty array, and nothing is "leave the links alone" to saveService.
	function orykServiceTicked(name) {
		return $('[name="' + name + '"]:checked').map(function () {
			return this.value;
		}).get().join(',');
	}

	// A save or delete that changes what users hold says how, and asks first.
	orykEditor({
		save: 'saveService',
		ask: function (values) {
			const asked = $.Deferred();

			orykServiceImpact($.extend({ action: 'save' }, values)).done(function (message) {
				orykAsk(message ? message + ' Save?' : '', { title: 'Save service', choices: [{ label: 'Save', value: true, style: 'btn-primary' }] })
					.done(() => asked.resolve())
					.fail(() => asked.reject());
			});

			return asked.promise();
		},
		remove: 'deleteService',
		confirm: function () {
			return orykServiceImpact({ action: 'delete', slug: orykServiceSlug }).then(function (message) {
				return 'Delete this service? The services over and under it are kept.' + (message ? ' ' + message : '');
			});
		},
		key: 'slug',
		values: function () {
			return {
				slug: orykServiceSlug,
				name: $('#service_name').val(),
				parents: orykServiceTicked('service_parent'),
				children: orykServiceTicked('service_child')
			};
		},
		// The address is the slug, which a save makes, and changes with the
		// name: the page to land on is the one the answer names.
		saved: function (response) {
			orykServiceSlug = response.slug;
		},
		page: function () {
			return '?display=oryk_provisioner&service=' + encodeURIComponent(orykServiceSlug);
		},
		closed: '?display=oryk_provisioner&tab=services'
	});

</script>
