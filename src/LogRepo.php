<?php

// src/LogRepo.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Where a log a phone sent us is kept.
 *
 * ASTLOGDIR/provisioner/[client id]/[filename]. The counterpart of
 * FileRepo and deliberately not the same directory: a file in the repo
 * is something an operator uploaded and the endpoint hands out, and one
 * here is something a phone uploaded and nothing hands out at all.
 * Different direction, different lifetime, different place.
 *
 * A directory per client, which is the one decision in here worth
 * stating. A log resource is `-boot.log` for every client on its
 * profile, so what makes one stored log different from another has to
 * come from the request rather than from the resource. It could have
 * come from the filename -- a phone writes its own MAC into what it PUTs
 * -- and that is what this did first. The directory is better for the
 * reason it is more work: it is the module saying whose log this is
 * rather than the phone happening to mention it, so a vendor that names
 * its uploads something fixed cannot quietly overwrite the fleet, and
 * `ls` on one directory is everything one phone has ever sent.
 *
 * **The directory is the client's id, not its MAC.** The id is what the
 * client *is*; the MAC is a field on it, and a field can be corrected. A
 * MAC typed wrong and fixed a week later used to leave everything that
 * phone had sent in a directory nothing on the system pointed at any
 * more, and start a second one beside it. The id cannot be edited and
 * cannot be reused, so a client's logs follow the client for as long as
 * there is one -- and stop being anybody's the moment there is not,
 * which is what makes deleteClient() able to prune them by id alone.
 *
 * The filename inside it is the resource's own name, rendered against
 * that client, which the endpoint works out and hands over. What the
 * phone PUT to is addressing -- how the request found its way here --
 * and it carries whatever the vendor decided to put in front of the
 * name. What is stored is the file, and the file is the resource.
 */
class LogRepo extends Repo
{
	/**
	 * The directory logs a phone sent are kept in.
	 *
	 * Under the Asterisk log directory, because that is what these are:
	 * something to go and read when a phone is misbehaving, alongside
	 * everything else on the box somebody reads for the same reason.
	 *
	 * This is the root; every stored log is a directory further down, under
	 * the id of the client that sent it.
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
	 * The id is cast here rather than trusted from the caller, though the
	 * caller has it from a row: this is the one value in the module that
	 * becomes a directory name, and an int is either a positive number or it
	 * is nothing a path can be built out of. There is no third answer.
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
	 * The root only, and only for install() -- a client's own directory is
	 * made by the PUT that first needs it, since ensureDirectory() makes
	 * parents anyway. A client that has never sent anything having no
	 * directory is a truer thing for the filesystem to say than an empty one
	 * per client on the site.
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
	 * place that turns what a resource is into where it is on disk, so the
	 * side that writes it and the side that reads it back cannot disagree
	 * about the path. A file in the repo is found by the resource's id; one
	 * here is found by the client's id and the resource's rendered name,
	 * because that is what a log is -- one phone's copy of one file.
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
	 * Store what a phone PUT, in its own directory, under the name the
	 * resource it was PUT to renders to.
	 *
	 * Streamed rather than read into a string: a boot log is usually a few
	 * kilobytes and occasionally is not, and there is no more reason to
	 * hold one in memory than there is to hold a firmware image on the way
	 * out -- see Endpoint::sendFile().
	 *
	 * Overwritten rather than appended. A phone PUTs the whole of its log
	 * each time, so appending would store the same lines over and over and
	 * grow a file nothing prunes; what is wanted is the log as it stands.
	 *
	 * The path is logFile()'s answer, handed in rather than worked out again
	 * here: the endpoint has already built it to decide there was something
	 * to store, and building it twice is two chances to build it differently.
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
		// renders to nothing a file can be called, and the endpoint refuses a
		// PUT with no client long before either. Reaching here without a path
		// is a caller that has invented one.
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
	 * FileRepo::removeRepoFile() for the other direction: what is stored for
	 * a row goes when the row does, so the filesystem never holds something
	 * nothing on the system can account for. A client's logs are a directory
	 * rather than a file, which is the only reason this is longer than that.
	 *
	 * **What makes deleting a directory safe here is that the path is never
	 * given.** It is built by clientPath(), which answers either '' or
	 * logPath() joined to a positive integer -- so there is no argument to
	 * this method that reaches a directory outside the log root, and no
	 * caller that could pass one.
	 *
	 * One level deep, deliberately. Everything written here is a file, so a
	 * subdirectory is somebody else's; it is not descended into, and rmdir()
	 * refusing over it is the right outcome rather than an obstacle.
	 *
	 * A directory that is not there is not a failure -- it is the state this
	 * was asked to reach, and it is the common one: a client that never sent
	 * anything never had a directory made for it.
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
	 * This one is a resource name an administrator typed and saveResource()
	 * validated -- so it has no slash in it already -- but it has been
	 * through renderTemplate() since, against values read from the FreePBX
	 * device. A device description is not a filename and nobody ever said it
	 * was, so a rendered name is reduced to a basename and then to the
	 * characters a filename may have, rather than checked for the shapes that
	 * would be dangerous: a list of what is allowed cannot be incomplete in
	 * the direction that matters.
	 *
	 * Nothing else is tidied. A leading dot or dash survives, because the
	 * name is the resource's and the resource's name is what the operator
	 * wrote -- `.cfg` is how this module spells the main config, and a log
	 * resource called `-boot.log` is a file called `-boot.log`, awkward at a
	 * shell prompt and not this function's to rename.
	 *
	 * A name that is nothing but dots and separators comes back empty and
	 * is refused by the caller: `..` is the obvious one, and there is no
	 * useful name left in the rest of them either.
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
