<?php

// tests/namespacing.php
//
// Every global class named inside a namespaced file has to be qualified or
// imported. Unqualified, PHP looks for it in the current namespace and the
// file fatals -- but only when that line runs, which may be a code path no
// test reaches and no page opens until somebody deletes a device.
//
// This is a lexical check, so it does not care whether a line ever runs.
// Returns a list of problems; empty means clean.

function oryk_unqualified_classes($path)
{
	$tokens = token_get_all(file_get_contents($path));
	$found = [];
	$declared = [];
	$imported = [];
	$line = 0;

	// What this file declares or imports
	for ($i = 0; $i < count($tokens); $i++) {
		$t = $tokens[$i];

		if (!is_array($t)) {
			continue;
		}

		if ($t[0] === T_CLASS || $t[0] === T_INTERFACE || $t[0] === T_TRAIT) {
			for ($j = $i + 1; $j < count($tokens); $j++) {
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
					$declared[strtolower($tokens[$j][1])] = true;

					break;
				}
			}
		}

		if ($t[0] === T_USE) {
			for ($j = $i + 1; $j < count($tokens); $j++) {
				if ($tokens[$j] === ';' || $tokens[$j] === '{') {
					break;
				}

				if (is_array($tokens[$j])
					&& in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED], true)) {
					$parts = explode('\\', $tokens[$j][1]);
					$imported[strtolower(end($parts))] = true;
				}
			}
		}
	}

	// Anything in the same namespace is fine: the loader finds it
	$siblings = [];

	foreach (glob(__DIR__ . '/../src/*.php') as $sibling) {
		$siblings[strtolower(basename($sibling, '.php'))] = true;
	}

	$safe = ['self' => true, 'parent' => true, 'static' => true]
		+ $declared + $imported + $siblings;

	// Now every place a class can be named
	for ($i = 0; $i < count($tokens); $i++) {
		$t = $tokens[$i];

		if (is_array($t)) {
			$line = $t[2];
		}

		if (!is_array($t) || $t[0] !== T_STRING) {
			continue;
		}

		$name = $t[1];

		if (isset($safe[strtolower($name)])) {
			continue;
		}

		// Foo::
		$next = $tokens[$i + 1] ?? null;
		$isStatic = is_array($next) && $next[0] === T_DOUBLE_COLON;

		// new Foo / catch (Foo / instanceof Foo
		$prev = null;

		for ($j = $i - 1; $j >= 0; $j--) {
			if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
				continue;
			}

			$prev = $tokens[$j];

			break;
		}

		$isNew = is_array($prev)
			&& in_array($prev[0], [T_NEW, T_INSTANCEOF], true);
		$isCatch = $prev === '(' ;

		if ($isCatch) {
			// only count it when the '(' belongs to a catch
			$isCatch = false;

			for ($j = $i - 2; $j >= 0 && $j > $i - 6; $j--) {
				if (is_array($tokens[$j]) && $tokens[$j][0] === T_CATCH) {
					$isCatch = true;

					break;
				}
			}
		}

		if ($isStatic || $isNew || $isCatch) {
			$found[] = sprintf('%s:%d  %s', basename($path), $line, $name);
		}
	}

	return $found;
}
