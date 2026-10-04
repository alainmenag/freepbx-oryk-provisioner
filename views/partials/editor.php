<?php
/**
 * views/partials/editor.php -- what the editors share.
 *
 * views/client.php, views/profile.php, views/resource.php and views/user.php
 * are one page bound to different rows: fields, and an action bar of Save,
 * Delete and Close that posts to ajax.php rather than submitting anything. Everything
 * alike about them is here -- orykPost(), orykShowError(), orykEscape(),
 * orykBytes(), formatResourceKind(), orykRenderButtons(), the placeholder chips
 * and orykEditor();
 * what is left in each view is its own fields and where Save goes next.
 *
 * Neither page contains a <form>. The module page is itself rendered inside
 * the FreePBX page form, and a nested form is dropped by the browser, which
 * left the Save button submitting the page as a GET to config.php and losing
 * display=oryk_provisioner. Values are read by id and posted explicitly.
 *
 * Which is why a tab being a link matters here. Only the pane that was asked
 * for is rendered, so on any tab but the editor's own the fields Save posts
 * are not on the page -- and $('#client_mac').val() of nothing is undefined,
 * which jQuery posts as the nine letters of it. Pages::getActionBar() draws
 * Save only on the editor's own tab, so the binding in
 * assets/scripts/editor.js has nothing to
 * bind to on the others. Delete and Close stay: both act on the row rather
 * than on the fields, and the row's id is printed into every one of its
 * tabs as a constant rather than read out of a hidden input in the first
 * pane.
 *
 * Both views render an alert with id `oryk_error` for orykShowError() to fill.
 *
 * A field's help follows FreePBX's own convention, which is the only one its
 * CSS shows: a `.fpbx-help-block` is hidden until the
 * `<i class="fa fa-question-circle fpbx-help-icon" data-for="<id>">` after the
 * label is hovered, and then the element with id `<id>-help` is shown.
 *
 * The placeholder chips below the template are copied by clicking one, which
 * is wired in assets/scripts/editor.js because both editors include the
 * same list.
 */
?>

<script>
	// Said by the copied-placeholder label, which CSS draws and so cannot
	// translate itself.
	var orykCopied = <?php echo json_encode(_('copied')); ?>;
	var orykCopyFailed = <?php echo json_encode(_('could not copy')); ?>;
</script>
<?php echo $script('editor'); ?>
