<?php
/**
 * views/job.php -- one job, read-only: what it was made for, and its steps.
 *
 * Reached at ?display=oryk_provisioner&job=<id>. Nothing here is edited: the
 * action bar is Delete and Close, and a failed job has Retry, which puts it
 * back in its user's queue and starts the worker. A job still waiting or
 * running reloads itself until it is not. See ARCHITECTURE.md, "Jobs".
 *
 * @var array<string, mixed>             $job       Jobs::jobRow(), with steps
 * @var array<string, string>            $names     Every service's name, by slug: a step's own is a snapshot
 * @var array<int, array<string, mixed>> $navigator Levels the navigator draws -- see partials/navigator.php
 * @var array<int, array<string, mixed>> $sections  Navigator::sections() -- see partials/sections.php
 * @var string                           $version   Module version -- see partials/sections.php
 */

use FreePBX\Modules\Oryk_Provisioner\Jobs;

$jobId = (int) $job['id'];
$state = (string) $job['state'];
$names = isset($names) && is_array($names) ? $names : [];
$labels = Jobs::labels();

$h = function ($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};

$tab = 'job';
$tabs = [
	'job' => [
		'label' => _('Job'),
		'href' => '?display=oryk_provisioner&job=' . $jobId,
	],
];

// A read-only line: label, then what is already escaped.
$fact = function ($label, $html) use ($h) {
	echo '<div class="element-container"><div class="row"><div class="form-group">';
	echo '<div class="col-md-4"><label class="control-label">' . $h($label) . '</label></div>';
	echo '<div class="col-md-8"><p class="form-control-static flex" style="gap: 5px;">' . $html . '</p></div>';
	echo '</div></div></div>';
};

// A service, linked while it exists.
$service = function ($slug, $name = '') use ($h, $names) {
	$slug = (string) $slug;
	$text = $name !== '' ? $name : ($names[$slug] ?? $slug);

	return isset($names[$slug])
		? '<a href="?display=oryk_provisioner&amp;service=' . $h(rawurlencode($slug)) . '">' . $h($text) . '</a>'
		: $h($text);
};

$stateClass = ['queued' => 'label-default', 'running' => 'label-info', 'done' => 'label-success', 'failed' => 'label-danger'];
$stepClass = ['pending' => 'label-default', 'done' => 'label-success', 'failed' => 'label-danger', 'skipped' => 'label-warning'];
?>
<?php include __DIR__ . '/partials/editor.php'; ?>

<div class="provisioner container-fluid">
	<div class="fpbx-container">
		<div class="display no-border">

			<?php include __DIR__ . '/partials/sections.php'; ?>
			<?php include __DIR__ . '/partials/navigator.php'; ?>

			<div class="section no-border" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<?php include __DIR__ . '/partials/tabs.php'; ?>

				<div class="tab-content">
					<div class="tab-pane oryk-tab-section active" id="oryk_job">

						<?php
						$fact(_('Job'), '#' . $jobId . ' ' . ((string) $job['service'] !== '' ? $service($job['service'], (string) $job['name']) : $h(Jobs::title($job))));

						$fact(_('User'), '<a href="?display=oryk_provisioner&amp;user=' . $h(rawurlencode((string) $job['extension'])) . '&amp;tab=services">' . $h($job['extension']) . '</a>');

						$fact(_('Reason'), $h($labels['reason'][(string) $job['reason']] ?? $job['reason'])
							. ' <span class="text-muted">' . $h($labels['source'][(string) $job['source']] ?? $job['source'])
							. ((string) ($job['admin'] ?? '') !== '' ? ': ' . $h($job['admin']) : '') . '</span>');

						$fact(_('State'), '<span class="label ' . ($stateClass[$state] ?? 'label-default') . '">' . $h($labels['state'][$state] ?? $state) . '</span>'
							. ($state === 'failed'
								? ' <button type="button" class="btn btn-default btn-sm" id="oryk_job_retry">' . $icon('refresh') . ' ' . $h(_('Retry')) . '</button>'
								: ''));

						$fact(_('Attempts'), $h($job['attempts']));
						$fact(_('Created'), $h($job['created_at']));
						$fact(_('Last Started'), $job['started_at'] ? $h($job['started_at']) : '-');
						$fact(_('Finished'), $job['finished_at'] ? $h($job['finished_at']) : '-');

						if ((string) ($job['error'] ?? '') !== '') {
							$fact(_('Error'), '<span class="text-danger oryk-job-error">' . $h($job['error']) . '</span>');
						}
						?>

						<table class="table table-striped oryk-job-steps">
							<thead>
								<tr>
									<th><?php echo _('Service'); ?></th>
									<th><?php echo _('Through'); ?></th>
									<th><?php echo _('Event'); ?></th>
									<th><?php echo _('State'); ?></th>
									<th><?php echo _('Finished By'); ?></th>
									<th><?php echo _('Attempts'); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($job['steps'] as $step): ?>
								<?php $stepState = (string) $step['state']; ?>
								<tr>
									<td><?php echo $service($step['service'], (string) $step['name']); ?></td>
									<td><?php echo $step['via'] !== null ? $service($step['via']) : '-'; ?></td>
									<td><?php echo $h($labels['event'][(string) $step['event']] ?? $step['event']); ?></td>
									<td>
										<span class="label <?php echo $stepClass[$stepState] ?? 'label-default'; ?>"><?php echo $h($labels['step'][$stepState] ?? $stepState); ?></span>
										<?php if ((string) ($step['error'] ?? '') !== ''): ?>
										<p class="text-danger oryk-job-error"><?php echo $h($step['error']); ?></p>
										<?php endif; ?>
									</td>
									<td><?php
									// This module's own entry names the job in src/Jobs/ it ran.
									$finished = array_map(function ($name) use ($step) {
										return $name === 'oryk_provisioner' && (string) ($step['own_job'] ?? '') !== ''
											? $name . ' (' . $step['own_job'] . ')'
											: $name;
									}, Jobs::doneBy($step));
									echo $finished ? $h(implode(', ', $finished)) : '-';
									?></td>
									<td><?php echo $h($step['attempts']); ?></td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

					</div>
				</div>

			</div>
		</div>
	</div>
</div>

<script>

	const orykJobId = <?php echo $jobId; ?>;

	// Still to run: shown again in a moment, until it has finished one way or the other.
	if (<?php echo json_encode(in_array($state, ['queued', 'running'], true)); ?>) {
		window.setTimeout(function () {
			window.location.reload();
		}, 2000);
	}

	$(document).on('click', '#oryk_job_retry', function () {
		const button = $(this).prop('disabled', true);

		orykPost('retryJob', { id: orykJobId }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				orykShowError(response && response.message);
				return;
			}

			window.location.reload();
		}).fail(function () {
			button.prop('disabled', false);
			orykShowError('The server could not be reached.');
		});
	});

	orykEditor({
		save: '',
		remove: 'deleteJob',
		confirm: <?php echo json_encode($state === 'running'
			? _('Delete this job? It stops before its next step; what it has done already stays done.')
			: _('Delete this job? What it has done already stays done.')); ?>,
		values: function () {
			return { id: orykJobId };
		},
		page: function () {
			return '?display=oryk_provisioner&job=' + orykJobId;
		},
		closed: '?display=oryk_provisioner&tab=jobs'
	});

</script>
