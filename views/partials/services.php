<?php
/**
 * views/partials/services.php -- the services, as a table.
 *
 * A name, how many users are assigned it, and its actions: what a service
 * is under, and what is under it, are on its own page. One of the
 * module's own has View (an eye) where the others have a trash can and Edit
 * (a pencil); every action is an icon, named by its title.
 *
 * Two selects in the toolbar narrow it, independently: whose the service is
 * (Available, which is every one and what it opens on, Custom or Module) and which kind
 * it is: All, which it opens on, Services, which means one with nothing
 * under it, or Packs. **The filters are the address**
 * (`&source=`, `&kind=`, each left out at its default): changing one is a
 * page load, so a reload, a bookmark and Back all keep them. Each option says how many services it
 * would list with the other select left as it is, from the `counts` every
 * listServices answer carries, so a delete or a change of filter keeps them
 * true. They count every service in scope, whatever is in the search box.
 *
 * Narrowed by `&scope=` like every list (see views/admin.php), and a change
 * of filter keeps it.
 *
 * Included by views/admin.php, which already defines orykPost() and
 * orykEscape().
 *
 * @var array<string, string> $serviceFilter Services::filters(): source, kind
 * @var array<string, string>|null $scope    What the list is narrowed to, or null
 * @var callable              $icon          Prints assets/icons/<name>.svg
 */

$serviceFilter = (isset($serviceFilter) && is_array($serviceFilter) ? $serviceFilter : []) + ['source' => 'all', 'kind' => 'all'];
$serviceScope = (isset($scope) && is_array($scope)) ? (string) $scope['key'] : '';
$serviceChosen = function ($filter, $value) use ($serviceFilter) {
	return $serviceFilter[$filter] === $value ? ' selected' : '';
};
?>

<div id="service_toolbar" class="oryk-toolbar">
	<a class="btn btn-primary" href="?display=oryk_provisioner&amp;service=">
		<?php echo $icon('plus'); ?> <?php echo _('Add Service'); ?>
	</a>
	<select class="form-control oryk-toolbar-filter" id="service_source" aria-label="<?php echo _('Show services from'); ?>">
		<option value="all" data-label="<?php echo _('Available'); ?>"<?php echo $serviceChosen('source', 'all'); ?>><?php echo _('Available'); ?></option>
		<option value="custom" data-label="<?php echo _('Custom'); ?>"<?php echo $serviceChosen('source', 'custom'); ?>><?php echo _('Custom'); ?></option>
		<option value="module" data-label="<?php echo _('Module'); ?>"<?php echo $serviceChosen('source', 'module'); ?>><?php echo _('Module'); ?></option>
	</select>
	<select class="form-control oryk-toolbar-filter" id="service_kind" aria-label="<?php echo _('Show packs'); ?>">
		<option value="all" data-label="<?php echo _('All'); ?>"<?php echo $serviceChosen('kind', 'all'); ?>><?php echo _('All'); ?></option>
		<option value="single" data-label="<?php echo _('Services'); ?>"<?php echo $serviceChosen('kind', 'single'); ?>><?php echo _('Services'); ?></option>
		<option value="pack" data-label="<?php echo _('Packs'); ?>"<?php echo $serviceChosen('kind', 'pack'); ?>><?php echo _('Packs'); ?></option>
	</select>
</div>

<table
	id="service_table"
	data-toggle="table"
	data-url="ajax.php?module=oryk_provisioner&command=listServices<?php echo htmlspecialchars($serviceScope !== '' ? '&scope=' . rawurlencode($serviceScope) : '', ENT_QUOTES, 'UTF-8'); ?>"
	data-toolbar="#service_toolbar"
	data-query-params="orykServiceQuery"
	class="table table-striped"
	data-side-pagination="server"
	data-pagination="true"
	data-search="true"
	data-show-refresh="true"
	data-icons-prefix="oryk-icon"
	data-icons='{"refresh":"oryk-icon-refresh"}'
	data-unique-id="slug"
	data-sort-name="name"
	data-sort-order="asc">
	<thead>
		<tr>
			<th data-field="name" data-formatter="formatServiceName" data-sortable="true"><?php echo _('Name'); ?></th>
			<th data-field="assignments" data-sortable="true" data-align="center"><?php echo _('Assignments'); ?></th>
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

		const scope = <?php echo json_encode($serviceScope); ?>;

		window.location = '?display=oryk_provisioner&tab=services'
			+ (scope !== '' ? '&scope=' + encodeURIComponent(scope) : '')
			+ (source !== 'all' ? '&source=' + encodeURIComponent(source) : '')
			+ (kind !== 'all' ? '&kind=' + encodeURIComponent(kind) : '');
	});

	// Each option's count is of what choosing it would list, the other select
	// staying where it is: the two filters cross.
	$(document).on('load-success.bs.table', '#service_table', function (event, data) {
		const counts = data && data.counts;

		if (!counts) {
			return;
		}

		// How many one source has of one kind; `all` is both, of either.
		const held = function (source, kind) {
			if (source === 'all') {
				return held('custom', kind) + held('module', kind);
			}

			const of = counts[source] || {};

			return kind === 'all' ? (of.single || 0) + (of.pack || 0) : (of[kind] || 0);
		};

		const source = $('#service_source').val();
		const kind = $('#service_kind').val();

		$('#service_source option').each(function () {
			$(this).text(`${$(this).data('label')} (${held(this.value, kind)})`);
		});

		$('#service_kind option').each(function () {
			$(this).text(`${$(this).data('label')} (${held(source, this.value)})`);
		});
	});

	// A service's page is named by its slug.
	function orykServiceHref(row) {
		return '?display=oryk_provisioner&service=' + encodeURIComponent(row.slug);
	}

	function formatServiceName(value, row) {
		return value ? `<a href="${orykServiceHref(row)}">${orykEscape(value)}</a>` : '-';
	}


	function formatServiceActions(value, row) {
		const href = orykServiceHref(row);

		return [
			`<div class="flex gap-3" style="justify-content: flex-end;">`,
			row.managed ? '' : `<button type="button" class="btn btn-danger btn-sm" name="service_delete" value="${orykEscape(row.slug)}" title="Delete" aria-label="Delete">${orykIcon('trash')}</button>`,
			row.managed
				? `<a class="btn btn-primary btn-sm" href="${href}" title="View" aria-label="View">${orykIcon('eye')}</a>`
				: `<a class="btn btn-primary btn-sm" href="${href}" title="Edit" aria-label="Edit">${orykIcon('edit')}</a>`,
			`</div>`
		].join('');
	}

	$(document).on('click', '[name="service_delete"]', function () {
		orykAsk('Delete this service? The services over and under it are kept.').done(() => {
			orykPost('deleteService', { slug: $(this).val() }).done(function (response) {
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
