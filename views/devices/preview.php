<?php
/**
 * Configuration preview: the resolved parameters and every rendered file,
 * produced by the same engine that answers a real provisioning request.
 *
 * @var \Oryk\Provisioner\Admin\View $view
 * @var \Oryk\Provisioner\Model\Device $device
 * @var array $rows        Resolved parameter rows
 * @var \Oryk\Provisioner\Provisioning\RenderedFile[] $files
 * @var array $urls
 * @var array $errors
 * @var array $validation  parameter => error
 * @var bool  $reveal
 */

use Oryk\Provisioner\Admin\View;

$missing = array();

foreach ($files as $file) {
	foreach ($file->missing as $name) {
		$missing[$name] = true;
	}
}
?>
<div class="oryk-preview" data-id="<?php echo View::esc($device->id); ?>">

	<div class="oryk-preview-head">
		<div>
			<strong><?php echo View::esc($device->name); ?></strong>
			<span class="text-muted"><?php echo View::esc($device->identifier); ?></span>
		</div>
		<div class="text-muted">
			<?php echo View::esc(View::t('Template')); ?>:
			<?php echo View::esc($device->template === null ? View::t('none') : $device->template->name); ?>
			&middot;
			<?php echo View::esc(View::t('Extension')); ?>:
			<?php echo View::esc($device->extension === '' ? View::t('none') : $device->extension); ?>
			&middot;
			<?php echo $device->enabled
				? '<span class="label label-success">' . View::esc(View::t('Enabled')) . '</span>'
				: '<span class="label label-default">' . View::esc(View::t('Disabled')) . '</span>'; ?>
		</div>
	</div>

	<?php foreach ($errors as $error) { ?>
		<div class="alert alert-danger"><?php echo View::esc($error); ?></div>
	<?php } ?>

	<?php if (!empty($validation)) { ?>
		<div class="alert alert-warning">
			<strong><?php echo View::esc(View::t('Schema validation')); ?></strong>
			<ul class="oryk-issue-list">
				<?php foreach ($validation as $parameter => $message) { ?>
					<li><code><?php echo View::esc($parameter); ?></code> - <?php echo View::esc($message); ?></li>
				<?php } ?>
			</ul>
		</div>
	<?php } ?>

	<?php if (!empty($missing)) { ?>
		<div class="alert alert-info">
			<strong><?php echo View::esc(View::t('Referenced but unresolved')); ?>:</strong>
			<?php echo View::esc(implode(', ', array_keys($missing))); ?>
			<div class="small"><?php echo View::esc(View::t('These render as an empty value.')); ?></div>
		</div>
	<?php } ?>

	<ul class="nav nav-tabs oryk-preview-tabs">
		<li class="active">
			<a href="#oryk-preview-params" data-toggle="tab"><?php echo View::esc(View::t('Resolved Parameters')); ?></a>
		</li>
		<?php foreach ($files as $index => $file) { ?>
			<li>
				<a href="#oryk-preview-file-<?php echo View::esc($index); ?>" data-toggle="tab">
					<?php echo View::esc($file->filename); ?>
				</a>
			</li>
		<?php } ?>
		<li><a href="#oryk-preview-urls" data-toggle="tab"><?php echo View::esc(View::t('URLs')); ?></a></li>
	</ul>

	<div class="tab-content oryk-preview-body">

		<div class="tab-pane active" id="oryk-preview-params">
			<div class="oryk-preview-toolbar">
				<label class="oryk-switch">
					<input type="checkbox" id="oryk-preview-reveal" data-id="<?php echo View::esc($device->id); ?>"
						<?php echo View::flag($reveal, 'checked'); ?>>
					<span><?php echo View::esc(View::t('Show secret values')); ?></span>
				</label>
			</div>
			<table class="table table-condensed oryk-param-table">
				<thead>
					<tr>
						<th><?php echo View::esc(View::t('Parameter')); ?></th>
						<th><?php echo View::esc(View::t('Value')); ?></th>
						<th><?php echo View::esc(View::t('Source')); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($rows as $row) { ?>
						<tr>
							<td><code><?php echo View::esc($row['parameter']); ?></code></td>
							<td class="oryk-param-value">
								<?php echo View::esc($row['value']); ?>
								<?php if ($row['secret']) { ?>
									<i class="fa fa-lock text-muted"></i>
								<?php } ?>
							</td>
							<td>
								<span class="label oryk-source oryk-source-<?php echo View::esc($row['source']); ?>">
									<?php echo View::esc($row['sourceLabel']); ?>
								</span>
							</td>
						</tr>
					<?php } ?>
				</tbody>
			</table>
		</div>

		<?php foreach ($files as $index => $file) { ?>
			<div class="tab-pane" id="oryk-preview-file-<?php echo View::esc($index); ?>">
				<div class="oryk-preview-toolbar">
					<span class="text-muted">
						<?php echo View::esc($file->contentType); ?> &middot;
						<?php echo View::esc($file->size()); ?> <?php echo View::esc(View::t('bytes')); ?> &middot;
						<?php echo View::esc(View::t('from')); ?> <code><?php echo View::esc($file->filenameTemplate); ?></code>
					</span>
					<span class="oryk-preview-actions">
						<button type="button" class="btn btn-default btn-xs oryk-copy"
							data-copy-target="#oryk-preview-content-<?php echo View::esc($index); ?>">
							<i class="fa fa-clipboard"></i> <?php echo View::esc(View::t('Copy')); ?>
						</button>
						<a class="btn btn-default btn-xs" target="_blank"
							href="ajax.php?module=oryk_provisioner&amp;command=downloadFile&amp;id=<?php
								echo View::esc($device->id); ?>&amp;filename=<?php echo View::esc(rawurlencode($file->filename)); ?>">
							<i class="fa fa-download"></i> <?php echo View::esc(View::t('Download')); ?>
						</a>
					</span>
				</div>
				<pre class="oryk-code" id="oryk-preview-content-<?php echo View::esc($index); ?>"><?php
					echo View::esc($file->content); ?></pre>
			</div>
		<?php } ?>

		<div class="tab-pane" id="oryk-preview-urls">
			<?php echo $view->render('partials/urls', array('urls' => $urls)); ?>
		</div>
	</div>
</div>
