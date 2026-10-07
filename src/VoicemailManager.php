<?php

// src/VoicemailManager.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The mailbox side of an extension.
 *
 * A moving number carries three things: the voicemail.conf entry, the
 * messages on disk, and the alias message waiting and direct dial use.
 * Getting one wrong is invisible until somebody rings the extension. Also
 * answers what numbers dial a mailbox, for the call history.
 */
class VoicemailManager extends Service
{
	/**
	 * Move a mailbox from one extension to another.
	 *
	 * Rewrites the voicemail.conf entry and moves the messages on disk.
	 * Extensions without a mailbox are skipped.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return string|false The voicemail context, or false when nothing moved.
	 */
	public function moveMailbox($old, $new)
	{
		if (!$this->moduleActive('voicemail')) {
			return false;
		}

		try {
			$voicemail = \FreePBX::Voicemail();
			$vmconf = $voicemail->getVoicemail();
			$context = null;

			foreach ($vmconf as $name => $boxes) {
				if (is_array($boxes) && isset($boxes[$old]) && is_array($boxes[$old])) {
					$context = $name;

					break;
				}
			}

			if ($context === null) {
				return false; // nothing to move
			}

			// The new number already has a mailbox of its own. Merging two
			// mailboxes is not something to decide here, so the move stops and
			// the caller keeps the messages where they are.
			if (isset($vmconf[$context][$new])) {
				$this->logError('not moving the mailbox from ' . $old . ' to ' . $new . ': ' . $new . ' already has one');

				return false;
			}

			$vmconf[$context][$new] = $vmconf[$context][$old];

			unset($vmconf[$context][$old]);

			// The messages themselves are stored under the old number
			$spool = \FreePBX::Config()->get('ASTSPOOLDIR') . '/voicemail/' . $context;

			if (is_dir($spool . '/' . $old) && !file_exists($spool . '/' . $new)) {
				@rename($spool . '/' . $old, $spool . '/' . $new);
			}

			// A mailbox is reached through an alias keyed on the number rather
			// than directly, so the alias has to move with it
			$this->moveAlias($voicemail, $old, $new, $context, $spool);

			// saveVoicemail() rebuilds the alias section from the key/value
			// store but merges into whatever is already there, so the parsed
			// copy of it goes before the old alias can be written back out
			unset($vmconf['pbxaliases']);

			// Written out once, with the mailbox and its alias both moved
			$voicemail->saveVoicemail($vmconf);
		} catch (\Exception $e) {
			$this->logError('unable to move the mailbox from ' . $old . ' to ' . $new . ': ' . $e->getMessage());

			return false;
		}

		return $context;
	}

	/**
	 * Move the device-to-mailbox alias that follows a mailbox.
	 *
	 * `<id>@device` is aliased to `<mailbox>@<context>`: a [pbxaliases] entry
	 * on Asterisk 16.2+, a symlink under voicemail/device before that. Both are
	 * keyed on the number; a mailbox moved without its alias loses message
	 * waiting and *97 answers an empty box.
	 *
	 * Nothing is saved here; the caller writes voicemail.conf afterwards.
	 *
	 * @param object     $voicemail Voicemail module instance.
	 * @param int|string $old       Number being left behind.
	 * @param int|string $new       Number being moved to.
	 * @param string     $context   Voicemail context holding the mailbox.
	 * @param string     $spool     Spool directory for that context.
	 *
	 * @return bool True when the alias was moved.
	 */
	private function moveAlias($voicemail, $old, $new, $context, $spool)
	{
		try {
			// The alias map, for the Asterisk versions that use one
			$voicemail->delConfig((string) $old, 'vmmapping');
			$voicemail->updateAliasDeviceMapping((string) $new, $new . '@' . $context, false);

			// The symlink, for the ones that do not
			$devices = dirname($spool) . '/device/';

			if (is_link($devices . $old)) {
				@unlink($devices . $old);
			}

			// file_exists() follows the link, so a dangling one reads as absent
			// and would leave the symlink below failing quietly
			if (is_link($devices . $new)) {
				@unlink($devices . $new);
			}

			if (is_dir($devices) && !file_exists($devices . $new)) {
				@symlink($spool . '/' . $new, $devices . $new);
			}
		} catch (\Exception $e) {
			$this->logError('unable to move the voicemail alias from ' . $old . ' to ' . $new . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Report whether an extension has a mailbox.
	 *
	 * Asked before a mailbox is moved so the caller can tell a number that
	 * never had one from a number whose mailbox failed to follow it.
	 *
	 * @param int|string $extension Extension to look at.
	 *
	 * @return bool True when a mailbox is configured for the extension.
	 */
	public function hasMailbox($extension)
	{
		if (!$this->moduleActive('voicemail')) {
			return false;
		}

		try {
			$mailbox = \FreePBX::Voicemail()->getVoicemailBoxByExtension((string) $extension);
		} catch (\Exception $e) {
			return false;
		}

		return !empty($mailbox['vmcontext']);
	}

	/**
	 * Where an extension's mailbox keeps its messages.
	 *
	 * @param int|string $extension Extension to look at.
	 *
	 * @return string The mailbox's spool directory, or '' when the extension
	 *                has no mailbox or either half is not a name a path can
	 *                be built from.
	 */
	public function mailboxPath($extension)
	{
		$extension = trim((string) $extension);

		if (!preg_match('/^[0-9]{1,20}$/', $extension) || !$this->moduleActive('voicemail')) {
			return '';
		}

		try {
			$mailbox = \FreePBX::Voicemail()->getVoicemailBoxByExtension($extension);
			$context = (string) ($mailbox['vmcontext'] ?? '');
			$spool = (string) \FreePBX::Config()->get('ASTSPOOLDIR');
		} catch (\Exception $e) {
			return '';
		}

		return ($spool !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $context))
			? $spool . '/voicemail/' . $context . '/' . $extension
			: '';
	}

	/**
	 * The messages in a mailbox directory, newest first.
	 *
	 * A message is `<folder>/msgNNNN.txt` and the audio beside it; the
	 * greetings sit in the mailbox itself and are not messages. messageFiles()
	 * is the one walk both this and clearIn() make, so what is listed is what
	 * is cleared.
	 *
	 * @param string $path mailboxPath().
	 *
	 * @return array<int, array<string, mixed>> Each: id (`<folder>/msgNNNN`),
	 *                                          folder, callerid, time
	 *                                          (Y-m-d H:i:s, or ''), duration
	 *                                          (seconds).
	 */
	public function messagesIn($path)
	{
		$messages = [];

		foreach ($this->messageFiles($path) as $id => $files) {
			$about = [];

			foreach ((array) @file($files['txt'], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
				$parts = explode('=', (string) $line, 2);

				if (count($parts) === 2) {
					$about[trim($parts[0])] = trim($parts[1]);
				}
			}

			$time = (int) ($about['origtime'] ?? 0);

			$messages[] = [
				'id' => $id,
				'folder' => dirname($id),
				'callerid' => (string) ($about['callerid'] ?? ''),
				'time' => $time > 0 ? date('Y-m-d H:i:s', $time) : '',
				'sort' => $time,
				'duration' => (int) ($about['duration'] ?? 0),
			];
		}

		usort($messages, function ($a, $b) {
			return $b['sort'] <=> $a['sort'];
		});

		return array_map(function ($message) {
			unset($message['sort']);

			return $message;
		}, $messages);
	}

	/**
	 * Delete every message in a mailbox directory, and leave the greetings.
	 *
	 * @param string $path mailboxPath().
	 *
	 * @return int Messages removed.
	 */
	public function clearIn($path)
	{
		$removed = 0;

		foreach ($this->messageFiles($path) as $files) {
			foreach ($files['all'] as $file) {
				@unlink($file);
			}

			if (!file_exists($files['txt'])) {
				$removed++;
			}
		}

		return $removed;
	}

	/**
	 * Delete one message from a mailbox directory, and close the gap it leaves.
	 *
	 * Asterisk numbers a folder's messages from msg0000 without holes, so the
	 * ones after it are renamed down a place. The ids messagesIn() gave out
	 * for that folder are stale afterwards.
	 *
	 * @param string $path mailboxPath().
	 * @param string $id   `<folder>/msgNNNN`, as messagesIn() lists it.
	 *
	 * @return bool True when the message was there and is gone.
	 */
	public function deleteIn($path, $id)
	{
		$id = (string) $id;
		$messages = $this->messageFiles($path);

		// Only an id the walk itself produced: it becomes a path.
		if (!isset($messages[$id])) {
			return false;
		}

		foreach ($messages[$id]['all'] as $file) {
			@unlink($file);
		}

		if (file_exists($messages[$id]['txt'])) {
			return false;
		}

		$folder = dirname($id);
		$next = 0;

		// Sorted by id, so each is moved into a place already vacated.
		ksort($messages);

		foreach ($messages as $other => $files) {
			if ($other === $id || dirname($other) !== $folder) {
				continue;
			}

			$name = sprintf('msg%04d', $next++);

			if (basename($other) === $name) {
				continue;
			}

			foreach ($files['all'] as $file) {
				$target = dirname($file) . '/' . $name . substr(basename($file), 7);

				if (!file_exists($target)) {
					@rename($file, $target);
				}
			}
		}

		return true;
	}

	/**
	 * Every message under a mailbox directory, with the files it is made of.
	 *
	 * One level of folders (INBOX, Old, ...), and in them only `msgNNNN.*`:
	 * nothing else in a mailbox is a message.
	 *
	 * @param string $path mailboxPath().
	 *
	 * @return array<string, array{txt: string, all: array<int, string>}> By
	 *         `<folder>/msgNNNN`, for the messages that have a `.txt`.
	 */
	private function messageFiles($path)
	{
		$found = [];

		if ($path === '' || !is_dir($path)) {
			return $found;
		}

		foreach ((array) @scandir($path) as $folder) {
			if ($folder === '.' || $folder === '..' || !is_dir($path . '/' . $folder) || is_link($path . '/' . $folder)) {
				continue;
			}

			foreach ((array) @scandir($path . '/' . $folder) as $entry) {
				if (preg_match('/^(msg[0-9]{4})\.[A-Za-z0-9]+$/', (string) $entry, $m) && is_file($path . '/' . $folder . '/' . $entry)) {
					$found[$folder . '/' . $m[1]]['all'][] = $path . '/' . $folder . '/' . $entry;
				}
			}
		}

		foreach ($found as $id => $files) {
			$txt = $path . '/' . $id . '.txt';

			if (in_array($txt, $files['all'], true)) {
				$found[$id]['txt'] = $txt;
			} else {
				unset($found[$id]);
			}
		}

		return $found;
	}

	/**
	 * Keep the extension's voicemail email in step with the device email.
	 *
	 * Edits the mailbox in place, leaving password, name, pager and options
	 * untouched. Extensions without a mailbox are skipped.
	 *
	 * @param int|string  $extension Extension/user number.
	 * @param string|null $email     Email to store, null to leave it alone.
	 *
	 * @return bool True when the mailbox was updated.
	 */
	public function syncEmail($extension, $email)
	{
		if ($email === null || !$this->moduleActive('voicemail')) {
			return false;
		}

		try {
			$voicemail = \FreePBX::Voicemail();
			$mailbox = $voicemail->getVoicemailBoxByExtension($extension);

			if (empty($mailbox['vmcontext'])) {
				return false; // no mailbox for this extension
			}

			$context = $mailbox['vmcontext'];
			$vmconf = $voicemail->getVoicemail();

			if (empty($vmconf[$context][$extension])) {
				return false;
			}

			// saveVoicemail() turns commas into the '|' separator on the way out
			if ((string) ($mailbox['email'] ?? '') === (string) $email) {
				return true;
			}

			$vmconf[$context][$extension]['email'] = $email;

			$voicemail->saveVoicemail($vmconf);
		} catch (\Exception $e) {
			$this->logError('unable to update voicemail email for ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * The numbers that reach an extension's mailbox rather than the extension.
	 *
	 * The four pseudo extensions Core adds, then the direct dial prefix plus
	 * the number. An extension under three digits gets no prefixed number:
	 * *98 would collide with the feature codes. Callers line lists up by
	 * position, so the pseudo extensions must stay first, unconditional,
	 * in fixed order.
	 *
	 * @param int|string $extension Extension whose mailbox is wanted.
	 *
	 * @return array<int, string> Numbers that reach the mailbox.
	 */
	public function dialableNumbers($extension)
	{
		$dialled = [];

		foreach (['vmu', 'vmb', 'vms', 'vmi'] as $prefix) {
			$dialled[] = $prefix . $extension;
		}

		if (strlen((string) $extension) < 3) {
			return $dialled;
		}

		$prefix = $this->directDialPrefix();

		if ($prefix !== '') {
			$dialled[] = $prefix . $extension;
		}

		return $dialled;
	}

	/**
	 * The prefix that dials a mailbox directly.
	 *
	 * The voicemail module's feature code, which an administrator can change
	 * or disable.
	 *
	 * @return string The prefix, or an empty string when there is none.
	 */
	private function directDialPrefix()
	{
		try {
			if (!class_exists('featurecode')) {
				$this->FreePBX->Modules->loadFunctionsInc('featurecodes');
			}

			if (!class_exists('featurecode')) {
				return '*'; // the module's own default
			}

			// Asked of the feature code itself rather than through the
			// convenience function, which answers a disabled code with a
			// human readable complaint rather than with nothing
			$code = new \featurecode('voicemail', 'directdialvoicemail');

			// Empty when the administrator has turned the code off, in which
			// case no numbers of that shape were ever put in the dialplan
			return (string) $code->getCodeActive();
		} catch (\Throwable $e) {
			return '*';
		}
	}
}
