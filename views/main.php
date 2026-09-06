<?php
/**
 * Oryk Provisioner - admin interface shell.
 *
 * The shell is rendered server side; every table, form and preview inside it is
 * loaded and saved over AJAX (see assets/js/).
 *
 * @var \Oryk\Provisioner\Admin\View $view
 * @var array $settings
 * @var \Oryk\Provisioner\Model\Template[] $templates
 * @var array $vendors
 * @var array $friendly
 * @var string $baseUrl
 */

use Oryk\Provisioner\Admin\View;
?>
<div class="row">
	<div class="col-sm-12">
		<div class="fpbx-container">
			<div class="display full-border oryk-provisioner" id="oryk-provisioner">

				<div class="row oryk-page-head">
					<div class="col-sm-7">
						<h3 class="oryk-page-title"><?php echo View::esc(View::t('Provisioner')); ?></h3>
						<p class="oryk-page-sub">
							<?php echo View::esc(View::t('Template driven provisioning for softphones and SIP endpoints.')); ?>
						</p>
					</div>
					<div class="col-sm-5">
						<div class="oryk-summary" id="oryk-summary">
							<span class="oryk-stat" data-stat="devices">
								<strong>-</strong> <?php echo View::esc(View::t('Devices')); ?>
							</span>
							<span class="oryk-stat" data-stat="templates">
								<strong>-</strong> <?php echo View::esc(View::t('Templates')); ?>
							</span>
							<span class="oryk-stat" data-stat="success">
								<strong>-</strong> <?php echo View::esc(View::t('Served (24h)')); ?>
							</span>
						</div>
					</div>
				</div>

				<?php /* Note: never use data-target here - Bootstrap's tab plugin reads
					it instead of href, which breaks tab switching. The pane a tab loads
					its data for is named with data-oryk-tab. */ ?>
				<ul class="nav nav-tabs" role="tablist" id="oryk-tabs">
					<li role="presentation" class="active">
						<a href="#oryk-tab-devices" data-oryk-tab="devices" data-toggle="tab" role="tab">
							<i class="fa fa-phone"></i> <?php echo View::esc(View::t('Devices')); ?>
						</a>
					</li>
					<li role="presentation">
						<a href="#oryk-tab-templates" data-oryk-tab="templates" data-toggle="tab" role="tab">
							<i class="fa fa-file-code-o"></i> <?php echo View::esc(View::t('Templates')); ?>
						</a>
					</li>
					<li role="presentation">
						<a href="#oryk-tab-logs" data-oryk-tab="logs" data-toggle="tab" role="tab">
							<i class="fa fa-list"></i> <?php echo View::esc(View::t('Provisioning Log')); ?>
						</a>
					</li>
					<li role="presentation">
						<a href="#oryk-tab-settings" data-oryk-tab="settings" data-toggle="tab" role="tab">
							<i class="fa fa-sliders"></i> <?php echo View::esc(View::t('Settings')); ?>
						</a>
					</li>
				</ul>

				<div class="tab-content oryk-tab-content">

					<!-- Devices ------------------------------------------------ -->
					<div role="tabpanel" class="tab-pane active" id="oryk-tab-devices">
						<div class="oryk-toolbar">
							<button type="button" class="btn btn-primary" id="oryk-device-add">
								<i class="fa fa-plus"></i> <?php echo View::esc(View::t('Add Device')); ?>
							</button>
							<button type="button" class="btn btn-default" id="oryk-device-refresh">
								<i class="fa fa-refresh"></i> <?php echo View::esc(View::t('Refresh')); ?>
							</button>
							<div class="oryk-toolbar-right">
								<select class="form-control input-sm" id="oryk-device-template-filter">
									<option value=""><?php echo View::esc(View::t('All templates')); ?></option>
									<?php foreach ($templates as $template) { ?>
										<option value="<?php echo View::esc($template->id); ?>">
											<?php echo View::esc($template->name); ?>
										</option>
									<?php } ?>
								</select>
								<input type="search" class="form-control input-sm" id="oryk-device-search"
									placeholder="<?php echo View::esc(View::t('Search devices')); ?>">
							</div>
						</div>
						<div class="oryk-table-wrap">
							<table class="table table-striped table-hover oryk-table" id="oryk-devices-table">
								<thead>
									<tr>
										<th><?php echo View::esc(View::t('Device')); ?></th>
										<th><?php echo View::esc(View::t('Extension')); ?></th>
										<th><?php echo View::esc(View::t('Template')); ?></th>
										<th><?php echo View::esc(View::t('MAC')); ?></th>
										<th><?php echo View::esc(View::t('State')); ?></th>
										<th><?php echo View::esc(View::t('Last Provisioned')); ?></th>
										<th class="oryk-actions-col"><?php echo View::esc(View::t('Actions')); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr class="oryk-empty">
										<td colspan="7"><?php echo View::esc(View::t('Loading...')); ?></td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<!-- Templates ----------------------------------------------- -->
					<div role="tabpanel" class="tab-pane" id="oryk-tab-templates">
						<div class="oryk-toolbar">
							<button type="button" class="btn btn-primary" id="oryk-template-add">
								<i class="fa fa-plus"></i> <?php echo View::esc(View::t('Add Template')); ?>
							</button>
							<button type="button" class="btn btn-default" id="oryk-template-import">
								<i class="fa fa-upload"></i> <?php echo View::esc(View::t('Import')); ?>
							</button>
							<button type="button" class="btn btn-default" id="oryk-template-seed">
								<i class="fa fa-magic"></i> <?php echo View::esc(View::t('Restore Bundled Templates')); ?>
							</button>
							<div class="oryk-toolbar-right">
								<select class="form-control input-sm" id="oryk-template-vendor-filter">
									<option value=""><?php echo View::esc(View::t('All vendors')); ?></option>
									<?php foreach ($vendors as $vendor) { ?>
										<option value="<?php echo View::esc($vendor); ?>"><?php echo View::esc($vendor); ?></option>
									<?php } ?>
								</select>
								<input type="search" class="form-control input-sm" id="oryk-template-search"
									placeholder="<?php echo View::esc(View::t('Search templates')); ?>">
							</div>
						</div>
						<div class="oryk-table-wrap">
							<table class="table table-striped table-hover oryk-table" id="oryk-templates-table">
								<thead>
									<tr>
										<th><?php echo View::esc(View::t('Template')); ?></th>
										<th><?php echo View::esc(View::t('Slug')); ?></th>
										<th><?php echo View::esc(View::t('Vendor')); ?></th>
										<th><?php echo View::esc(View::t('Outputs')); ?></th>
										<th><?php echo View::esc(View::t('Parameters')); ?></th>
										<th><?php echo View::esc(View::t('Devices')); ?></th>
										<th class="oryk-actions-col"><?php echo View::esc(View::t('Actions')); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr class="oryk-empty">
										<td colspan="7"><?php echo View::esc(View::t('Loading...')); ?></td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<!-- Log ----------------------------------------------------- -->
					<div role="tabpanel" class="tab-pane" id="oryk-tab-logs">
						<div class="oryk-toolbar">
							<button type="button" class="btn btn-default" id="oryk-log-refresh">
								<i class="fa fa-refresh"></i> <?php echo View::esc(View::t('Refresh')); ?>
							</button>
							<button type="button" class="btn btn-danger" id="oryk-log-clear">
								<i class="fa fa-trash"></i> <?php echo View::esc(View::t('Clear Log')); ?>
							</button>
							<div class="oryk-toolbar-right">
								<select class="form-control input-sm" id="oryk-log-status">
									<option value=""><?php echo View::esc(View::t('All results')); ?></option>
									<option value="success"><?php echo View::esc(View::t('Served')); ?></option>
									<option value="notfound"><?php echo View::esc(View::t('Not found')); ?></option>
									<option value="denied"><?php echo View::esc(View::t('Denied')); ?></option>
									<option value="error"><?php echo View::esc(View::t('Error')); ?></option>
								</select>
								<input type="search" class="form-control input-sm" id="oryk-log-search"
									placeholder="<?php echo View::esc(View::t('Search log')); ?>">
							</div>
						</div>
						<div class="oryk-table-wrap">
							<table class="table table-striped oryk-table" id="oryk-logs-table">
								<thead>
									<tr>
										<th><?php echo View::esc(View::t('When')); ?></th>
										<th><?php echo View::esc(View::t('Device')); ?></th>
										<th><?php echo View::esc(View::t('File')); ?></th>
										<th><?php echo View::esc(View::t('Result')); ?></th>
										<th><?php echo View::esc(View::t('Source')); ?></th>
										<th><?php echo View::esc(View::t('User Agent')); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr class="oryk-empty">
										<td colspan="6"><?php echo View::esc(View::t('Loading...')); ?></td>
									</tr>
								</tbody>
							</table>
						</div>
					</div>

					<!-- Settings ------------------------------------------------ -->
					<div role="tabpanel" class="tab-pane" id="oryk-tab-settings">
						<?php echo $view->render('settings/form', array(
							'settings' => $settings,
							'friendly' => $friendly,
							'baseUrl'  => $baseUrl,
						)); ?>
					</div>

				</div>
			</div>
		</div>
	</div>
</div>

<?php echo $view->render('partials/modal'); ?>
