<?php

// src/LogRepo.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Where a log a phone sent us is kept.
 *
 * ASTLOGDIR/provisioner/[mac]/[filename]. The counterpart of FileRepo
 * and deliberately not the same directory: a file in the repo is
 * something an operator uploaded and the endpoint hands out, and one
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
 * `ls` on one MAC is everything one phone has ever sent.
 *
 * The filename inside it is the resource's own name, rendered against
 * that client, which the endpoint works out and hands over. What the
 * phone PUT to is addressing -- how the request found its way here --
 * and it carries whatever the vendor decided to put in front of the
 * name. What is stored is the file, and the file is the resource.
 */
class LogRepo extends Service
{
	/**
	 * The directory logs a phone sent are kept in.
	 *
	 * Under the Asterisk log directory, because that is what these are:
	 * something to go and read when a phone is misbehaving, alongside
	 * everything else on the box somebody reads for the same reason.
	 *
	 * This is the root; every stored log is a directory further down, under
	 * the MAC of the client that sent it.
	 *
	 * @return string Absolute path, without a trailing slash.
	 */
	public function logPath()
	{
		$logs = trim((string) $this->FreePBX->Config->get('ASTLOGDIR'));

		return ($logs !== '' ? rtrim($logs, '/') : '/var/log/asterisk') . '/provisioner';
	}

	/**
	 * Where one client's logs are kept.
	 *
	 * Normalised here rather than trusted from the caller, though the caller
	 * has already normalised it: this is the one value in the module that
	 * becomes a directory name, and Mac::normalize() answers with twelve
	 * lowercase hex characters or with nothing at all. There is no third
	 * answer for a path to be built out of.
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
	 * Make a log directory if it is not there.
	 *
	 * Called at install for the root, and again before every PUT for the one
	 * client's, for the reason FileRepo::ensureRepo() is: the log directory
	 * is not a place this module is the only writer of, and one that was
	 * there in the morning may not be by the afternoon.
	 *
	 * A client's directory is made on its first upload rather than when the
	 * client is written. A client that has never sent anything has no
	 * directory, which is a truer thing for the filesystem to say than an
	 * empty one per MAC on the site -- and a client written before this
	 * release would not have got one anyway.
	 *
	 * @param mixed $mac MAC to make the directory for, or '' for the root.
	 *
	 * @return bool True when the directory exists and is writable.
	 */
	public function ensureLogs($mac = '')
	{
		$path = $this->logPath();

		if (!$this->makeDirectory($path)) {
			return false;
		}

		if (trim((string) $mac) === '') {
			return is_writable($path);
		}

		$client = $this->clientPath($mac);

		return $client !== '' && $this->makeDirectory($client) && is_writable($client);
	}

	/**
	 * Make one directory, owned by the user that writes to it.
	 *
	 * @param string $path Absolute path.
	 *
	 * @return bool True when it is there afterwards.
	 */
	private function makeDirectory($path)
	{
		if (is_dir($path)) {
			return true;
		}

		if (!@mkdir($path, 0750, true) && !is_dir($path)) {
			$this->log(sprintf('oryk_provisioner: could not create %s', $path), null, 'WARNING');

			return false;
		}

		// The web user writes here -- this is the endpoint, running under
		// Apache -- and Asterisk owns the rest of the directory. Neither is
		// fatal, for the reason ensureRepo() gives: a directory somebody else
		// made with workable permissions is workable.
		@chown($path, (string) $this->FreePBX->Config->get('AMPASTERISKWEBUSER'));
		@chgrp($path, (string) $this->FreePBX->Config->get('AMPASTERISKWEBGROUP'));

		return true;
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
	 * @param mixed  $mac      MAC of the client that sent it.
	 * @param string $filename Name to store it under -- the rendered resource name.
	 * @param string $source   Stream to read the body from.
	 *
	 * @return array<string, mixed> Status, the path and byte count, or why not.
	 */
	public function storeLog($mac, $filename, $source = 'php://input')
	{
		$directory = $this->clientPath($mac);

		// The endpoint refuses a PUT without a client long before this, so
		// reaching here without a MAC is a caller that has invented one.
		if ($directory === '') {
			return ['status' => false, 'message' => _('A log has to belong to a client.')];
		}

		$name = $this->safeName($filename);

		if ($name === '') {
			return ['status' => false, 'message' => _('That is not a name a log can be stored under.')];
		}

		if (!$this->ensureLogs($mac)) {
			return [
				'status' => false,
				'message' => sprintf(_('%s cannot be written to.'), $directory),
			];
		}

		$path = $directory . '/' . $name;

		$in = @fopen($source, 'rb');
		$out = $in === false ? false : @fopen($path, 'wb');

		if ($in === false || $out === false) {
			if (is_resource($in)) {
				fclose($in);
			}

			$this->log(sprintf('oryk_provisioner: could not write %s', $path), null, 'WARNING');

			return ['status' => false, 'message' => sprintf(_('%s could not be written.'), $name)];
		}

		$bytes = (int) stream_copy_to_stream($in, $out);

		fclose($in);
		fclose($out);

		@chmod($path, 0640);

		return ['status' => true, 'path' => $path, 'bytes' => $bytes, 'name' => $name];
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
