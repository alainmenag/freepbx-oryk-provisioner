<?php

// src/Repo.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * A directory this module keeps files in.
 *
 * There are two, they are opposites, and they are the same shape: FileRepo is
 * what an operator uploaded and the endpoint hands out, LogRepo what a phone
 * uploaded and the endpoint hands back. Both hang a directory off one of
 * Asterisk's, both have to make it before writing, and both want it owned by
 * the web user that writes to it.
 *
 * Where the directory is, and what a file in it is called, is the subclass's.
 */
abstract class Repo extends Service
{
	/**
	 * A directory of ours under one of Asterisk's.
	 *
	 * @param string $setting  FreePBX config key naming Asterisk's directory.
	 * @param string $fallback Where that is on a site that does not say.
	 * @param string $name     Our directory inside it.
	 *
	 * @return string Absolute path, without a trailing slash.
	 */
	protected function asteriskPath($setting, $fallback, $name)
	{
		$root = trim((string) $this->FreePBX->Config->get($setting));

		return ($root !== '' ? rtrim($root, '/') : $fallback) . '/' . $name;
	}

	/**
	 * Make a directory if it is not there, and say whether it can be written.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return bool True when it exists and is writable.
	 */
	protected function ensureDirectory($path)
	{
		$path = (string) $path;

		if ($path === '') {
			return false;
		}

		if (!is_dir($path)) {
			if (!@mkdir($path, 0750, true) && !is_dir($path)) {
				$this->log(sprintf('oryk_provisioner: could not create %s', $path), null, 'WARNING');

				return false;
			}

			// Only for a directory this just made, and neither is fatal: one somebody
			// else made with workable permissions is workable, and by a write it is far
			// too late to be asking.
			@chown($path, (string) $this->FreePBX->Config->get('AMPASTERISKWEBUSER'));
			@chgrp($path, (string) $this->FreePBX->Config->get('AMPASTERISKWEBGROUP'));
		}

		return is_writable($path);
	}
}
