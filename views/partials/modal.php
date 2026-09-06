<?php
/**
 * The single reusable modal. Forms and previews are rendered by the server and
 * injected into the body, so the markup for each screen lives in its own view.
 *
 * @var \Oryk\Provisioner\Admin\View $view
 */

use Oryk\Provisioner\Admin\View;
?>
<div class="modal fade oryk-modal" id="oryk-modal" tabindex="-1" role="dialog" aria-hidden="true">
	<div class="modal-dialog modal-lg" role="document">
		<div class="modal-content">
			<div class="modal-header">
				<button type="button" class="close" data-dismiss="modal" aria-label="Close">
					<span aria-hidden="true">&times;</span>
				</button>
				<h4 class="modal-title" id="oryk-modal-title"></h4>
			</div>
			<div class="modal-body" id="oryk-modal-body"></div>
			<div class="modal-footer" id="oryk-modal-footer">
				<span class="oryk-modal-message text-danger" id="oryk-modal-message"></span>
				<button type="button" class="btn btn-default" data-dismiss="modal">
					<?php echo View::esc(View::t('Close')); ?>
				</button>
				<button type="button" class="btn btn-primary" id="oryk-modal-save">
					<?php echo View::esc(View::t('Save')); ?>
				</button>
			</div>
		</div>
	</div>
</div>
