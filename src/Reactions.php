<?php

// src/Reactions.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The module's own reaction to a user being granted or losing one of its
 * services: the first handler of every step, before any hooked module.
 *
 * Only the module's own services (Services::DEFAULTS) have one, and only the
 * ones FreePBX has a setting for; the rest -- the packs, Support, Guest User,
 * Lobby User -- are events for other modules alone. A guest's or lobby
 * user's context is changed in Extensions, never here.
 *
 * Every reaction sets a state rather than flipping one, so running it twice
 * is running it once: a retry calls it again. None reloads; one that
 * changes what Apply Config writes raises it.
 */
class Reactions extends Service
{
	/** Each service with a reaction, and the method it is. */
	const HANDLERS = [
		'voicemail' => 'voicemail',
		'call-recording' => 'callRecording',
		'on-demand-recording' => 'onDemandRecording',
		'find-me-follow' => 'findMeFollow',
	];

	/** The four calls Call Recording covers, as Core keys them under AMPUSER/<ext>/recording/. */
	const RECORDED = ['in/external', 'out/external', 'in/internal', 'out/internal'];

	/** @var ExtensionManager */
	private $extensions;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, ExtensionManager $extensions)
	{
		parent::__construct($freepbx);

		$this->extensions = $extensions;
	}

	/**
	 * Whether a service has a reaction.
	 *
	 * @param string $slug The service.
	 *
	 * @return bool True when handle() does something for it.
	 */
	public static function handles($slug)
	{
		return isset(self::HANDLERS[(string) $slug]);
	}

	/**
	 * React to one step, if its service has a reaction.
	 *
	 * @param string               $event   granted|revoked.
	 * @param array<string, mixed> $payload ServiceEngine's event: extension, service.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException When it cannot be done: the step fails, and is retried.
	 */
	public function handle($event, array $payload)
	{
		$method = self::HANDLERS[(string) ($payload['service'] ?? '')] ?? null;

		if ($method !== null) {
			$this->$method($event === 'granted', (string) $payload['extension']);
		}
	}

	/**
	 * Voicemail: a mailbox. Granted, one is made (in `default`, with a random
	 * PIN) where there is none; revoked, it comes out of voicemail.conf and
	 * the extension is `novm`. **The messages on disk are kept**, and a mailbox
	 * granted again finds them.
	 *
	 * @param bool   $on        Granted.
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	private function voicemail($on, $extension)
	{
		$this->requireModule('voicemail', _('Voicemail'));

		$vm = \FreePBX::Voicemail();
		$box = $vm->getMailbox($extension, false);
		$context = is_array($box) && !empty($box['vmcontext']) ? (string) $box['vmcontext'] : '';

		if ($on) {
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
		} else {
			if ($context !== '') {
				$vm->delMailbox($extension, false);
			}

			$this->extensions->setVoicemailContext($extension, 'novm');
		}

		self::pending();
	}

	/**
	 * Call Recording: every call to and from the user recorded (`force`), or
	 * back to Core's default (`dontcare`). Asterisk reads these as calls are
	 * made, so nothing is applied.
	 *
	 * @param bool   $on        Granted.
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	private function callRecording($on, $extension)
	{
		foreach (self::RECORDED as $key) {
			$this->astdbPut($extension . '/recording/' . $key, $on ? 'force' : 'dontcare');
		}
	}

	/**
	 * On Demand Recording: the one-touch recording feature, enabled or disabled.
	 *
	 * @param bool   $on        Granted.
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	private function onDemandRecording($on, $extension)
	{
		$this->astdbPut($extension . '/recording/ondemand', $on ? 'enabled' : 'disabled');
	}

	/**
	 * Find Me/Follow Me: switched on, made with Find Me/Follow Me's own
	 * defaults where the user has none; switched off, its list and settings kept.
	 *
	 * @param bool   $on        Granted.
	 * @param string $extension The user.
	 *
	 * @return void
	 */
	private function findMeFollow($on, $extension)
	{
		$this->requireModule('findmefollow', _('Find Me/Follow Me'));
		$this->requireAstman();

		$fm = \FreePBX::Findmefollow();
		$existing = $fm->get($extension);

		if (!empty($existing)) {
			$fm->setDDial($extension, $on);

			return;
		}

		if ($on) {
			// add() calls findmefollow_allusers(), from the module's functions.inc.
			$this->FreePBX->Modules->loadFunctionsInc('findmefollow');
			$fm->add($extension, ['ddial' => '']);
			self::pending();
		}
	}

	/**
	 * Write one AMPUSER key.
	 *
	 * @param string $key   Under AMPUSER/.
	 * @param string $value What it holds.
	 *
	 * @return void
	 */
	private function astdbPut($key, $value)
	{
		$this->requireAstman();
		$this->astman->database_put('AMPUSER', $key, $value);
	}

	/**
	 * Refuse a reaction when its module is missing: the step fails and says so.
	 *
	 * @param string $module Rawname.
	 * @param string $label  What it is called, for the message.
	 *
	 * @return void
	 */
	private function requireModule($module, $label)
	{
		if (!$this->moduleActive($module)) {
			throw new \RuntimeException(sprintf(_('the %s module is not installed and enabled'), $label));
		}
	}

	/**
	 * Refuse a reaction while Asterisk cannot be reached.
	 *
	 * @return void
	 */
	private function requireAstman()
	{
		if (!$this->astmanReady()) {
			throw new \RuntimeException(_('Asterisk is not reachable'));
		}
	}

	/**
	 * Raise Apply Config. Nothing here reloads.
	 *
	 * @return void
	 */
	private static function pending()
	{
		if (function_exists('needreload')) {
			needreload();
		}
	}
}
