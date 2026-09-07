<?php

/**
 * /engine/provisioner.php -- the unauthenticated device provisioning endpoint.
 *
 * A phone has no admin login, and FreePBX's config.php forces every
 * session-less request to the login page before a module's doConfigPageInit()
 * ever runs -- a menu item's requires_auth flag governs menu visibility and
 * permissions, not anonymous access to a display page. So the device endpoint
 * does not go through config.php at all. This file is reached directly:
 *
 *   http(s)://<pbx>/provisioner/?mac=00908F3BBCBA
 *
 * and bootstraps FreePBX itself. It calls the same serveConfig() the admin
 * preview (config.php?...&config=) calls -- one engine, two ways in, and only
 * this one is reachable without a session.
 *
 * GET or POST; the MAC is read from either. The reply is text/plain, and every
 * failure is a uniform 404 so a MAC cannot be probed for whether it is known.
 */

// Bootstrap FreePBX for an anonymous request: skip the admin session and the
// Asterisk manager connection (this endpoint only reads the database), and
// load this module alone rather than every module's functions. These are read
// by bootstrap.php, which /etc/freepbx.conf pulls in.

$bootstrap_settings = [];
$bootstrap_settings['freepbx_auth'] = false;

// Don't load every module's functions.inc.php
$restrict_mods = true;

require '/etc/freepbx.conf';

$freepbx = \FreePBX::Create();
$provisioner = \FreePBX::Oryk_provisioner();

$mac = isset($_REQUEST['mac']) ? (string) $_REQUEST['mac'] : '';

// $freepbx->Logger->log(
// 		FPBX_LOG_INFO,
// 		'test ' . $mac
// );

$provisioner->serveConfig($mac);
