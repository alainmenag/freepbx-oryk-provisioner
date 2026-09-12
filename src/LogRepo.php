<?php

// src/LogRepo.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Where a log a phone sent us is kept.
 *
 * ASTLOGDIR/provisioner/[client id]/[filename]. The counterpart of FileRepo
 * and deliberately not the same directory: a file in the repo is something an
 * operator uploaded and the endpoint hands out, and one here is something a
 * phone uploaded and nothing hands out at all.
 *
 * **A directory per client, named by the client's id and not its MAC.** A log
 * resource is `-boot.log` for every client on its profile, so what tells two
 * stored logs apart has to come from the request rather than the resource --
 * and the module saying whose log this is beats the phone happening to mention
 * it, so a vendor that names its uploads something fixed cannot overwrite the
 * fleet. The id cannot be edited and cannot be reused, so a corrected MAC does
 * not strand what a phone has already sent, and deleteClient() can prune by id
 * alone.
 *
 * The filename inside it is the resource's own name rendered against that
 * client, which the endpoint works out and hands over.
 */
class LogRepo extends Repo
{
	/**
	 * The directory logs a phone sent are kept in.
	 *
	 * Under the Asterisk log directory, because that is what these are: something
	 * to read when a phone is misbehaving, alongside everything else on the box
	 * read for the same reason. This is the root; every stored log is a directory
	 * further down, under the id of the client that sent it.
	 *
	 * @return string Absolute path, without a trailing slash.
	 */
	public function logPath()
	{
		return $this->asteriskPath('ASTLOGDIR', '/var/log/asterisk', 'provisioner');
	}

	/**
	 * Where one client's logs are kept.
	 *
	 * The id is cast here rather than trusted from the caller: this is the one
	 * value in the module that becomes a directory name, and an int is either a
	 * positive number or it is nothing a path can be built out of.
	 *
	 * @param mixed $client Client id.
	 *
	 * @return string Absolute path, or '' when that is not a client id.
	 */
	public function clientPath($client)
	{
		$id = (int) $client;

		return $id > 0 ? $this->logPath() . '/' . $id : '';
	}

	/**
	 * Make the root log directory if it is not there.
	 *
	 * The root only, and only for install() -- a client's own directory is made by
	 * the PUT that first needs it. A client that has never sent anything having no
	 * directory is truer than an empty one per client on the site.
	 *
	 * @return bool True when the directory exists and is writable.
	 */
	public function ensureLogs()
	{
		return $this->ensureDirectory($this->logPath());
	}

	/**
	 * Where one client's log for one resource is kept.
	 *
	 * FileRepo::repoFile() for the other direction, and the same job: the one
	 * place that turns what a resource is into where it is on disk, so the side
	 * that writes it and the side that reads it back cannot disagree. A file in
	 * the repo is found by the resource's id; one here by the client's id and the
	 * resource's rendered name, because that is what a log is.
	 *
	 * @param mixed  $client   Id of the client whose log it is.
	 * @param string $filename Rendered resource name.
	 *
	 * @return string Absolute path, or '' when either half is unusable.
	 */
	public function logFile($client, $filename)
	{
		$directory = $this->clientPath($client);
		$name = $this->safeName($filename);

		return ($directory === '' || $name === '') ? '' : $directory . '/' . $name;
	}

	/**
	 * Store what a phone PUT, under the name its resource renders to.
	 *
	 * Streamed rather than read into a string, for the reason Endpoint::sendFile()
	 * gives. Overwritten rather than appended: a phone PUTs the whole of its log
	 * each time, so appending would store the same lines over and over and grow a
	 * file nothing prunes.
	 *
	 * The path is logFile()'s answer, handed in rather than worked out again here
	 * -- building it twice is two chances to build it differently.
	 *
	 * @param string $path   Absolute path, from logFile().
	 * @param string $source Stream to read the body from.
	 *
	 * @return array<string, mixed> Status, the path and byte count, or why not.
	 */
	public function storeLog($path, $source = 'php://input')
	{
		$path = (string) $path;

		// logFile() answers '' for an id that is not one and for a name that
		// renders to nothing a file can be called. Reaching here without a path is
		// a caller that has invented one.
		if ($path === '') {
			return ['status' => false, 'message' => _('That is not somewhere a log can be stored.')];
		}

		// The client's own directory, made by the first PUT that needs it.
		if (!$this->ensureDirectory(dirname($path))) {
			return [
				'status' => false,
				'message' => sprintf(_('%s cannot be written to.'), dirname($path)),
			];
		}

		$in = @fopen($source, 'rb');
		$out = $in === false ? false : @fopen($path, 'wb');

		if ($in === false || $out === false) {
			if (is_resource($in)) {
				fclose($in);
			}

			$this->log(sprintf('oryk_provisioner: could not write %s', $path), null, 'WARNING');

			return ['status' => false, 'message' => sprintf(_('%s could not be written.'), basename($path))];
		}

		$bytes = (int) stream_copy_to_stream($in, $out);

		fclose($in);
		fclose($out);

		@chmod($path, 0640);

		return ['status' => true, 'path' => $path, 'bytes' => $bytes, 'name' => basename($path)];
	}

	/**
	 * Remove everything one client has ever sent, and the directory itself.
	 *
	 * FileRepo::removeRepoFile() for the other direction: what is stored for a row
	 * goes when the row does, so the filesystem never holds something nothing can
	 * account for.
	 *
	 * **What makes deleting a directory safe here is that the path is never
	 * given.** It is built by clientPath(), which answers either '' or logPath()
	 * joined to a positive integer, so no argument to this method reaches a
	 * directory outside the log root.
	 *
	 * One level deep, deliberately: everything written here is a file, so a
	 * subdirectory is somebody else's and rmdir() refusing over it is right.
	 *
	 * A directory that is not there is not a failure -- it is the state this was
	 * asked to reach, and the common one.
	 *
	 * @param mixed $client Id of the client whose logs these are.
	 *
	 * @return bool True when nothing is left at that path.
	 */
	public function removeClientLogs($client)
	{
		$path = $this->clientPath($client);

		if ($path === '' || !is_dir($path)) {
			return true;
		}

		foreach ((array) @scandir($path) as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}

			$file = $path . '/' . $entry;

			if (is_file($file) || is_link($file)) {
				@unlink($file);
			}
		}

		if (!@rmdir($path)) {
			$this->log(sprintf('oryk_provisioner: could not remove %s', $path), null, 'WARNING');

			return false;
		}

		return true;
	}

	/**
	 * A filename off the wire, as something safe to write to disk.
	 *
	 * The name is a resource name an administrator typed and saveResource()
	 * validated -- so it has no slash already -- but it has been through
	 * renderTemplate() since, against values read from the FreePBX device. So a
	 * rendered name is reduced to a basename and then to the characters a filename
	 * may have, rather than checked for the shapes that would be dangerous: a list
	 * of what is allowed cannot be incomplete in the direction that matters.
	 *
	 * Nothing else is tidied. A leading dot or dash survives, because `.cfg` is
	 * how this module spells the main config and a resource called `-boot.log` is
	 * a file called `-boot.log`.
	 *
	 * A name that is nothing but dots and separators comes back empty and is
	 * refused by the caller.
	 *
	 * @param string $filename Name to store under, as it rendered.
	 *
	 * @return string Something safe to write, or '' when there is nothing left.
	 */
	private function safeName($filename)
	{
		$name = basename(trim((string) $filename));
		$name = (string) preg_replace('/[^A-Za-z0-9._:-]+/', '_', $name);

		if (trim($name, '._:-') === '') {
			return '';
		}

		// The column that records this in the provisioning log is 255, and a
		// filesystem is not much more generous.
		return mb_strlen($name) > 200 ? mb_substr($name, 0, 200) : $name;
	}
}
