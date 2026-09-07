<?php
/**
 * views/resource.php -- one resource of one profile.
 *
 * Reached at ?display=oryk_provisioner&profile=<id>&resource=<id>, or with
 * `resource` present and empty to write a new one -- the same shape the
 * profile editor has, one level down.
 *
 * A resource is a profile's other files: the phone asks for [mac]-phone.cfg
 * or [mac]-directory.xml alongside its main config, and this is what it gets.
 * Which makes it a profile minus the parts a profile has because devices are
 * assigned to it, which is why the two views share partials/editor.php and
 * differ in little more than their two fields.
 *
 * @var array<string, mixed>                 $resource     id (0 when new), profile_id, name, template
 * @var array<string, mixed>                 $profile      The profile it belongs to
 * @var array<string, array<string, string>> $placeholders What a template can refer to
 */

$resource = $resource ?? ['id' => 0, 'profile_id' => 0, 'name' => '', 'template' => ''];
$profile = $profile ?? ['id' => 0, 'name' => ''];
$placeholders = $placeholders ?? [];

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$id = (int) $resource['id'];
$profileId = (int) $profile['id'];
$isNew = $id === 0;
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="container-fluid">
	<div class="fpbx-container">
		<div class="display full-border">

			<!-- <div class="section-title">
				<h2>
					<span class="title">
						<?php if ($isNew): ?>
							<?php echo _('New Resource'); ?>
						<?php else: ?>
							<?php echo _('Edit Resource'); ?>
							<code><?php echo $h($resource['name']); ?></code>
						<?php endif; ?>
					</span>
				</h2>
			</div> -->

			<div class="section-title">
				<h2>
					<span class="title">
						<a class="title" href="?display=oryk_provisioner&tab=profiles">Provisioner</a>
						<span>:: Profile</span>
						<a class="title" href="?display=oryk_provisioner&profile=<?php echo $profileId; ?>&tab=resources">
							<span><?php echo $h($profile['name']); ?></span>
						</a>
						<span>:: Resource</span>
						<?php if (isset($resource['name'])): ?>
							<code><?php echo $h($resource['name']); ?></code>
						<?php endif; ?>
					</span>
				</h2>
			</div>

			<div class="section" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<!-- Not a form: see the note in partials/editor.php. -->
				<input type="hidden" id="resource_row_id" value="<?php echo $id; ?>">
				<input type="hidden" id="resource_profile_id" value="<?php echo $profileId; ?>">

				<div class="element-container">
					<div class="row">
						<div class="form-group">
							<div class="col-md-4">
								<label class="control-label" for="resource_name">
									<?php echo _('Filename'); ?>
									<span class="text-danger" title="<?php echo _('Required'); ?>">*</span>
								</label>
							</div>
							<div class="col-md-8">
								<input type="text" class="form-control oryk-name" id="resource_name"
									autocomplete="off" placeholder="{{device.mac}}-phone.cfg"
									value="<?php echo $h($resource['name']); ?>">
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12">
							<span class="help-block fpbx-help-block">
								<?php echo _('The filename a phone asks for. It is a template like the body below, so <code>{{device.mac}}-phone.cfg</code> covers every device on this profile, and a vendor that names its files some other way can be matched exactly. A name with no placeholders in it -- <code>phone.cfg</code> -- is matched against the request with the device\'s MAC taken off the front, so either way of writing it works. Unique within this profile.'); ?>
							</span>
							<span class="help-block fpbx-help-block">
								<?php echo _('The main configuration file, <code>{{device.mac}}.cfg</code>, is the profile\'s own template -- unless a resource here claims that name, which then wins.'); ?>
							</span>
						</div>
					</div>
				</div>

				<div class="element-container">
					<div class="row">
						<div class="form-group">
							<div class="col-md-4">
								<label class="control-label" for="resource_template"><?php echo _('Template'); ?></label>
							</div>
							<div class="col-md-8">
								<textarea class="form-control oryk-template" id="resource_template"
									rows="24" spellcheck="false" wrap="off">
<?php echo $h($resource['template']); ?></textarea>
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-12">
							<span class="help-block fpbx-help-block">
								<?php echo _('What is served under that filename, stored as typed and rendered the same way the profile\'s template is. The content type is taken from the extension: .xml is served as XML, .json as JSON, anything else as plain text.'); ?>
							</span>
							<?php include __DIR__ . '/partials/placeholders.php'; ?>
						</div>
					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<script>

	const orykResources = '?display=oryk_provisioner&profile=<?php echo $profileId; ?>&tab=resources';

	orykEditor({
		save: 'saveResource',
		remove: 'deleteResource',
		confirm: 'Delete this resource?',
		values: function () {
			return {
				id: $('#resource_row_id').val(),
				profile_id: $('#resource_profile_id').val(),
				name: $('#resource_name').val(),
				template: $('#resource_template').val()
			};
		},
		// Back to the profile's Resources tab, which is re-rendered on
		// arrival, with the row that was just written picked out.
		saved: function (response) {
			return orykResources + '&saved=' + encodeURIComponent(response.id);
		},
		closed: orykResources
	});

</script>
