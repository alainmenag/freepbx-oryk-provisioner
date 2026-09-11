<?php

// src/LogRepo.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Where a log a phone sent us is kept.
 *
 * ASTLOGDIR/provisioner/[mac]/[filename]. The counterpart of FileRepo and
 * deliberately not the same directory: a file in the repo is something an
 * operator uploaded and the endpoint hands out, one here is something a phone
 * uploaded and nothing hands out at all.
 *
 * A directory per client, which is the one decision here worth stating. A log
 * resource is `-boot.log` for every client on its profile, so what makes one
 * stored log different from another has to come from the request rather than
 * the resource -- and a directory is the module saying whose log this is rather
 * than the phone happening to mention it, so a vendor that names its uploads
 * something fixed cannot quietly overwrite the fleet.
 */
class LogRepo extends Repo
{
	/**
	 * The directory logs a phone sent are kept in.
	 *
	 * Under the Asterisk log directory, because that is what these are. This is the
	 * root; every stored log is a directory further down, under the MAC that sent it.
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
	 * Normalised here rather than trusted from the caller: this is the one value in
	 * the module that becomes a directory name, and Mac::normalize() answers with
	 * twelve lowercase hex characters or with nothing at all.
	 *
	 * @param mixed $mac MAC address, written however it was written.
	 *
	 * @return string Absolute path, or '' when that is not a MAC.
	 */
	public function clientPath($mac)
	{
		$mac = Mac::normalize($mac);

		return $mac === '' ? '' : $this->logPath() . '/' . $mac;
	}

	/**
	 * Make the root log directory if it is not there.
	 *
	 * The root only, and only for install() -- a client's own directory is made by
	 * the PUT that first needs it, since ensureDirectory() makes parents anyway.
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
	 * FileRepo::repoFile() for the other direction, and the same job: the one place
	 * that turns what a resource is into where it is on disk, so the side that
	 * writes it and the side that reads it back cannot disagree about the path.
	 *
	 * @param mixed  $mac      MAC of the client whose log it is.
	 * @param string $filename Rendered resource name.
	 *
	 * @return string Absolute path, or '' when either half is unusable.
	 */
	public function logFile($mac, $filename)
	{
		$directory = $this->clientPath($mac);
		$name = $this->safeName($filename);

		return ($directory === '' || $name === '') ? '' : $directory . '/' . $name;
	}

	/**
	 * Store what a phone PUT, in its own directory, under the name the
	 * resource it was PUT to renders to.
	 *
	 * Streamed rather than read into a string, as Endpoint::sendFile() is.
	 * Overwritten rather than appended: a phone PUTs the whole of its log each
	 * time, so appending would grow a file nothing prunes.
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

		// logFile() answers '' for a MAC that is not one and for a name that
		// renders to nothing a file can be called, and the endpoint refuses a PUT
		// with no client long before either.
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
	 * goes when the row does.
	 *
	 * **What makes deleting a directory safe here is that the path is never
	 * given.** clientPath() answers either '' or logPath() joined to twelve
	 * lowercase hex characters, so no argument to this method reaches a directory
	 * outside the log root.
	 *
	 * One level deep, deliberately: everything written here is a file, so a
	 * subdirectory is somebody else's and rmdir() refusing over it is right.
	 *
	 * @param mixed $mac MAC of the client whose logs these are.
	 *
	 * @return bool True when nothing is left at that path.
	 */
	public function removeClientLogs($mac)
	{
		$path = $this->clientPath($mac);

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
	 * This one is a resource name saveResource() validated, but it has been through
	 * renderTemplate() since, against values read from the FreePBX device -- and a
	 * device description is not a filename. So it is reduced to a basename and then
	 * to the characters a filename may have, rather than checked for the shapes
	 * that would be dangerous: a list of what is allowed cannot be incomplete in
	 * the direction that matters.
	 *
	 * Nothing else is tidied. A leading dot or dash survives, because `.cfg` is how
	 * this module spells the main config and a log resource called `-boot.log` is a
	 * file called `-boot.log`. A name that is nothing but dots and separators comes
	 * back empty and is refused by the caller.
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
