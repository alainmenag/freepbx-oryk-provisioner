<?php

// tests/phpstan-stubs.php
//
// What FreePBX provides at runtime and tests/stubs.php does not, declared
// only so PHPStan knows the names. Never loaded by anything that runs.

interface BMO
{
}

class FreePBX_Helpers
{
	/** @var mixed */
	public $FreePBX;
}

/**
 * @param string               $view Absolute path of the view.
 * @param array<string, mixed> $vars Variables the view is handed.
 *
 * @return string
 */
function load_view($view, array $vars = [])
{
	return '';
}

class extension
{
	/** @param mixed ...$args */
	public function __construct(...$args)
	{
	}
}

class ext_answer extends extension {}
class ext_goto extends extension {}
class ext_gotoif extends extension {}
class ext_hangup extends extension {}
class ext_playback extends extension {}
class ext_set extends extension {}
