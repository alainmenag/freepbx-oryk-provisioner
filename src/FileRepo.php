<?php

// src/FileRepo.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Where an uploaded resource file is kept.
 *
 * ASTSPOOLDIR/repo, one file per resource id. A store and nothing more: the
 * path, the directory, and removing a file. What a resource *is* -- template
 * or uploaded file -- is Resources' business.
 */
class FileRepo extends Repo
{
	/**
	 * The directory uploaded resource files are kept in.
	 *
	 * Under the Asterisk spool rather than beneath the web root: these are handed
	 * out by the endpoint reading them, never by Apache finding them.
	 *
	 * @return string Absolute path, without a trailing slash.
	 */
	public function repoPath()
	{
		return $this->asteriskPath('ASTSPOOLDIR', '/var/spool/asterisk', 'repo');
	}

	/**
	 * Where one resource's uploaded file is kept.
	 *
	 * Named after the resource id and nothing else -- not the filename it is served
	 * under, which can be renamed and repeats across profiles. The integer cast is
	 * a path that cannot climb out of its directory, and it means a file can still
	 * be found and removed when the row it belonged to is going.
	 *
	 * @param mixed $id Resource id.
	 *
	 * @return string Absolute path to the file, whether or not it is there.
	 */
	public function repoFile($id)
	{
		return $this->repoPath() . '/' . (int) $id;
	}

	/**
	 * Make the repository directory if it is not there.
	 *
	 * At install and again before every upload: the spool is not a place this
	 * module is the only writer of.
	 *
	 * @return bool True when the directory exists and is writable.
	 */
	public function ensureRepo()
	{
		return $this->ensureDirectory($this->repoPath());
	}

	/**
	 * Remove one resource's uploaded file.
	 *
	 * A file that is not there is not a failure: it is the state this was asked to
	 * reach.
	 *
	 * @param mixed $id Resource id.
	 *
	 * @return bool True when nothing is left at that path.
	 */
	public function removeRepoFile($id)
	{
		$path = $this->repoFile($id);

		if (is_file($path) && !@unlink($path)) {
			$this->log(sprintf('oryk_provisioner: could not remove %s', $path), null, 'WARNING');

			return false;
		}

		return true;
	}
}
