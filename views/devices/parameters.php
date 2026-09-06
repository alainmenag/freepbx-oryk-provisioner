<?php
/**
 * Device parameter editor, built from the template's parameter schema.
 *
 * Anything left blank falls back through the resolution order
 * (template default -> FreePBX -> ...), so only real overrides are stored.
 *
 * @var \Oryk\Provisioner\Admin\View $view
 * @var array $schema     Rows from ParameterSchema::toList()
 * @var array $parameters Current device overrides (flat dotted map)
 */

use Oryk\Provisioner\Admin\View;

$parameters = isset($parameters) && is_array($parameters) ? $parameters : array();
$schema = isset($schema) && is_array($schema) ? $schema : array();

$declared = array();

foreach ($schema as $row) {
	$declared[$row['name']] = true;
}

$extra = array();

foreach ($parameters as $key => $value) {
	if (!isset($declared[$key])) {
		$extra[$key] = $value;
	}
}
?>
<div class="oryk-parameters" id="oryk-parameters">

	<?php if (empty($schema)) { ?>
		<p class="text-muted">
			<?php echo View::esc(View::t('This template does not declare a parameter schema. Add parameters manually below.')); ?>
		</p>
	<?php } else { ?>
		<?php foreach ($schema as $row) {
			$name = $row['name'];
			$value = isset($parameters[$name]) ? $parameters[$name] : '';
			$hasValue = array_key_exists($name, $parameters) && $parameters[$name] !== '';
			$default = isset($row['default']) ? $row['default'] : '';
			$placeholder = $default === '' ? View::t('Resolved automatically') : $default;
			$allowed = isset($row['allowed']) && $row['allowed'] !== '' ? explode(',', $row['allowed']) : array();
			?>
			<div class="form-group oryk-param-row<?php echo $hasValue ? ' oryk-param-overridden' : ''; ?>">
				<label class="col-sm-4 control-label">
					<code><?php echo View::esc($name); ?></code>
					<?php if (!empty($row['required'])) { ?>
						<span class="text-danger" title="<?php echo View::esc(View::t('Required')); ?>">*</span>
					<?php } ?>
					<?php if (!empty($row['secret'])) { ?>
						<i class="fa fa-lock text-muted" title="<?php echo View::esc(View::t('Secret')); ?>"></i>
					<?php } ?>
				</label>
				<div class="col-sm-8">
					<?php if (!empty($allowed)) { ?>
						<select class="form-control oryk-param" data-param-name="<?php echo View::esc($name); ?>">
							<option value=""><?php echo View::esc(View::t('Use resolved value')); ?><?php
								echo $default === '' ? '' : ' (' . View::esc($default) . ')'; ?></option>
							<?php foreach ($allowed as $option) {
								$option = trim($option); ?>
								<option value="<?php echo View::esc($option); ?>"<?php
									echo View::flag((string) $value === $option); ?>><?php echo View::esc($option); ?></option>
							<?php } ?>
						</select>
					<?php } elseif ($row['type'] === 'boolean') { ?>
						<select class="form-control oryk-param" data-param-name="<?php echo View::esc($name); ?>">
							<option value=""><?php echo View::esc(View::t('Use resolved value')); ?></option>
							<option value="1"<?php echo View::flag((string) $value === '1'); ?>><?php echo View::esc(View::t('Yes')); ?></option>
							<option value="0"<?php echo View::flag($hasValue && (string) $value === '0'); ?>><?php echo View::esc(View::t('No')); ?></option>
						</select>
					<?php } elseif ($row['type'] === 'text') { ?>
						<textarea class="form-control oryk-param" rows="3"
							data-param-name="<?php echo View::esc($name); ?>"
							placeholder="<?php echo View::esc($placeholder); ?>"><?php echo View::esc($value); ?></textarea>
					<?php } else { ?>
						<input type="<?php echo !empty($row['secret']) ? 'password' : ($row['type'] === 'integer' ? 'number' : 'text'); ?>"
							class="form-control oryk-param"
							autocomplete="new-password"
							data-param-name="<?php echo View::esc($name); ?>"
							placeholder="<?php echo View::esc($placeholder); ?>"
							value="<?php echo View::esc($value); ?>">
					<?php } ?>
					<?php if (!empty($row['description'])) { ?>
						<span class="help-block"><?php echo View::esc($row['description']); ?></span>
					<?php } ?>
				</div>
			</div>
		<?php } ?>
	<?php } ?>

	<div class="oryk-extra-params">
		<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Additional parameters')); ?></label>
		<div class="col-sm-8">
			<div class="oryk-repeater" id="oryk-extra-params">
				<?php foreach ($extra as $key => $value) { ?>
					<div class="oryk-repeater-row oryk-extra-row">
						<input type="text" class="form-control oryk-extra-key" value="<?php echo View::esc($key); ?>"
							placeholder="<?php echo View::esc(View::t('parameter.name')); ?>">
						<input type="text" class="form-control oryk-extra-value" value="<?php echo View::esc($value); ?>"
							placeholder="<?php echo View::esc(View::t('value')); ?>">
						<button type="button" class="btn btn-default oryk-repeater-remove" title="<?php echo View::esc(View::t('Remove')); ?>">
							<i class="fa fa-times"></i>
						</button>
					</div>
				<?php } ?>
			</div>
			<button type="button" class="btn btn-default btn-sm oryk-repeater-add" data-repeater="#oryk-extra-params"
				data-row="oryk-extra-row-template">
				<i class="fa fa-plus"></i> <?php echo View::esc(View::t('Add parameter')); ?>
			</button>
			<span class="help-block">
				<?php echo View::esc(View::t('Use dotted names such as sip.outbound_proxy. Device parameters override every other source.')); ?>
			</span>
		</div>
	</div>

	<script type="text/html" id="oryk-extra-row-template">
		<div class="oryk-repeater-row oryk-extra-row">
			<input type="text" class="form-control oryk-extra-key" placeholder="parameter.name">
			<input type="text" class="form-control oryk-extra-value" placeholder="value">
			<button type="button" class="btn btn-default oryk-repeater-remove"><i class="fa fa-times"></i></button>
		</div>
	</script>
</div>
