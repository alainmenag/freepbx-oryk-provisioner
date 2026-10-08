<?php

// src/Jobs/Voicemail.php

namespace FreePBX\Modules\Oryk_Provisioner\Jobs;

/**
 * Voicemail: the user's mailbox.
 *
 * Granted, one is made in `default` with a random PIN where there is none,
 * and the extension points at it. Revoked, it comes out of voicemail.conf and
 * the extension is `novm`. **The messages on disk are kept**: a mailbox
 * granted again finds them.
 */
class Voicemail extends Job
{
	const SERVICES = ['voicemail'];

	/**
	 * Make the mailbox, or point the extension back at the one it has.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function granted(array $event)
	{
		$extension = (string) $event['extension'];
		$vm = $this->voicemail();
		$context = $this->context($vm, $extension);

		if ($context === '') {
			$user = \FreePBX::Core()->getUser($extension);
			$context = 'default';

			$vm->addMailbox($extension, [
				'vm' => 'enabled',
				'vmcontext' => $context,
				'name' => is_array($user) ? (string) ($user['name'] ?? '') : '',
				'vmpwd' => (string) random_int(100000, 999999),
				'email' => '',
				'passlogin' => 'passlogin=no',
				'attach' => 'attach=no',
				'envelope' => 'envelope=no',
				'vmdelete' => 'vmdelete=no',
				'saycid' => 'saycid=no',
			], false);
		}

		$this->extensions->setVoicemailContext($extension, $context);
		self::pending();
	}

	/**
	 * Take the mailbox out of voicemail.conf, keeping its messages.
	 *
	 * @param array<string, mixed> $event The step's event.
	 *
	 * @return void
	 */
	public function revoked(array $event)
	{
		$extension = (string) $event['extension'];
		$vm = $this->voicemail();

		if ($this->context($vm, $extension) !== '') {
			$vm->delMailbox($extension, false);
		}

		$this->extensions->setVoicemailContext($extension, 'novm');
		self::pending();
	}

	/**
	 * FreePBX's Voicemail module, or a refusal.
	 *
	 * @return object The Voicemail BMO.
	 */
	private function voicemail()
	{
		$this->requireModule('voicemail', _('Voicemail'));

		return \FreePBX::Voicemail();
	}

	/**
	 * The context an extension's mailbox is in, read fresh from voicemail.conf.
	 *
	 * @param object $vm        The Voicemail BMO.
	 * @param string $extension The user.
	 *
	 * @return string The context, or '' when it has no mailbox.
	 */
	private function context($vm, $extension)
	{
		$box = $vm->getMailbox($extension, false);

		return is_array($box) && !empty($box['vmcontext']) ? (string) $box['vmcontext'] : '';
	}
}
