<?php
/**
 * views/partials/services.php -- the services, as a table.
 *
 * Service Pack is ticked on a service with at least one service under it;
 * which ones, and what it is under itself, are on its own page. One of the
 * module's own is labelled so, and has View where the others have a trash
 * can and Edit.
 *
 * Included by views/admin.php, which already defines orykPost() and
 * orykEscape().
 *
 * @var callable $icon Prints assets/icons/<name>.svg
 */
?>

<div id="service_toolbar" class="oryk-toolbar">
	<a class="btn btn-primary" href="?display=oryk_provisioner&amp;service=">
		<?php echo $icon('plus'); ?> <?php echo _('Add Service'); ?>
	</a>
</div>

<table
	id="service_table"
	data-toggle="table"
	data-url="ajax.php?module=oryk_provisioner&command=listServices"
	data-toolbar="#service_toolbar"
	class="table table-striped"
	data-side-pagination="server"
	data-pagination="true"
	data-search="true"
	data-show-refresh="true"
	data-icons-prefix="oryk-icon"
	data-icons='{"refresh":"oryk-icon-refresh"}'
	data-unique-id="id"
	data-sort-name="name"
	data-sort-order="asc">
	<thead>
		<tr>
			<th data-field="name" data-formatter="formatServiceName" data-sortable="true"><?php echo _('Name'); ?></th>
			<th data-field="pack" data-formatter="formatServicePack" data-sortable="true" data-align="center"><?php echo _('Service Pack'); ?></th>
			<th data-field="actions" data-formatter="formatServiceActions" data-align="right"><?php echo _('Actions'); ?></th>
		</tr>
	</thead>
</table>

<script>

	// A service's page is named by its slug.
	function orykServiceHref(row) {
		return '?display=oryk_provisioner&service=' + encodeURIComponent(row.slug);
	}

	function formatServiceName(value, row) {
		if (!value) {
			return '-';
		}

		const link = `<a href="${orykServiceHref(row)}">${orykEscape(value)}</a>`;

		return row.managed
			? `${link} <span class="label label-default" title="Managed by the module: it cannot be edited or deleted">module</span>`
			: link;
	}


	// A pack is a service with services under it; the title says how many.
	function formatServicePack(value, row) {
		const count = Number(row.children);

		return count
			? `<span title="${count} service${count === 1 ? '' : 's'} under it" aria-label="Service pack">${orykIcon('check')}</span>`
			: '';
	}

	function formatServiceActions(value, row) {
		const href = orykServiceHref(row);

		return [
			`<div class="flex gap-3" style="justify-content: flex-end;">`,
			row.managed ? '' : `<button type="button" class="btn btn-danger btn-sm" name="service_delete" value="${orykEscape(row.id)}">${orykIcon('trash')}</button>`,
			`<a class="btn btn-primary btn-sm" href="${href}">${row.managed ? 'View' : 'Edit'}</a>`,
			`</div>`
		].join('');
	}

	$(document).on('click', '[name="service_delete"]', function () {
		orykAsk('Delete this service? The services over and under it are kept.').done(() => {
			orykPost('deleteService', { id: $(this).val() }).done(function (response) {
				if (!response || !response.status) {
					notie.alert(3, (response && response.message) || 'Could not delete.', 4);
					return;
				}

				$('#service_table').bootstrapTable('refresh');
				notie.alert(1, 'Deleted.', 2);
			});
		});
	});

</script>
