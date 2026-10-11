<?php
/**
 * views/partials/placeholders.php -- what a template can refer to.
 *
 * Listed rather than discovered from a rendering, because an editor has to be
 * able to say what the names are with no client in hand: a new profile is not
 * assigned to anything yet, and a resource never is directly. The `sip.` names
 * are whatever the device carries in FreePBX, so a few are named by way of
 * example instead of the lot being listed.
 *
 * Included by both editors, which have $placeholders in scope.
 *
 * @var array<string, array<string, string>> $placeholders
 */

$placeholders = $placeholders ?? [];
?>
<div class="oryk-mt-1">
	<?php foreach ($placeholders as $group => $names): ?>
		<div class="oryk-placeholder-group">
			<span class="oryk-block text-muted"><?php echo htmlspecialchars((string) $group, ENT_QUOTES, 'UTF-8'); ?></span>
			<?php foreach ($names as $name => $note): ?>
				<code title="<?php echo htmlspecialchars((string) $note, ENT_QUOTES, 'UTF-8'); ?>"><?php
					echo '{{' . htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8') . '}}';
				?></code>
			<?php endforeach; ?>
		</div>
	<?php endforeach; ?>
	<div class="oryk-placeholder-group">
		<span class="oryk-block text-muted"><?php echo _('Anything else the device is configured with in FreePBX'); ?></span>
		<code>{{sip.transport}}</code>
		<code>{{sip.callerid}}</code>
		<code>{{sip.dtmfmode}}</code>
	</div>
</div>
