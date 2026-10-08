<?php
/**
 * views/partials/jobs.php -- the jobs, as a table.
 *
 * One row per job: the service it was made for, the user, why, where it
 * stands, how many of its steps are finished, and Retry on a failed one and
 * Delete on any. Its page (?job=) has the steps. See ARCHITECTURE.md, "Jobs".
 *
 * On the Jobs section three selects narrow it -- state, reason and source,
 * each All by default -- and **are the address** (`&state=`, `&reason=`,
 * `&source=`, left out at All), as the Services list's are; each option says
 * how many it would list with the other two as they are. Overview draws the
 * same table for one user, with Clear in their place.
 *
 * Narrowed by `&scope=` like every list (see views/admin.php).
 *
 * Included by views/admin.php, which defines orykPost() and orykEscape().
 *
 * @var array<string, string>|null $jobFilter Jobs::filters(), or null for no filters (Overview)
 * @var string                     $jobScope  The `&scope=` value, or ''
 * @var callable                   $icon      Prints assets/icons/<name>.svg
 */

use FreePBX\Modules\Oryk_Provisioner\Jobs;

$jobFilter = isset($jobFilter) && is_array($jobFilter) ? $jobFilter : null;
$jobScope = isset($jobScope) ? (string) $jobScope : '';
$jobLabels = Jobs::labels();
$jobSelects = ['state' => _('State'), 'reason' => _('Reason'), 'source' => _('Source')];
?>

<?php if ($jobFilter === null): ?>
<div id="job_toolbar" class="oryk-toolbar">
	<button type="button" class="btn btn-danger" id="oryk_overview_jobs_clear" title="<?php echo _('Clear Jobs'); ?>">
		<?php echo $icon('trash'); ?>
		<?php echo _('Clear'); ?>
	</button>
</div>
<?php else: ?>
<div id="job_toolbar" class="oryk-toolbar">
	<?php foreach ($jobSelects as $jobKey => $jobAria): ?>
	<select class="form-control oryk-toolbar-filter oryk-job-filter" id="job_<?php echo $jobKey; ?>" data-filter="<?php echo $jobKey; ?>" aria-label="<?php echo htmlspecialchars($jobAria, ENT_QUOTES, 'UTF-8'); ?>">
		<option value="all" data-label="<?php echo htmlspecialchars(sprintf(_('Any %s'), strtolower($jobAria)), ENT_QUOTES, 'UTF-8'); ?>"<?php echo $jobFilter[$jobKey] === 'all' ? ' selected' : ''; ?>><?php echo htmlspecialchars(sprintf(_('Any %s'), strtolower($jobAria)), ENT_QUOTES, 'UTF-8'); ?></option>
		<?php foreach ($jobLabels[$jobKey] as $jobValue => $jobText): ?>
		<option value="<?php echo $jobValue; ?>" data-label="<?php echo htmlspecialchars($jobText, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $jobFilter[$jobKey] === $jobValue ? ' selected' : ''; ?>><?php echo htmlspecialchars($jobText, ENT_QUOTES, 'UTF-8'); ?></option>
		<?php endforeach; ?>
	</select>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<table
	id="job_table"
	data-toggle="table"
	data-url="ajax.php?module=oryk_provisioner&command=listJobs<?php echo htmlspecialchars($jobScope !== '' ? '&scope=' . rawurlencode($jobScope) : '', ENT_QUOTES, 'UTF-8'); ?>"
	data-toolbar="#job_toolbar"
	data-query-params="orykJobQuery"
	class="table table-striped"
	data-side-pagination="server"
	data-pagination="true"
	data-search="<?php echo $jobFilter !== null ? 'true' : 'false'; ?>"
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
	const orykJobScope = <?php echo json_encode($jobScope); ?>;

	// The filters ride in the list's query, read off the selects the page drew
	// from its address; the server whitelists the values.
	function orykJobQuery(params) {
		$('.oryk-job-filter').each(function () {
			params[$(this).data('filter')] = $(this).val() || '';
		});

		return params;
	}

	// A filter is part of the address, so choosing one goes there.
	$(document).on('change', '.oryk-job-filter', function () {
		let href = '?display=oryk_provisioner&tab=jobs' + (orykJobScope !== '' ? '&scope=' + encodeURIComponent(orykJobScope) : '');

		$('.oryk-job-filter').each(function () {
			if ($(this).val() !== 'all') {
				href += '&' + $(this).data('filter') + '=' + encodeURIComponent($(this).val());
			}
		});

		window.location = href;
	});

	// Each option counts what choosing it would list, the other two as they are.
	$(document).on('load-success.bs.table', '#job_table', function (event, data) {
		const counts = data && data.counts;

		if (!counts) {
			return;
		}

		$('.oryk-job-filter').each(function () {
			const of = counts[$(this).data('filter')] || {};
			let all = 0;

			Object.keys(of).forEach((value) => { all += of[value]; });

			$(this).find('option').each(function () {
				$(this).text(`${$(this).data('label')} (${this.value === 'all' ? all : (of[this.value] || 0)})`);
			});
		});
	});

	function orykJobHref(row) {
		return '?display=oryk_provisioner&job=' + encodeURIComponent(row.id);
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

		return `<a href="?display=oryk_provisioner&service=${encodeURIComponent(row.service)}">${orykEscape(value || row.service)}</a>`;
	}

	function formatJobUser(value) {
		return `<a href="?display=oryk_provisioner&user=${encodeURIComponent(value)}&tab=services">${orykEscape(value)}</a>`;
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
			`<div class="flex gap-3" style="justify-content: flex-end;">`,
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
