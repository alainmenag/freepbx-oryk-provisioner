<?php
/**
 * Provisioning URLs for one device, one block per generated file.
 * Shared by the device form and the preview.
 *
 * @var \Oryk\Provisioner\Admin\View $view
 * @var array $urls  [ ['filename' => ..., 'urls' => ['direct' => ..., ...]], ... ]
 */

use Oryk\Provisioner\Admin\View;

$labels = array(
	'direct'   => View::t('Direct'),
	'config'   => View::t('FreePBX'),
	'friendly' => View::t('Friendly'),
);
?>
<?php if (empty($urls)) { ?>
	<p class="text-muted"><?php echo View::esc(View::t('No files are generated yet - assign a template with at least one output.')); ?></p>
<?php } else { ?>
	<div class="oryk-urls">
		<?php foreach ($urls as $entry) { ?>
			<div class="oryk-url-block">
				<div class="oryk-url-file">
					<i class="fa fa-file-o"></i>
					<strong><?php echo View::esc($entry['filename']); ?></strong>
					<span class="text-muted"><?php echo View::esc($entry['contentType']); ?></span>
				</div>
				<?php foreach ($entry['urls'] as $type => $url) { ?>
					<div class="input-group input-group-sm oryk-url-row">
						<span class="input-group-addon"><?php echo View::esc(isset($labels[$type]) ? $labels[$type] : $type); ?></span>
						<input type="text" class="form-control oryk-url-input" readonly value="<?php echo View::esc($url); ?>">
						<span class="input-group-btn">
							<button type="button" class="btn btn-default oryk-copy" data-copy="<?php echo View::esc($url); ?>"
								title="<?php echo View::esc(View::t('Copy to clipboard')); ?>">
								<i class="fa fa-clipboard"></i>
							</button>
						</span>
					</div>
				<?php } ?>
			</div>
		<?php } ?>
	</div>
<?php } ?>
