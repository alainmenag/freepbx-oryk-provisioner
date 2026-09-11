<?php

// src/FileRepo.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Where an uploaded resource file is kept.
 *
 * ASTSPOOLDIR/repo, one file per resource id. A store and nothing more:
 * it knows the path, makes the directory and removes a file. What a
 * resource *is* -- template or uploaded file -- is the resource's
 * business, in Resources.
 */
class FileRepo extends Repo
{
	/**
	 * The directory uploaded resource files are kept in.
	 *
	 * Under the Asterisk spool rather than anywhere beneath the web root:
	 * these are handed out by the endpoint reading them, never by Apache
	 * finding them, and a firmware image has no business being reachable at
	 * its path on disk as well as by the name a phone asks for.
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
	 * Named after the resource id and nothing else -- not the filename it is
	 * served under, which can be renamed and repeats across profiles, and
	 * nothing the uploader sent. An integer cast is a path that cannot climb
	 * out of the directory it is in, and it means a file can still be found
	 * and removed by id alone when the row it belonged to is being deleted.
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
	 * Called at install and again before every upload, because the spool is
	 * not a place the module is the only writer of and a directory that was
	 * there in the morning may not be by the afternoon.
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
	 * A file that is not there is not a failure: it is the state this was
	 * asked to reach.
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
