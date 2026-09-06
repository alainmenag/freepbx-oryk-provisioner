<?php
/**
 * Run every test in this directory.
 *
 *   php tests/run.php
 *
 * The suite uses an in-memory SQLite database and never touches FreePBX, so it
 * is safe to run on a PBX or on a workstation.
 */

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/EngineTest.php';
require_once __DIR__ . '/ViewTest.php';
require_once __DIR__ . '/AjaxTest.php';

exit(oryk_report());
