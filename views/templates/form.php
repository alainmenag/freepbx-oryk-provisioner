<?php
/**
 * Template editor: general details, defaults, parameter schema and the output
 * files the template produces.
 *
 * @var \Oryk\Provisioner\Admin\View $view
 * @var \Oryk\Provisioner\Model\Template $template
 * @var array $contentTypes
 * @var array $schemaRows
 */

use Oryk\Provisioner\Admin\View;

$isNew = $template->id === 0;
$commonParameters = array(
	'sip.username', 'sip.auth_username', 'sip.password', 'sip.domain', 'sip.port',
	'sip.transport', 'sip.secure', 'sip.voicemail_number',
	'user.extension', 'user.display_name', 'user.email',
	'device.id', 'device.identifier', 'device.name', 'device.mac', 'device.mac_lower',
	'device.mac_colon', 'device.vendor', 'device.model',
	'server.address', 'server.port', 'server.protocol',
	'template.slug', 'template.vendor',
	'provisioning.root_url', 'system.date', 'system.timezone',
);
?>
<form class="form-horizontal oryk-form" id="oryk-template-form" data-id="<?php echo View::esc($template->id); ?>">
	<input type="hidden" data-oryk-field="id" value="<?php echo View::esc($template->id); ?>">
	<input type="hidden" data-oryk-field="builtin" value="<?php echo View::esc($template->builtin ? 1 : 0); ?>">

	<ul class="nav nav-pills oryk-form-nav">
		<li class="active"><a href="#oryk-template-general" data-toggle="tab"><?php echo View::esc(View::t('General')); ?></a></li>
		<li><a href="#oryk-template-outputs" data-toggle="tab"><?php echo View::esc(View::t('Outputs')); ?></a></li>
		<li><a href="#oryk-template-defaults" data-toggle="tab"><?php echo View::esc(View::t('Defaults')); ?></a></li>
		<li><a href="#oryk-template-schema" data-toggle="tab"><?php echo View::esc(View::t('Parameter Schema')); ?></a></li>
		<li><a href="#oryk-template-help" data-toggle="tab"><?php echo View::esc(View::t('Variables')); ?></a></li>
	</ul>

	<div class="tab-content oryk-form-body">

		<!-- General ------------------------------------------------------- -->
		<div class="tab-pane active" id="oryk-template-general">
			<?php if ($template->builtin) { ?>
				<div class="alert alert-info">
					<?php echo View::esc(View::t('This template ships with the module. Your changes are kept unless you restore the bundled templates.')); ?>
				</div>
			<?php } ?>

			<div class="form-group">
				<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Name')); ?> <span class="text-danger">*</span></label>
				<div class="col-sm-8">
					<input type="text" class="form-control" data-oryk-field="name"
						value="<?php echo View::esc($template->name); ?>" placeholder="Yealink T54W" required>
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Slug')); ?></label>
				<div class="col-sm-8">
					<input type="text" class="form-control" data-oryk-field="slug"
						value="<?php echo View::esc($template->slug); ?>"
						placeholder="<?php echo View::esc(View::t('generated from the name')); ?>">
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Vendor / Family')); ?></label>
				<div class="col-sm-4">
					<input type="text" class="form-control" data-oryk-field="vendor"
						value="<?php echo View::esc($template->vendor); ?>" placeholder="yealink">
				</div>
				<div class="col-sm-4">
					<input type="text" class="form-control" data-oryk-field="family"
						value="<?php echo View::esc($template->family); ?>" placeholder="T5">
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Description')); ?></label>
				<div class="col-sm-8">
					<textarea class="form-control" rows="2" data-oryk-field="description"><?php
						echo View::esc($template->description); ?></textarea>
				</div>
			</div>

			<div class="form-group">
				<label class="col-sm-4 control-label"><?php echo View::esc(View::t('Enabled')); ?></label>
				<div class="col-sm-8">
					<label class="oryk-switch">
						<input type="checkbox" data-oryk-field="enabled" value="1"
							<?php echo View::flag($template->enabled || $isNew, 'checked'); ?>>
						<span><?php echo View::esc(View::t('Available when assigning devices')); ?></span>
					</label>
				</div>
			</div>
		</div>

		<!-- Outputs ------------------------------------------------------- -->
		<div class="tab-pane" id="oryk-template-outputs">
			<p class="text-muted">
				<?php echo View::esc(View::t('Each output is one generated file. Filenames are templates too, so vendor naming rules stay in the template.')); ?>
			</p>

			<div class="oryk-outputs" id="oryk-outputs">
				<?php foreach ($template->outputs as $index => $output) { ?>
					<div class="oryk-output-row panel panel-default">
						<div class="panel-heading">
							<span class="oryk-output-title"><?php echo View::esc($output->filename); ?></span>
							<button type="button" class="btn btn-default btn-xs oryk-repeater-remove pull-right">
								<i class="fa fa-times"></i> <?php echo View::esc(View::t('Remove')); ?>
							</button>
						</div>
						<div class="panel-body">
							<input type="hidden" class="oryk-output-id" value="<?php echo View::esc($output->id); ?>">
							<div class="row">
								<div class="col-sm-7">
									<label><?php echo View::esc(View::t('Filename')); ?></label>
									<input type="text" class="form-control oryk-output-filename"
										value="<?php echo View::esc($output->filename); ?>"
										placeholder="{{device.mac}}.cfg">
								</div>
								<div class="col-sm-5">
									<label><?php echo View::esc(View::t('Content type')); ?></label>
									<select class="form-control oryk-output-contenttype">
										<?php foreach ($contentTypes as $value => $label) { ?>
											<option value="<?php echo View::esc($value); ?>"<?php
												echo View::flag($output->contentType === $value); ?>>
												<?php echo View::esc($label); ?>
											</option>
										<?php } ?>
									</select>
								</div>
							</div>
							<div class="row">
								<div class="col-sm-12">
									<label><?php echo View::esc(View::t('Template body')); ?></label>
									<textarea class="form-control oryk-output-body oryk-code-editor" rows="14"
										spellcheck="false"><?php echo View::esc($output->body); ?></textarea>
								</div>
							</div>
						</div>
					</div>
				<?php } ?>
			</div>

			<button type="button" class="btn btn-default btn-sm oryk-repeater-add" data-repeater="#oryk-outputs"
				data-row="oryk-output-row-template">
				<i class="fa fa-plus"></i> <?php echo View::esc(View::t('Add output file')); ?>
			</button>

			<script type="text/html" id="oryk-output-row-template">
				<div class="oryk-output-row panel panel-default">
					<div class="panel-heading">
						<span class="oryk-output-title"><?php echo View::esc(View::t('New output')); ?></span>
						<button type="button" class="btn btn-default btn-xs oryk-repeater-remove pull-right">
							<i class="fa fa-times"></i> <?php echo View::esc(View::t('Remove')); ?>
						</button>
					</div>
					<div class="panel-body">
						<input type="hidden" class="oryk-output-id" value="0">
						<div class="row">
							<div class="col-sm-7">
								<label><?php echo View::esc(View::t('Filename')); ?></label>
								<input type="text" class="form-control oryk-output-filename" placeholder="config.json">
							</div>
							<div class="col-sm-5">
								<label><?php echo View::esc(View::t('Content type')); ?></label>
								<select class="form-control oryk-output-contenttype">
									<?php foreach ($contentTypes as $value => $label) { ?>
										<option value="<?php echo View::esc($value); ?>"><?php echo View::esc($label); ?></option>
									<?php } ?>
								</select>
							</div>
						</div>
						<div class="row">
							<div class="col-sm-12">
								<label><?php echo View::esc(View::t('Template body')); ?></label>
								<textarea class="form-control oryk-output-body oryk-code-editor" rows="14" spellcheck="false"></textarea>
							</div>
						</div>
					</div>
				</div>
			</script>
		</div>

		<!-- Defaults ------------------------------------------------------ -->
		<div class="tab-pane" id="oryk-template-defaults">
			<p class="text-muted">
				<?php echo View::esc(View::t('Template defaults sit below FreePBX data and device overrides in the resolution order.')); ?>
			</p>

			<div class="oryk-repeater" id="oryk-defaults">
				<?php foreach ($template->defaults as $key => $value) { ?>
					<div class="oryk-repeater-row oryk-default-row">
						<input type="text" class="form-control oryk-default-key" value="<?php echo View::esc($key); ?>"
							placeholder="sip.transport">
						<input type="text" class="form-control oryk-default-value" value="<?php echo View::esc($value); ?>"
							placeholder="udp">
						<button type="button" class="btn btn-default oryk-repeater-remove"><i class="fa fa-times"></i></button>
					</div>
				<?php } ?>
			</div>

			<button type="button" class="btn btn-default btn-sm oryk-repeater-add" data-repeater="#oryk-defaults"
				data-row="oryk-default-row-template">
				<i class="fa fa-plus"></i> <?php echo View::esc(View::t('Add default')); ?>
			</button>

			<script type="text/html" id="oryk-default-row-template">
				<div class="oryk-repeater-row oryk-default-row">
					<input type="text" class="form-control oryk-default-key" placeholder="sip.transport">
					<input type="text" class="form-control oryk-default-value" placeholder="udp">
					<button type="button" class="btn btn-default oryk-repeater-remove"><i class="fa fa-times"></i></button>
				</div>
			</script>
		</div>

		<!-- Parameter schema ---------------------------------------------- -->
		<div class="tab-pane" id="oryk-template-schema">
			<p class="text-muted">
				<?php echo View::esc(View::t('Declared parameters build the device form automatically and are validated before a preview is rendered.')); ?>
			</p>

			<div class="table-responsive">
				<table class="table table-condensed oryk-schema-table">
					<thead>
						<tr>
							<th><?php echo View::esc(View::t('Parameter')); ?></th>
							<th><?php echo View::esc(View::t('Type')); ?></th>
							<th><?php echo View::esc(View::t('Default')); ?></th>
							<th><?php echo View::esc(View::t('Allowed')); ?></th>
							<th><?php echo View::esc(View::t('Req')); ?></th>
							<th><?php echo View::esc(View::t('Secret')); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="oryk-schema-rows">
						<?php foreach ($schemaRows as $row) { ?>
							<tr class="oryk-schema-row">
								<td><input type="text" class="form-control input-sm oryk-schema-name"
									value="<?php echo View::esc($row['name']); ?>" placeholder="sip.transport"></td>
								<td>
									<select class="form-control input-sm oryk-schema-type">
										<?php foreach (\Oryk\Provisioner\Model\ParameterDefinition::types() as $value => $label) { ?>
											<option value="<?php echo View::esc($value); ?>"<?php
												echo View::flag($row['type'] === $value); ?>><?php echo View::esc($label); ?></option>
										<?php } ?>
									</select>
								</td>
								<td><input type="text" class="form-control input-sm oryk-schema-default"
									value="<?php echo View::esc($row['default']); ?>"></td>
								<td><input type="text" class="form-control input-sm oryk-schema-allowed"
									value="<?php echo View::esc($row['allowed']); ?>" placeholder="udp, tcp, tls"></td>
								<td class="text-center"><input type="checkbox" class="oryk-schema-required"
									<?php echo View::flag(!empty($row['required']), 'checked'); ?>></td>
								<td class="text-center"><input type="checkbox" class="oryk-schema-secret"
									<?php echo View::flag(!empty($row['secret']), 'checked'); ?>></td>
								<td><button type="button" class="btn btn-default btn-xs oryk-repeater-remove">
									<i class="fa fa-times"></i></button></td>
							</tr>
						<?php } ?>
					</tbody>
				</table>
			</div>

			<button type="button" class="btn btn-default btn-sm oryk-repeater-add" data-repeater="#oryk-schema-rows"
				data-row="oryk-schema-row-template">
				<i class="fa fa-plus"></i> <?php echo View::esc(View::t('Add parameter')); ?>
			</button>

			<script type="text/html" id="oryk-schema-row-template">
				<tr class="oryk-schema-row">
					<td><input type="text" class="form-control input-sm oryk-schema-name" placeholder="sip.transport"></td>
					<td>
						<select class="form-control input-sm oryk-schema-type">
							<?php foreach (\Oryk\Provisioner\Model\ParameterDefinition::types() as $value => $label) { ?>
								<option value="<?php echo View::esc($value); ?>"><?php echo View::esc($label); ?></option>
							<?php } ?>
						</select>
					</td>
					<td><input type="text" class="form-control input-sm oryk-schema-default"></td>
					<td><input type="text" class="form-control input-sm oryk-schema-allowed" placeholder="udp, tcp, tls"></td>
					<td class="text-center"><input type="checkbox" class="oryk-schema-required"></td>
					<td class="text-center"><input type="checkbox" class="oryk-schema-secret"></td>
					<td><button type="button" class="btn btn-default btn-xs oryk-repeater-remove">
						<i class="fa fa-times"></i></button></td>
				</tr>
			</script>
		</div>

		<!-- Variable reference -------------------------------------------- -->
		<div class="tab-pane" id="oryk-template-help">
			<div class="row">
				<div class="col-sm-6">
					<h5><?php echo View::esc(View::t('Common parameters')); ?></h5>
					<ul class="oryk-var-list">
						<?php foreach ($commonParameters as $parameter) { ?>
							<li><code>{{<?php echo View::esc($parameter); ?>}}</code></li>
						<?php } ?>
					</ul>
				</div>
				<div class="col-sm-6">
					<h5><?php echo View::esc(View::t('Syntax')); ?></h5>
					<ul class="oryk-var-list">
						<li><code>{{ sip.username }}</code> - <?php echo View::esc(View::t('escaped for the content type')); ?></li>
						<li><code>{{{ sip.username }}}</code> - <?php echo View::esc(View::t('raw, never escaped')); ?></li>
						<li><code>{{ sip.port | default:5060 }}</code> - <?php echo View::esc(View::t('filters')); ?></li>
						<li><code>{{#if sip.secure}} ... {{else}} ... {{/if}}</code></li>
						<li><code>{{#unless device.mac}} ... {{/unless}}</code></li>
						<li><code>{{#each directory}}{{name}} {{extension}}{{/each}}</code></li>
						<li><code>{{! a comment }}</code></li>
					</ul>

					<h5><?php echo View::esc(View::t('Filters')); ?></h5>
					<p class="oryk-filter-list">
						<?php foreach (\Oryk\Provisioner\Template\Filters::names() as $filter) { ?>
							<code><?php echo View::esc($filter); ?></code>
						<?php } ?>
					</p>
				</div>
			</div>
		</div>
	</div>
</form>
