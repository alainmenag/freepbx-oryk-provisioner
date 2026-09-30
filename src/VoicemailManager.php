<?php

// src/VoicemailManager.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The mailbox side of an extension.
 *
 * FreePBX keeps mailboxes in voicemail.conf rather than in the database,
 * and reaches them through an alias keyed on the number rather than
 * directly, so a number that moves has three separate things to carry with
 * it: the entry in the configuration file, the messages on disk, and the
 * alias that makes message waiting and the direct dial code work. Getting
 * one of the three wrong is not visible until somebody rings the extension.
 *
 * This also answers what a mailbox is dialled as, which the call history
 * needs: the pseudo extensions Core puts in the dialplan and the feature
 * code that reaches a mailbox directly all carry the number.
 */
class VoicemailManager extends Service
{
	/**
	 * Move a mailbox from one extension to another.
	 *
	 * Voicemail boxes live in voicemail.conf rather than the database, so the
	 * entry is rewritten under the new number and the messages on disk are
	 * moved with it. Extensions without a mailbox are skipped.
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
	 * A FreePBX mailbox is not reached directly. The device asks for
	 * `<id>@device` and an alias maps that onto the real
	 * `<mailbox>@<context>`. On Asterisk 16.2 and later the alias is a
	 * [pbxaliases] section that saveVoicemail() builds from the voicemail
	 * module's own key/value store; before that it was a symlink under
	 * voicemail/device. Both are keyed on the number, so a mailbox that
	 * moves without its alias is a mailbox nothing points at: no message
	 * waiting indicator, and *97 answering on an empty box.
	 *
	 * Nothing is saved here. The caller writes voicemail.conf out once the
	 * mailbox and its alias have both been moved.
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
	 * Keep the extension's voicemail email in step with the device email.
	 *
	 * Voicemail addresses live in voicemail.conf rather than the database, so
	 * this edits the mailbox in place the same way the voicemail module does
	 * and leaves the password, greeting name, pager and options untouched.
	 * Extensions without a mailbox are skipped.
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
	 * Core adds a set of pseudo extensions to the dialplan for a mailbox, and
	 * a prefix dials one directly. The prefix is a feature code and the
	 * feature codes are themselves that prefix and two digits, so on a two
	 * digit extension it collides with them: taking *98 for extension 98
	 * would be taking everybody's voicemail. Short extensions therefore get
	 * the pseudo extensions and nothing else.
	 *
	 * The four pseudo extensions come first, always, in a fixed order, and
	 * the prefixed number last. Callers rewriting one number into another
	 * line the two lists up by position, so nothing here may become
	 * conditional ahead of them.
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
	 * This is the voicemail module's own feature code rather than a setting,
	 * so it is asked for where feature codes live. An administrator can
	 * change it, and can turn it off, in which case no such numbers were ever
	 * put in the dialplan and there is nothing of that shape to find.
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
