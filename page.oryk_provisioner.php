<?php
/**
 * Oryk Provisioner - FreePBX page entry point.
 *
 * Provisioning requests (token + filename) have already been answered and the
 * process stopped inside doConfigPageInit(), so anything reaching this file is
 * an authenticated administrator asking for the interface.
 */

if (!defined('FREEPBX_IS_AUTH')) {
	die('No direct script access allowed');
}

$oryk_provisioner = \FreePBX::create()->Oryk_provisioner;

echo $oryk_provisioner->showPage();
