<?php
/**
 * views/partials/services.php -- the services, as a table.
 *
 * A name and its actions: what a service is under, and what is under it, are
 * on its own page. One of the
 * module's own is labelled so, and has View where the others have a trash
 * can and Edit.
 *
 * Two selects in the toolbar narrow it, independently: whose the service is
 * (Custom, which it opens on, or Module) and which kind it is: Services,
 * which it opens on and which means one with nothing under it, or Service
 * Packs. Neither select has an "all". **The filters are the address**
 * (`&source=`, `&kind=`, each left out at its default): changing one is a
 * page load, so a reload, a bookmark and Back all keep them. Each option says how many services it
 * would list with the other select left as it is, from the `counts` every
 * listServices answer carries, so a delete or a change of filter keeps them
 * true. They count every service, whatever is in the search box.
 *
 * Included by views/admin.php, which already defines orykPost() and
 * orykEscape().
 *
 * @var array<string, string> $serviceFilter Services::filters(): source, kind
 * @var callable              $icon          Prints assets/icons/<name>.svg
 */

$serviceFilter = (isset($serviceFilter) && is_array($serviceFilter) ? $serviceFilter : []) + ['source' => 'custom', 'kind' => 'single'];
$serviceChosen = function ($filter, $value) use ($serviceFilter) {
	return $serviceFilter[$filter] === $value ? ' selected' : '';
};
?>

<div id="service_toolbar" class="oryk-toolbar">
	<a class="btn btn-primary" href="?display=oryk_provisioner&amp;service=">
		<?php echo $icon('plus'); ?> <?php echo _('Add Service'); ?>
	</a>
	<select class="form-control oryk-toolbar-filter" id="service_source" aria-label="<?php echo _('Show services from'); ?>">
		<option value="custom" data-label="<?php echo _('Custom'); ?>"<?php echo $serviceChosen('source', 'custom'); ?>><?php echo _('Custom'); ?></option>
		<option value="module" data-label="<?php echo _('Module'); ?>"<?php echo $serviceChosen('source', 'module'); ?>><?php echo _('Module'); ?></option>
	</select>
	<select class="form-control oryk-toolbar-filter" id="service_kind" aria-label="<?php echo _('Show service packs'); ?>">
		<option value="single" data-label="<?php echo _('Services'); ?>"<?php echo $serviceChosen('kind', 'single'); ?>><?php echo _('Services'); ?></option>
		<option value="pack" data-label="<?php echo _('Service Packs'); ?>"<?php echo $serviceChosen('kind', 'pack'); ?>><?php echo _('Service Packs'); ?></option>
	</select>
</div>

<table
	id="service_table"
	data-toggle="table"
	data-url="ajax.php?module=oryk_provisioner&command=listServices"
	data-toolbar="#service_toolbar"
	data-query-params="orykServiceQuery"
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
			<th data-field="actions" data-formatter="formatServiceActions" data-align="right"><?php echo _('Actions'); ?></th>
		</tr>
	</thead>
</table>

<script>

	// Both filters ride in the list's query, read off the selects the page
	// drew from its address. The server whitelists the values.
	function orykServiceQuery(params) {
		params.source = $('#service_source').val() || '';
		params.kind = $('#service_kind').val() || '';

		return params;
	}

	// A filter is part of the address, so choosing one goes there; a default
	// is the address without it.
	$(document).on('change', '#service_source, #service_kind', function () {
		const source = $('#service_source').val();
		const kind = $('#service_kind').val();

		window.location = '?display=oryk_provisioner&tab=services'
			+ (source !== 'custom' ? '&source=' + encodeURIComponent(source) : '')
			+ (kind !== 'single' ? '&kind=' + encodeURIComponent(kind) : '');
	});

	// Each option's count is of what choosing it would list, the other select
	// staying where it is: the two filters cross.
	$(document).on('load-success.bs.table', '#service_table', function (event, data) {
		const counts = data && data.counts;

		if (!counts) {
			return;
		}

		const source = $('#service_source').val();
		const kind = $('#service_kind').val();

		$('#service_source option').each(function () {
			$(this).text(`${$(this).data('label')} (${(counts[this.value] || {})[kind] || 0})`);
		});

		$('#service_kind option').each(function () {
			$(this).text(`${$(this).data('label')} (${(counts[source] || {})[this.value] || 0})`);
		});
	});

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
