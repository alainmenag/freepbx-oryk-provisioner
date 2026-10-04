<?php
/**
 * The module page: one pane per section -- Users, Clients, Profiles, Logs,
 * Bans and Settings.
 *
 * Every table is filled by the module's AJAX commands, so nothing on this
 * page is rendered from data: what it is handed is which tab to open and
 * which row was just written, so it can say so. Logs is the provisioning log
 * unnarrowed, and is drawn by partials/logs.php, which the client editor
 * includes too.
 *
 * Neither a client nor a profile is edited here. Both are pages of their own
 * -- views/client.php and views/profile.php -- which the Add and Edit buttons
 * link to. What is left on the list is deletion and the switch, which is the
 * other thing that needs no page: one column, changed on the row it is shown
 * on. The clients table and the profiles table both have one, they mean the
 * same thing -- the endpoint answers this row, or it answers nothing for it
 * -- so both are drawn and handled by the same three functions in
 * assets/scripts/admin.js. The
 * one action on either table that leaves the PBX is on a client: a link to
 * the phone's own web interface, drawn from the private address written on
 * that client and only on the rows that have one.
 *
 * The sections are the bar every page is topped with -- see
 * partials/sections.php -- so this page has no tab strip of its own. Only the
 * section asked for is rendered. Each names itself (`&tab=<section>`) rather
 * than one being the bare URL, though a bare ?display=oryk_provisioner opens
 * Users, the first of them.
 *
 * Settings is the one tab with fields rather than a table, drawn by
 * partials/settings.php and saved by the action bar's Save.
 *
 * Bans is the bans table: who the endpoint refuses, or answers in spite of a
 * ban -- see ARCHITECTURE.md, "Bans".
 *
 * Opened from a navigator title, a table is narrowed to what that title's badge
 * counted: `$scope` names the row, and every list command is asked with it.
 *
 * @var string             $tab    Section to open on, settled by Navigator::section()
 * @var array<int, array<string, mixed>>  $navigator Levels the navigator draws -- see partials/navigator.php
 * @var array<int, array<string, mixed>>  $sections Navigator::sections() -- see partials/sections.php
 * @var string                            $version  Module version -- see partials/sections.php
 * @var array<int, array<string, mixed>>  $settings  Settings::fields(), on the Settings tab
 * @var array<string, mixed>              $sync      Fail2ban::status(), on the Bans tab
 * @var string                            $remote    The address this page was asked from, canonical
 * @var array<string, string>|null        $scope     Pages::scopeBanner(): what the list is narrowed to, or null
 * @var int                               $expireDays ORYK_OPEN_EXPIRE_DAYS, on the Users tab: Expired is offered above 0
 */

$tab = (string) ($tab ?? 'users');
$sync = isset($sync) && is_array($sync) ? $sync : [];
$scope = isset($scope) && is_array($scope) ? $scope : null;
$expireDays = (int) ($expireDays ?? 0);
// Appended to every list command's URL, so a refresh stays narrowed.
$scopeQuery = htmlspecialchars($scope ? '&scope=' . rawurlencode($scope['key']) : '', ENT_QUOTES, 'UTF-8');
?>

<div class="provisioner container-fluid">
	<div class="fpbx-container">
		<div class="display no-border">

			<?php include __DIR__ . '/partials/sections.php'; ?>
			<?php include __DIR__ . '/partials/navigator.php'; ?>

			<div class="section no-border" style="padding: 0;">

				<div class="alert alert-danger hidden" id="oryk_error"></div>

				<?php if ($scope): ?>
				<p class="oryk-scope">
					<?php echo sprintf(
						htmlspecialchars(_('Only what is linked to %s.'), ENT_QUOTES, 'UTF-8'),
						'<a href="' . htmlspecialchars($scope['href'], ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($scope['text'], ENT_QUOTES, 'UTF-8') . '</a>'
					); ?>
					<a class="oryk-scope-all" href="<?php echo htmlspecialchars($scope['all'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo _('Show all'); ?></a>
				</p>
				<?php endif; ?>

				<div class="tab-content">

					<?php if ($tab === 'clients'): ?>
					<div class="tab-pane active" id="oryk_clients">
						<div id="client_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;client=">
								<i class="fa fa-plus"></i> <?php echo _('Add Client'); ?>
							</a>
						</div>

						<table
							id="client_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listClients<?php echo $scopeQuery; ?>"
							data-toolbar="#client_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="id"
							data-row-style="orykRowClasses"
							data-sort-name="mac"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="mac" data-formatter="formatMac" data-sortable="true"><?php echo _('MAC Address'); ?></th>
									<th data-field="description" data-formatter="formatText" data-sortable="true"><?php echo _('Description'); ?></th>
									<th data-field="device_id" data-formatter="formatDevice" data-sortable="true"><?php echo _('Device'); ?></th>
									<th data-field="extension" data-formatter="formatExtension" data-sortable="true"><?php echo _('Extension'); ?></th>
									<th data-field="profile" data-formatter="formatClientProfile" data-sortable="true"><?php echo _('Profile'); ?></th>
									<th data-field="secure" data-formatter="formatClientSecure" data-sortable="true"><?php echo _('Secure'); ?></th>
									<th data-field="last_seen" data-formatter="formatClientSeen" data-sortable="true"><?php echo _('Last Seen'); ?></th>
									<th data-field="actions" data-formatter="formatClientActions" data-align="right"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
					</div>

					<?php endif; ?>

					<?php if ($tab === 'profiles'): ?>
					<div class="tab-pane active" id="oryk_profiles">
						<div id="profile_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;profile=">
								<i class="fa fa-plus"></i> <?php echo _('Add Profile'); ?>
							</a>
						</div>

						<table
							id="profile_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listProfiles<?php echo $scopeQuery; ?>"
							data-toolbar="#profile_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="id"
							data-row-style="orykRowClasses"
							data-sort-name="name"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="name" data-formatter="formatProfileName" data-sortable="true" ><?php echo _('Name'); ?></th>
									<th data-field="assigned" data-formatter="formatAssignedClients" data-sortable="true"><?php echo _('Clients'); ?></th>
									<th data-field="actions" data-formatter="formatProfileActions" data-align="right"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
					</div>

					<?php endif; ?>

					<?php if ($tab === 'users'): ?>
					<div class="tab-pane active" id="oryk_users">
						<div id="user_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;user=">
								<i class="fa fa-plus"></i> <?php echo _('Add User'); ?>
							</a>
							<select class="form-control oryk-user-filter" id="user_filter" aria-label="<?php echo _('Show'); ?>">
								<option value=""><?php echo _('All users'); ?></option>
								<option value="lobby"><?php echo _('Lobby'); ?></option>
								<?php if ($expireDays > 0): ?>
								<option value="expired"><?php echo sprintf(_('Expired (unseen %d days)'), $expireDays); ?></option>
								<?php endif; ?>
							</select>
							<button type="button" class="btn btn-danger hidden" id="user_delete_expired">
								<i class="fa fa-trash"></i> <?php echo _('Delete listed'); ?>
							</button>
						</div>

						<table
							id="user_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listUsers<?php echo $scopeQuery; ?>"
							data-toolbar="#user_toolbar"
							data-query-params="orykUserQuery"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="extension"
							data-sort-name="extension"
							data-sort-order="asc">
							<thead>
								<tr>
									<th data-field="extension" data-formatter="formatUserExtension" data-sortable="true"><?php echo _('Extension'); ?></th>
									<th data-field="name" data-formatter="formatText" data-sortable="true"><?php echo _('Name'); ?></th>
									<th data-field="email" data-formatter="formatText" data-sortable="true"><?php echo _('Email'); ?></th>
									<th data-field="clients" data-formatter="formatUserClients" data-sortable="true"><?php echo _('Clients'); ?></th>
									<th data-field="secure" data-formatter="formatClientSecure" data-sortable="true"><?php echo _('Secure'); ?></th>
									<th data-field="context" data-formatter="formatUserContext" data-sortable="true"><?php echo _('Context'); ?></th>
									<th data-field="last_seen" data-formatter="formatClientSeen" data-sortable="true"><?php echo _('Last Seen'); ?></th>
									<th data-field="actions" data-formatter="formatUserActions" data-align="right"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
					</div>

					<?php endif; ?>

					<?php if ($tab === 'logs'): ?>
					<div class="tab-pane active" id="oryk_logs">
						<?php
						// Every request, not just the ones a client was found
						// for -- see the note at the top of the partial.
						$logMac = '';
						$logScope = $scope ? $scope['key'] : '';
						include __DIR__ . '/partials/logs.php';
						?>
					</div>
					<?php endif; ?>

					<?php if ($tab === 'bans'): ?>
					<div class="tab-pane active" id="oryk_bans">
						<div id="ban_toolbar" class="oryk-toolbar">
							<a class="btn btn-primary" href="?display=oryk_provisioner&amp;ban=">
								<i class="fa fa-plus"></i> <?php echo _('Add Ban'); ?>
							</a>
						</div>

						<table
							id="ban_table"
							data-toggle="table"
							data-url="ajax.php?module=oryk_provisioner&command=listBans<?php echo $scopeQuery; ?>"
							data-toolbar="#ban_toolbar"
							class="table table-striped"
							data-side-pagination="server"
							data-pagination="true"
							data-search="true"
							data-show-refresh="true"
							data-unique-id="id"
							data-row-style="orykBanRowClasses"
							data-sort-name="created_at"
							data-sort-order="desc">
							<thead>
								<tr>
									<th data-field="ip" data-formatter="formatBanIp" data-sortable="true"><?php echo _('IP'); ?></th>
									<th data-field="mac" data-formatter="formatBanMac" data-sortable="true"><?php echo _('MAC'); ?></th>
									<th data-field="user" data-formatter="formatBanUser" data-sortable="true"><?php echo _('User'); ?></th>
									<th data-field="client" data-formatter="formatBanClient" data-sortable="true"><?php echo _('Client'); ?></th>
									<th data-field="profile" data-formatter="formatBanProfile" data-sortable="true"><?php echo _('Profile'); ?></th>
									<th data-field="state" data-formatter="formatBanState" data-sortable="true"><?php echo _('State'); ?></th>
									<th data-field="hits" data-formatter="formatBanHits" data-sortable="true" data-align="right"><?php echo _('Hits'); ?></th>
									<th data-field="times" data-formatter="formatBanTimes" data-sortable="true" data-align="right"><?php echo _('Times'); ?></th>
									<th data-field="created_at" data-formatter="formatBanCreated" data-sortable="true"><?php echo _('Created'); ?></th>
									<th data-field="expires_at" data-formatter="formatBanExpires" data-sortable="true"><?php echo _('Expires'); ?></th>
									<th data-field="actions" data-formatter="formatBanActions" data-align="right"><?php echo _('Actions'); ?></th>
								</tr>
							</thead>
						</table>
						<?php
						// The fail2ban sync in one line, under the table: what it is doing, or what stops it.
						$syncState = (string) ($sync['state'] ?? '');
						$syncClass = ['ok' => 'success', 'disabled' => 'info'][$syncState] ?? 'warning';
						?>
						<?php if ($syncState !== ''): ?>
						<div class="alert alert-<?php echo $syncClass; ?>" style="margin: 0; clear: both;">
							<i class="fa fa-shield"></i>
							<?php if ($syncState === 'ok'): ?>
								<?php echo htmlspecialchars(sprintf(_('IP bans sync with fail2ban every minute (fail2ban %s): Banned into the banned jail, Deny into deny (both every port), Allow onto the ignore lists of %s only.'), (string) ($sync['fail2ban'] ?? ''), implode(', ', (array) ($sync['jails'] ?? []))), ENT_QUOTES, 'UTF-8'); ?>
							<?php else: ?>
								<strong><?php echo htmlspecialchars((string) ($sync['message'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
								<?php if (!empty($sync['detail'])): ?>
								<small class="text-muted"><?php echo htmlspecialchars((string) $sync['detail'], ENT_QUOTES, 'UTF-8'); ?></small>
								<?php endif; ?>
								<?php if (in_array($syncState, ['missing', 'sudo', 'stale', 'nojail'], true)): ?>
								<br><?php echo _('Run this once on the PBX, as root:'); ?>
								<code style="user-select: all;"><?php echo htmlspecialchars((string) ($sync['command'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></code>
								<?php endif; ?>
							<?php endif; ?>
						</div>
						<?php endif; ?>
					</div>
					<?php endif; ?>

					<?php if ($tab === 'settings'): ?>
					<div class="tab-pane oryk-tab-section active" id="oryk_settings">
						<?php include __DIR__ . '/partials/settings.php'; ?>
					</div>
					<?php endif; ?>

				</div>

			</div>

		</div>
	</div>
</div>

<script>
	// Said in one place because the client editor says the same thing about
	// the same client, and the two reading differently would be two answers
	// to one question.
	const orykClientNeverSeen = <?php echo json_encode(_('Never')); ?>;
	const orykBanRemote = <?php echo json_encode((string) ($remote ?? '')); ?>;
</script>
<?php echo $script('admin'); ?>
