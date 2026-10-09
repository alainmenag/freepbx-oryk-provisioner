<?php
/**
 * views/partials/settings.php -- the Settings tab: every Settings::fields()
 * entry, drawn by its type, saved by the action bar's Save.
 *
 * Nothing here names a setting. A field is drawn from its definition and
 * posted back under its keyword, so a setting added to Settings::definitions()
 * appears here with nothing written in this file. See ARCHITECTURE.md,
 * "Settings".
 *
 * Save stays on this tab: the page reloads with what was written. Help is
 * FreePBX's (?) icon, the same as every editor -- see partials/editor.php.
 *
 * Under the settings is the one thing here that is not one: the button that
 * brings dismissed notices back -- see ARCHITECTURE.md, "Notices".
 *
 * @var array<int, array<string, mixed>> $settings         Settings::fields()
 * @var int                              $dismissedNotices Notices::dismissedCount()
 */

$settings = isset($settings) && is_array($settings) ? $settings : [];
$dismissedNotices = (int) ($dismissedNotices ?? 0);

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
?>

<?php foreach ($settings as $setting): ?>
	<?php
	$keyword = (string) $setting['keyword'];
	$id = 'oryk_setting_' . strtolower($keyword);
	$value = $setting['value'];
	?>
	<div class="element-container">
		<div class="row">
			<div class="form-group">
				<div class="col-md-4">
					<label class="control-label" for="<?php echo $h($id); ?>"><?php echo $h(_($setting['name'])); ?></label>
					<i class="fpbx-help-icon" data-for="<?php echo $h($id); ?>"><?php echo $icon('help'); ?></i>
				</div>
				<div class="col-md-8">
					<?php if ($setting['type'] === 'bool'): ?>
						<span class="radioset" id="<?php echo $h($id); ?>" data-oryk-setting="<?php echo $h($keyword); ?>">
							<input type="radio" name="<?php echo $h($id); ?>" id="<?php echo $h($id); ?>_yes" value="1"<?php echo $value ? ' checked' : ''; ?>>
							<label for="<?php echo $h($id); ?>_yes"><?php echo _('Yes'); ?></label>
							<input type="radio" name="<?php echo $h($id); ?>" id="<?php echo $h($id); ?>_no" value="0"<?php echo $value ? '' : ' checked'; ?>>
							<label for="<?php echo $h($id); ?>_no"><?php echo _('No'); ?></label>
						</span>
					<?php elseif ($setting['type'] === 'select'): ?>
						<select class="form-control" id="<?php echo $h($id); ?>" data-oryk-setting="<?php echo $h($keyword); ?>">
							<?php foreach ($setting['options'] as $optionValue => $optionLabel): ?>
								<option value="<?php echo $h($optionValue); ?>"<?php echo (string) $optionValue === (string) $value ? ' selected' : ''; ?>><?php echo $h(_($optionLabel)); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else: ?>
						<input type="<?php echo $setting['type'] === 'int' ? 'number' : 'text'; ?>" class="form-control" id="<?php echo $h($id); ?>"
							data-oryk-setting="<?php echo $h($keyword); ?>" autocomplete="off" spellcheck="false"
							<?php if ($setting['min'] !== null): ?>min="<?php echo (int) $setting['min']; ?>"<?php endif; ?>
							<?php if ($setting['max'] !== null): ?>max="<?php echo (int) $setting['max']; ?>"<?php endif; ?>
							placeholder="<?php echo $h($setting['placeholder'] !== '' ? $setting['placeholder'] : _('none')); ?>"
							value="<?php echo $h($value); ?>">
					<?php endif; ?>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-md-12">
				<span class="help-block fpbx-help-block" id="<?php echo $h($id); ?>-help">
					<?php echo $h(_($setting['description'])); ?>
					<span class="oryk-setting-keyword"><?php echo $h($keyword); ?></span>
				</span>
			</div>
		</div>
	</div>
<?php endforeach; ?>

<div class="element-container">
	<div class="row">
		<div class="form-group">
			<div class="col-md-4">
				<label class="control-label" for="oryk_notices_reset"><?php echo _('Notices'); ?></label>
				<i class="fpbx-help-icon" data-for="oryk_notices_reset"><?php echo $icon('help'); ?></i>
			</div>
			<div class="col-md-8">
				<button type="button" class="btn btn-default" id="oryk_notices_reset"<?php echo $dismissedNotices ? '' : ' disabled'; ?>>
					<?php echo _('Show Dismissed Notices Again'); ?>
				</button>
			</div>
		</div>
	</div>
	<div class="row">
		<div class="col-md-12">
			<span class="help-block fpbx-help-block" id="oryk_notices_reset-help">
				<?php echo $h(_('Brings back every notice that was dismissed and still applies. Nothing to bring back when none has been dismissed.')); ?>
			</span>
		</div>
	</div>
</div>

<script>

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

	$(document).on('click', '#oryk_notices_reset', function () {
		const button = $(this).prop('disabled', true);

		orykPost('resetNotices', {}).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				return;
			}

			window.location = '?display=oryk_provisioner&tab=settings';
		}).fail(function () {
			button.prop('disabled', false);
		});
	});

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

</script>
