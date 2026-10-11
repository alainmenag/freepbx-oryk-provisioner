<?php
/**
 * views/partials/jobs.php -- one user's jobs, on Overview.
 *
 * One row per job: the service it was made for, the user, why, where it
 * stands, how many of its steps are finished, and Retry on a failed one and
 * Delete on any; Clear deletes them all. A job and its steps are the services
 * module's pages, which every link here opens. The rows are its own, asked
 * through this module's listJobs (ServicesLink::ajax()) with the scope.
 *
 * Included by views/partials/overview.php, inside views/admin.php, which
 * defines orykPost() and orykEscape().
 *
 * @var string                     $jobScope  The `&scope=` value
 * @var array<string, array<string, string>> $jobLabels What each job state, reason and source is called, as every view is handed it
 * @var callable                   $icon      Prints assets/icons/<name>.svg
 */

$jobScope = isset($jobScope) ? (string) $jobScope : '';
?>

<div id="job_toolbar" class="oryk-toolbar oryk-toolbar-clear">
	<button type="button" class="btn btn-danger" id="oryk_overview_jobs_clear" title="<?php echo _('Clear Jobs'); ?>">
		<?php echo $icon('trash'); ?>
		<?php echo _('Clear'); ?>
	</button>
</div>
<table
	id="job_table"
	data-toggle="table"
	data-url="ajax.php?module=oryk_provisioner&command=listJobs<?php echo htmlspecialchars($jobScope !== '' ? '&scope=' . rawurlencode($jobScope) : '', ENT_QUOTES, 'UTF-8'); ?>"
	data-toolbar="#job_toolbar"
	class="table table-striped"
	data-side-pagination="server"
	data-pagination="true"
	data-search="false"
	data-show-refresh="true"
	data-icons-prefix="oryk-icon"
	data-icons='{"refresh":"oryk-icon-refresh"}'
	data-unique-id="id"
	data-sort-name="id"
	data-sort-order="desc">
	<thead>
		<tr>
			<th data-field="id" data-formatter="formatJobId" data-sortable="true"><?php echo _('Job'); ?></th>
			<th data-field="name" data-formatter="formatJobName" data-sortable="true"><?php echo _('Service'); ?></th>
			<th data-field="extension" data-formatter="formatJobUser" data-sortable="true"><?php echo _('User'); ?></th>
			<th data-field="reason" data-formatter="formatJobReason" data-sortable="true"><?php echo _('Reason'); ?></th>
			<th data-field="state" data-formatter="formatJobState" data-sortable="true"><?php echo _('State'); ?></th>
			<th data-field="steps" data-formatter="formatJobSteps" data-align="center"><?php echo _('Steps'); ?></th>
			<th data-field="attempts" data-sortable="true" data-align="center"><?php echo _('Attempts'); ?></th>
			<th data-field="created_at" data-sortable="true"><?php echo _('Created'); ?></th>
			<th data-field="actions" data-formatter="formatJobActions" data-align="right"><?php echo _('Actions'); ?></th>
		</tr>
	</thead>
</table>

<script>

	const orykJobLabelSet = <?php echo json_encode($jobLabels); ?>;

	function orykJobHref(row) {
		return '?display=oryk_services&job=' + encodeURIComponent(row.id);
	}

	function formatJobId(value, row) {
		return `<a href="${orykJobHref(row)}">#${orykEscape(value)}</a>`;
	}

	// The service it was made for, named as it was then; an upgrade names
	// none, nor does a save of several on a user's Services tab.
	function formatJobName(value, row) {
		if (!row.service) {
			return orykEscape(row.reason === 'changed' ? <?php echo json_encode(_('Several services')); ?> : orykJobLabelSet.source.upgrade);
		}

		return `<a href="?display=oryk_services&service=${encodeURIComponent(row.service)}">${orykEscape(value || row.service)}</a>`;
	}

	function formatJobUser(value) {
		return `<a href="?display=oryk_services&user=${encodeURIComponent(value)}">${orykEscape(value)}</a>`;
	}

	function formatJobReason(value) {
		return orykEscape(orykJobLabelSet.reason[value] || value);
	}

	function formatJobState(value, row) {
		const style = { queued: 'label-default', running: 'label-info', done: 'label-success', failed: 'label-danger' }[value] || 'label-default';
		const title = row.error ? ` title="${orykEscape(row.error)}"` : '';

		return `<span class="label ${style}"${title}>${orykEscape(orykJobLabelSet.state[value] || value)}</span>`;
	}

	function formatJobSteps(value, row) {
		return `${orykEscape(row.steps_done)} / ${orykEscape(value)}`;
	}

	function formatJobActions(value, row) {
		return [
			`<div class="oryk-flex oryk-gap-1 oryk-end">`,
			row.state === 'failed'
				? `<button type="button" class="btn btn-default btn-sm" name="job_retry" value="${orykEscape(row.id)}" title="Retry" aria-label="Retry">${orykIcon('refresh')}</button>`
				: '',
			`<button type="button" class="btn btn-danger btn-sm" name="job_delete" value="${orykEscape(row.id)}" title="Delete" aria-label="Delete">${orykIcon('trash')}</button>`,
			`<a class="btn btn-primary btn-sm" href="${orykJobHref(row)}" title="View" aria-label="View">${orykIcon('eye')}</a>`,
			`</div>`
		].join('');
	}

	$(document).on('click', '[name="job_retry"]', function () {
		const button = $(this).prop('disabled', true);

		orykPost('retryJob', { id: button.val() }).done(function (response) {
			if (!response || !response.status) {
				button.prop('disabled', false);
				notie.alert(3, (response && response.message) || 'Could not retry.', 4);
				return;
			}

			notie.alert(1, 'Queued again.', 2);
			window.setTimeout(() => $('#job_table').bootstrapTable('refresh', { silent: true }), 1500);
		}).fail(function () {
			button.prop('disabled', false);
			notie.alert(3, 'Could not retry.', 4);
		});
	});

	$(document).on('click', '[name="job_delete"]', function () {
		orykAsk('Delete this job? What it has done already stays done; a running one stops before its next step.').done(() => {
			const button = $(this).prop('disabled', true);

			orykPost('deleteJob', { id: button.val() }).done(function (response) {
				if (!response || !response.status) {
					button.prop('disabled', false);
					notie.alert(3, (response && response.message) || 'Could not delete.', 4);
					return;
				}

				$('#job_table').bootstrapTable('refresh');
				notie.alert(1, 'Deleted.', 2);
			}).fail(function () {
				button.prop('disabled', false);
				notie.alert(3, 'Could not delete.', 4);
			});
		});
	});

</script>
