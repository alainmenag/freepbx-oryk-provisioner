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
 * @var array<int, array<string, mixed>> $settings Settings::fields()
 */

$settings = isset($settings) && is_array($settings) ? $settings : [];

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
					<i class="fa fa-question-circle fpbx-help-icon" data-for="<?php echo $h($id); ?>"></i>
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

<?php echo $script('settings'); ?>
