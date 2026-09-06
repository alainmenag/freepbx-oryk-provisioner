<?php
/**
 * Device add/edit form. Loaded into the shared modal over AJAX and submitted
 * back the same way - nothing here posts to a page.
 *
 * @var \Oryk\Provisioner\Admin\View $view
 * @var \Oryk\Provisioner\Model\Device $device
 * @var \Oryk\Provisioner\Model\Template[] $templates
 * @var \Oryk\Provisioner\Model\Template|null $selected
 * @var array $extensions
 * @var array $schema
 * @var array $urls
 */

use Oryk\Provisioner\Admin\View;

$isNew = $device->id === 0;
?>
<form class="form-horizontal oryk-form" id="oryk-device-form" data-id="<?php echo View::esc($device->id); ?>">
	<input type="hidden" data-oryk-field="id" value="<?php echo View::esc($device->id); ?>">
	<input type="hidden" data-oryk-field="token" value="<?php echo View::esc($device->token); ?>">

	<ul class="nav nav-pills oryk-form-nav">
		<li class="active"><a href="#oryk-device-general" data-toggle="tab"><?php echo View::esc(View::t('General')); ?></a></li>
		<li><a href="#oryk-device-params" data-toggle="tab"><?php echo View::esc(View::t('Parameters')); ?></a></li>
		<?php if (!$isNew) { ?>
			<li><a href="#oryk-device-provisioning" data-toggle="tab"><?php echo View::esc(View::t('Provisioning')); ?></a></li>
		<?php } ?>
	</ul>

	<div class="tab-content oryk-form-body">

		<div class="tab-pane active" id="oryk-device-general">

			<div class="form-group">
				<label class="col-sm-4 control-label" for="oryk-device-name">
					<?php echo View::esc(View::t('Name')); ?> <span class="text-danger">*</span>
				</label>
				<div class="col-sm-8">
					<input type="text" class="form-control" id="oryk-device-name" data-oryk-field="name"
						value="<?php echo View::esc($device->name); ?>"
						placeholder="<?php echo View::esc(View::t('Alain Softphone')); ?>" required>
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label" for="oryk-device-identifier">
					<?php echo View::esc(View::t('Identifier')); ?>
				</label>
				<div class="col-sm-8">
					<input type="text" class="form-control" id="oryk-device-identifier" data-oryk-field="identifier"
						value="<?php echo View::esc($device->identifier); ?>"
						placeholder="<?php echo View::esc(View::t('generated from the name')); ?>">
					<span class="help-block"><?php echo View::esc(View::t('Stable key for this device. Lowercase letters, numbers and dashes.')); ?></span>
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label" for="oryk-device-template">
					<?php echo View::esc(View::t('Template')); ?> <span class="text-danger">*</span>
				</label>
				<div class="col-sm-8">
					<select class="form-control" id="oryk-device-template" data-oryk-field="templateId" required>
						<?php if (empty($templates)) { ?>
							<option value=""><?php echo View::esc(View::t('No templates available')); ?></option>
						<?php } ?>
						<?php foreach ($templates as $template) {
							$isSelected = $selected !== null && $selected->id === $template->id; ?>
							<option value="<?php echo View::esc($template->id); ?>"<?php echo View::flag($isSelected); ?>>
								<?php echo View::esc($template->name); ?> (<?php echo View::esc($template->slug); ?>)
							</option>
						<?php } ?>
					</select>
					<span class="help-block"><?php echo View::esc(View::t('Changing the template reloads the parameter list below.')); ?></span>
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label" for="oryk-device-extension">
					<?php echo View::esc(View::t('Extension')); ?>
				</label>
				<div class="col-sm-8">
					<input type="text" class="form-control" id="oryk-device-extension" data-oryk-field="extension"
						list="oryk-extension-list" value="<?php echo View::esc($device->extension); ?>"
						placeholder="1001">
					<datalist id="oryk-extension-list">
						<?php foreach ($extensions as $extension) { ?>
							<option value="<?php echo View::esc($extension['extension']); ?>">
								<?php echo View::esc($extension['extension'] . ' - ' . $extension['name']); ?>
							</option>
						<?php } ?>
					</datalist>
					<span class="help-block">
						<?php echo View::esc(View::t('SIP credentials, display name and email are pulled from this extension.')); ?>
					</span>
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label" for="oryk-device-mac"><?php echo View::esc(View::t('MAC Address')); ?></label>
				<div class="col-sm-8">
					<input type="text" class="form-control" id="oryk-device-mac" data-oryk-field="mac"
						value="<?php echo View::esc($device->mac); ?>" placeholder="001565AABBCC">
					<span class="help-block">
						<?php echo View::esc(View::t('Used for filenames such as {{device.mac}}.cfg. It is not a credential.')); ?>
					</span>
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label" for="oryk-device-vendor"><?php echo View::esc(View::t('Vendor / Model')); ?></label>
				<div class="col-sm-4">
					<input type="text" class="form-control" id="oryk-device-vendor" data-oryk-field="vendor"
						value="<?php echo View::esc($device->vendor); ?>" placeholder="yealink">
				</div>
				<div class="col-sm-4">
					<input type="text" class="form-control" data-oryk-field="model"
						value="<?php echo View::esc($device->model); ?>" placeholder="T54W">
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Enabled')); ?></label>
				<div class="col-sm-8">
					<label class="oryk-switch">
						<input type="checkbox" data-oryk-field="enabled" value="1"
							<?php echo View::flag($device->enabled || $isNew, 'checked'); ?>>
						<span><?php echo View::esc(View::t('This device may fetch its configuration')); ?></span>
					</label>
					<span class="help-block">
						<?php echo View::esc(View::t('A disabled device is refused immediately, even with a valid token.')); ?>
					</span>
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label" for="oryk-device-notes"><?php echo View::esc(View::t('Notes')); ?></label>
				<div class="col-sm-8">
					<textarea class="form-control" id="oryk-device-notes" rows="2" data-oryk-field="notes"><?php
						echo View::esc($device->notes); ?></textarea>
				</div>
			</div>
		</div>

		<div class="tab-pane" id="oryk-device-params">
			<div id="oryk-parameters-host">
				<?php echo $view->render('devices/parameters', array(
					'schema'     => $schema,
					'parameters' => $device->parameters,
					'template'   => $selected,
				)); ?>
			</div>
		</div>

		<?php if (!$isNew) { ?>
			<div class="tab-pane" id="oryk-device-provisioning">
				<div class="oryk-provisioning-panel">
					<?php echo $view->render('partials/urls', array('urls' => $urls)); ?>

					<div class="oryk-token-actions">
						<button type="button" class="btn btn-warning btn-sm" id="oryk-device-regenerate"
							data-id="<?php echo View::esc($device->id); ?>">
							<i class="fa fa-refresh"></i> <?php echo View::esc(View::t('Regenerate Token')); ?>
						</button>
						<span class="help-block">
							<?php echo View::esc(View::t('Regenerating invalidates the current provisioning URLs immediately.')); ?>
						</span>
					</div>

					<?php if ($device->lastProvisionedAt !== null) { ?>
						<p class="text-muted">
							<?php echo View::esc(View::t('Last provisioned')); ?>:
							<?php echo View::esc($device->lastProvisionedAt); ?>
							<?php echo View::esc($device->lastProvisionedIp === null ? '' : 'from ' . $device->lastProvisionedIp); ?>
						</p>
					<?php } ?>
				</div>
			</div>
		<?php } ?>
	</div>
</form>
