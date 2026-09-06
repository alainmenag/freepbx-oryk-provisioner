<?php
/**
 * views/profile.php -- one profile.
 *
 * Reached at ?display=oryk_provisioner&profile=<id> to edit an existing
 * profile, or ?display=oryk_provisioner&profile= (present, empty) to write a
 * new one. `profile` present but empty is deliberate rather than a degenerate
 * case: it is the same page doing the same thing, minus a row to replace.
 *
 * A template is a block of configuration text that wants room and a monospace
 * column, which a dialog inside the list page never had. Save, Delete and
 * Close are the action bar's, drawn by FreePBX from getActionBar() and bound
 * at the bottom of this file.
 *
 * Nothing here lists devices or profiles -- that is views/admin.php, which
 * Close and a finished Save both return to.
 *
 * @var array<string, mixed>            $profile  id (0 when new), name, template
 * @var array<int, array<string, mixed>> $assigned Device associations using it
 */

$profile = $profile ?? ['id' => 0, 'name' => '', 'template' => ''];
$assigned = $assigned ?? [];

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$isNew = ((int) $profile['id']) === 0;
?>
<style>
	.oryk-template {
		font-family: monospace;
		white-space: pre;
	}
	.oryk-assigned-macs {
		padding-top: 4px;
	}
	.oryk-assigned-device {
		display: inline-block;
		margin-right: 10px;
		white-space: nowrap;
	}
</style>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<div class="section-title">
				<h2>
					<span class="title">
						<?php if ($isNew): ?>
							<?php echo _('New Profile'); ?>
						<?php else: ?>
							<?php echo _('Edit Profile'); ?>
							<code><?php echo $h($profile['name']); ?></code>
						<?php endif; ?>
					</span>
				</h2>
			</div>

			<div class="section">

				<div class="alert alert-danger hidden" id="profile_error"></div>

				<?php if (!empty($assigned)): ?>
					<div class="alert alert-info oryk-assigned">
						<strong>
							<?php
							echo sprintf(
								_('Assigned to %s device(s).'),
								count($assigned)
							);
							?>
						</strong>
						<?php echo _('Whatever is saved here is what they provision with.'); ?>
						<div class="oryk-assigned-macs">
							<?php foreach ($assigned as $device): ?>
								<span class="oryk-assigned-device">
									<code><?php echo $h($device['mac']); ?></code>
									<?php if (($device['extension'] ?? '') !== ''): ?>
										<a href="?display=extensions&amp;extdisplay=<?php echo rawurlencode((string) $device['extension']); ?>">(<?php echo $h($device['extension']); ?>)</a>
									<?php endif; ?>
								</span>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endif; ?>

				<!-- Not a form: the module page is itself inside a FreePBX form,
				     and a nested one is dropped by the browser, which leaves the
				     fields submitting the page instead. The values are read by
				     id and posted to ajax.php. -->
				<input type="hidden" id="profile_row_id" value="<?php echo (int) $profile['id']; ?>">

				<div class="element-container">
					<div class="row">
						<div class="form-group">
							<div class="col-md-4">
								<label class="control-label" for="profile_name">
									<?php echo _('Name'); ?>
									<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
								</label>
							</div>
							<div class="col-md-8">
								<input type="text" class="form-control" id="profile_name"
									autocomplete="off" placeholder="<?php echo _('Yealink T54W'); ?>"
									value="<?php echo $h($profile['name']); ?>">
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12">
							<span class="help-block fpbx-help-block">
								<?php echo _('How the profile is named in the device list. Must be unique.'); ?>
							</span>
						</div>
					</div>
				</div>

				<div class="element-container">
					<div class="row">
						<div class="form-group">
							<div class="col-md-4">
								<label class="control-label" for="profile_template"><?php echo _('Template'); ?></label>
							</div>
							<div class="col-md-8">
								<textarea class="form-control oryk-template" id="profile_template"
									rows="24" spellcheck="false" wrap="off">
<?php echo $h($profile['template']); ?></textarea>
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12">
							<span class="help-block fpbx-help-block">
								<?php echo _('Configuration text, stored as typed.'); ?>
							</span>
						</div>
					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<script>

	const orykList = '?display=oryk_provisioner&tab=profiles';

	function orykPost(command, data) {
		return $.ajax({
			url: 'ajax.php?module=oryk_provisioner&command=' + command,
			type: 'POST',
			data: data,
			dataType: 'json'
		});
	}

	function orykShowError(message) {
		$('#profile_error')
			.text(message || 'Something went wrong.')
			.removeClass('hidden');

		$('html, body').animate({ scrollTop: 0 }, 150);
	}

	// The action bar's buttons are ours rather than the submit/delete names
	// core wires to a `form.fpbx-submit`: this page has no form, and a save
	// here is an AJAX post, not a page submit.
	$(document).on('click', '#oryksave', function (event) {
		event.preventDefault();

		orykPost('saveProfile', {
			id: $('#profile_row_id').val(),
			name: $('#profile_name').val(),
			template: $('#profile_template').val()
		}).done(function (response) {
			if (!response || !response.status) {
				orykShowError(response && response.message);
				return;
			}

			// The list is re-rendered on arrival, so the saved profile is in
			// its table and in the device dialog's dropdown without anything
			// here having to put it there.
			window.location = orykList + '&saved=' + encodeURIComponent(response.id);
		}).fail(function () {
			orykShowError('The server could not be reached.');
		});
	});

	$(document).on('click', '#orykdelete', function (event) {
		event.preventDefault();

		if (!window.confirm('Delete this profile?')) {
			return;
		}

		orykPost('deleteProfile', { id: $('#profile_row_id').val() }).done(function (response) {
			if (!response || !response.status) {
				orykShowError(response && response.message);
				return;
			}

			window.location = orykList;
		}).fail(function () {
			orykShowError('The server could not be reached.');
		});
	});

	$(document).on('click', '#orykclose', function (event) {
		event.preventDefault();
		window.location = orykList;
	});

</script>
