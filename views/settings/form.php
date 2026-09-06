<?php
/**
 * Module settings.
 *
 * @var \Oryk\Provisioner\Admin\View $view
 * @var array $settings
 * @var array $friendly
 * @var string $baseUrl
 */

use Oryk\Provisioner\Admin\View;
?>
<form class="form-horizontal oryk-form" id="oryk-settings-form">

	<fieldset>
		<legend><?php echo View::esc(View::t('Provisioning server')); ?></legend>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Server address')); ?></label>
			<div class="col-sm-8">
				<input type="text" class="form-control" data-oryk-field="server_address"
					value="<?php echo View::esc($settings['server_address']); ?>"
					placeholder="<?php echo View::esc(View::t('detected from the request')); ?>">
				<span class="help-block">
					<?php echo View::esc(View::t('Hostname or IP devices use to reach this PBX. Current base URL:')); ?>
					<code><?php echo View::esc($baseUrl); ?></code>
				</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Web protocol / port')); ?></label>
			<div class="col-sm-4">
				<select class="form-control" data-oryk-field="server_protocol">
					<?php foreach (array('auto' => View::t('Match the request'), 'https' => 'https', 'http' => 'http') as $value => $label) { ?>
						<option value="<?php echo View::esc($value); ?>"<?php
							echo View::flag($settings['server_protocol'] === $value); ?>><?php echo View::esc($label); ?></option>
					<?php } ?>
				</select>
			</div>
			<div class="col-sm-4">
				<input type="text" class="form-control" data-oryk-field="server_port"
					value="<?php echo View::esc($settings['server_port']); ?>"
					placeholder="<?php echo View::esc(View::t('default port')); ?>">
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('SIP domain')); ?></label>
			<div class="col-sm-8">
				<input type="text" class="form-control" data-oryk-field="sip_domain"
					value="<?php echo View::esc($settings['sip_domain']); ?>"
					placeholder="<?php echo View::esc(View::t('falls back to the server address')); ?>">
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Default SIP port / transport')); ?></label>
			<div class="col-sm-4">
				<input type="text" class="form-control" data-oryk-field="sip_port"
					value="<?php echo View::esc($settings['sip_port']); ?>" placeholder="5060">
			</div>
			<div class="col-sm-4">
				<select class="form-control" data-oryk-field="sip_transport">
					<?php foreach (array('udp', 'tcp', 'tls') as $transport) { ?>
						<option value="<?php echo View::esc($transport); ?>"<?php
							echo View::flag($settings['sip_transport'] === $transport); ?>><?php echo View::esc($transport); ?></option>
					<?php } ?>
				</select>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><?php echo View::esc(View::t('Endpoints and security')); ?></legend>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('FreePBX provisioning URL')); ?></label>
			<div class="col-sm-8">
				<label class="oryk-switch">
					<input type="checkbox" data-oryk-field="allow_config_route" value="1"
						<?php echo View::flag($settings['allow_config_route'], 'checked'); ?>>
					<span><code>config.php?display=oryk_provisioner&amp;token=...&amp;filename=...</code></span>
				</label>
				<span class="help-block">
					<?php echo View::esc(View::t('Turn this off to serve devices only from the module endpoint below.')); ?>
					<br><code><?php echo View::esc($baseUrl); ?>/admin/modules/oryk_provisioner/provision.php?token=...&amp;filename=...</code>
				</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Friendly URL')); ?></label>
			<div class="col-sm-8">
				<p class="form-control-static">
					<code><?php echo View::esc($baseUrl); ?>/provisioner/{token}/{filename}</code>
				</p>
				<p class="form-control-static">
					<?php if (!empty($friendly['installed'])) { ?>
						<span class="label label-success"><?php echo View::esc(View::t('Installed')); ?></span>
						<button type="button" class="btn btn-default btn-sm" id="oryk-friendly-remove">
							<?php echo View::esc(View::t('Remove')); ?>
						</button>
					<?php } else { ?>
						<span class="label label-default"><?php echo View::esc(View::t('Not installed')); ?></span>
						<button type="button" class="btn btn-default btn-sm" id="oryk-friendly-install">
							<?php echo View::esc(View::t('Install')); ?>
						</button>
					<?php } ?>
				</p>
				<span class="help-block">
					<?php echo View::esc(View::t('Writes a small shim to')); ?>
					<code><?php echo View::esc($friendly['path']); ?></code>.
					<?php echo View::esc(View::t('It reaches the same provisioning engine and needs mod_rewrite with AllowOverride.')); ?>
				</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Require HTTPS')); ?></label>
			<div class="col-sm-8">
				<label class="oryk-switch">
					<input type="checkbox" data-oryk-field="require_https" value="1"
						<?php echo View::flag($settings['require_https'], 'checked'); ?>>
					<span><?php echo View::esc(View::t('Refuse provisioning requests that arrive over plain HTTP')); ?></span>
				</label>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Allowed networks')); ?></label>
			<div class="col-sm-8">
				<input type="text" class="form-control" data-oryk-field="allowed_networks"
					value="<?php echo View::esc($settings['allowed_networks']); ?>"
					placeholder="192.168.1.0/24, 10.0.0.0/8">
				<span class="help-block">
					<?php echo View::esc(View::t('Leave empty to allow any source. Requests from elsewhere get the same 404 as an invalid token.')); ?>
				</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Token length')); ?></label>
			<div class="col-sm-8">
				<input type="number" class="form-control" data-oryk-field="token_length" min="16" max="128"
					value="<?php echo View::esc($settings['token_length']); ?>">
				<span class="help-block">
					<?php echo View::esc(View::t('Applies to newly generated tokens. Existing tokens are unchanged.')); ?>
				</span>
			</div>
		</div>
	</fieldset>

	<fieldset>
		<legend><?php echo View::esc(View::t('Rendering and logging')); ?></legend>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Strict rendering')); ?></label>
			<div class="col-sm-8">
				<label class="oryk-switch">
					<input type="checkbox" data-oryk-field="strict_rendering" value="1"
						<?php echo View::flag($settings['strict_rendering'], 'checked'); ?>>
					<span><?php echo View::esc(View::t('Fail rendering when a template references an unknown parameter')); ?></span>
				</label>
				<span class="help-block">
					<?php echo View::esc(View::t('Off by default: unknown parameters render as an empty value.')); ?>
				</span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Extension directory')); ?></label>
			<div class="col-sm-8">
				<label class="oryk-switch">
					<input type="checkbox" data-oryk-field="directory_enabled" value="1"
						<?php echo View::flag($settings['directory_enabled'], 'checked'); ?>>
					<span><?php echo View::esc(View::t('Expose the extension list to templates as {{#each directory}}')); ?></span>
				</label>
				<input type="number" class="form-control oryk-inline-number" data-oryk-field="directory_limit" min="1"
					max="5000" value="<?php echo View::esc($settings['directory_limit']); ?>">
				<span class="help-block"><?php echo View::esc(View::t('Maximum directory entries per device.')); ?></span>
			</div>
		</div>

		<div class="form-group">
			<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Provisioning log')); ?></label>
			<div class="col-sm-8">
				<label class="oryk-switch">
					<input type="checkbox" data-oryk-field="log_enabled" value="1"
						<?php echo View::flag($settings['log_enabled'], 'checked'); ?>>
					<span><?php echo View::esc(View::t('Record provisioning requests (metadata only, never configuration)')); ?></span>
				</label>
				<input type="number" class="form-control oryk-inline-number" data-oryk-field="log_retention_days" min="0"
					max="3650" value="<?php echo View::esc($settings['log_retention_days']); ?>">
				<span class="help-block"><?php echo View::esc(View::t('Days to keep log entries. 0 keeps everything.')); ?></span>
			</div>
		</div>
	</fieldset>

	<div class="form-group">
		<div class="col-sm-offset-4 col-sm-8">
			<button type="button" class="btn btn-primary" id="oryk-settings-save">
				<i class="fa fa-save"></i> <?php echo View::esc(View::t('Save Settings')); ?>
			</button>
		</div>
	</div>
</form>
