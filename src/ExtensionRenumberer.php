<?php

// src/ExtensionRenumberer.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Moving an Extension/User device to a different number.
 *
 * A number is an extension, a User Manager account, a mailbox, UCP access,
 * custom endpoint settings, provisioner clients and call history; nothing in
 * FreePBX moves them together. This class owns none of them, only the order
 * they move in, and that order is load-bearing: the new extension exists
 * before the old is given up, the mailbox moves before the old extension is
 * deleted, and clients are repointed only once the old device is gone. See
 * ARCHITECTURE.md, "Users".
 */
class ExtensionRenumberer extends Service
{
	/**
	 * The Core extension and the state Asterisk reads about it.
	 *
	 * @var ExtensionManager
	 */
	private $extensions;

	/**
	 * The mailbox and the alias that reaches it.
	 *
	 * @var VoicemailManager
	 */
	private $voicemail;

	/**
	 * The User Manager account, when this module owns one.
	 *
	 * @var UsermanManager
	 */
	private $userman;

	/**
	 * What a UCP account is allowed to open.
	 *
	 * @var UcpAssignments
	 */
	private $ucp;

	/**
	 * Every call placed to or from the number.
	 *
	 * @var CdrHistory
	 */
	private $cdr;

	/**
	 * The pjsip settings pinned on the endpoint outside what FreePBX
	 * generates.
	 *
	 * @var EndpointSettings
	 */
	private $endpoints;

	/**
	 * Provisioner clients, which name their device by id.
	 *
	 * @var Clients|null
	 */
	private $clients;

	/** @var Services|null The services assigned to a user, by its extension. */
	private $services;

	/**
	 * @param object            $freepbx    FreePBX application instance.
	 * @param ExtensionManager  $extensions Core extensions.
	 * @param VoicemailManager  $voicemail  Mailboxes.
	 * @param UsermanManager    $userman    User Manager accounts.
	 * @param UcpAssignments    $ucp        UCP assignments.
	 * @param CdrHistory        $cdr        Call history.
	 * @param EndpointSettings  $endpoints  Custom pjsip endpoint settings.
	 * @param Clients|null      $clients    Provisioner clients to repoint.
	 * @param Services|null     $services   Assigned services to carry over.
	 */
	public function __construct(
		$freepbx,
		ExtensionManager $extensions,
		VoicemailManager $voicemail,
		UsermanManager $userman,
		UcpAssignments $ucp,
		CdrHistory $cdr,
		EndpointSettings $endpoints,
		?Clients $clients = null,
		?Services $services = null
	) {
		parent::__construct($freepbx);

		$this->extensions = $extensions;
		$this->voicemail = $voicemail;
		$this->userman = $userman;
		$this->ucp = $ucp;
		$this->cdr = $cdr;
		$this->endpoints = $endpoints;
		$this->clients = $clients;
		$this->services = $services;
	}

	/**
	 * Move an Extension/User device to a different number.
	 *
	 * The new number must already have passed assertAvailable(); nothing
	 * here checks for a collision. The old number is given up only once the
	 * new extension is in place, so a failure leaves the device where it was.
	 *
	 * @param int|string  $old         Number being left behind.
	 * @param int|string  $new         Number being moved to.
	 * @param string      $displayname Display name for the extension.
	 * @param string      $tech        Device technology.
	 * @param string|null $email       Email address for the account.
	 *
	 * @return bool True when the number was moved.
	 *
	 * @throws \Exception When the extension cannot be recreated on the new number.
	 */
	public function renumber($old, $new, $displayname, $tech = 'pjsip', $email = null)
	{
		$old = trim((string) $old);
		$new = trim((string) $new);

		if ($old === '' || $new === '' || $old === $new) {
			return false;
		}

		$displayname = ($displayname === null || $displayname === '') ? $new : $displayname;

		// Everything the old number carries is read before any of it is removed
		$hadUser = $this->extensions->exists($old);
		$settings = [];

		if ($hadUser) {
			try {
				$settings = \FreePBX::Core()->getUser($old);
			} catch (\Exception $e) {
				$settings = [];
			}
		}

		$account = $this->userman->findByExtension($old);

		// Carry the extension's own settings over when they could be read
		if (!empty($settings['extension'])) {
			$settings['extension'] = $new;
			$settings['name'] = $displayname;
			$settings['device'] = $new;

			// A caller id pinned to the old number would follow the extension
			if ((string) ($settings['cid_masquerade'] ?? '') === $old) {
				$settings['cid_masquerade'] = '';
			}

			try {
				\FreePBX::Core()->addUser($new, $settings);
			} catch (\Exception $e) {
				throw new \Exception(sprintf(
					_('Unable to move extension %s to %s: %s'),
					$old,
					$new,
					$e->getMessage()
				));
			}
		} else {
			$this->extensions->ensure($new, $displayname);
		}

		// addUser() reads the mailbox before it has moved, so the voicemail
		// context is written back once the box is on the new number
		$hadMailbox = $this->voicemail->hasMailbox($old);
		$context = $this->voicemail->moveMailbox($old, $new);

		if ($context) {
			$this->extensions->setVoicemailContext($new, $context);
		}

		// Only now is the old number given up
		try {
			\FreePBX::Core()->delDevice($old);
		} catch (\Exception $e) {
			$this->logError('unable to delete device ' . $old . ': ' . $e->getMessage());
		}

		// The custom endpoint settings go with it. Anything written for this
		// device rather than for every device follows the number across, and
		// the save this renumbering is part of writes the rest.
		$this->endpoints->move($old, $new);

		if ($hadUser) {
			// Voicemail deletes the mailbox behind Core::delUser(), which is
			// right for a number being retired but not for one whose mailbox
			// is still sitting on it because the move did not come off. Edit
			// mode holds that hook back; the astdb keys it also spares are
			// taken out here instead.
			$stranded = $hadMailbox && !$context;

			if ($stranded) {
				$this->logError('the mailbox on ' . $old . ' did not move to ' . $new . ' and has been left where it is');
			}

			try {
				\FreePBX::Core()->delUser($old, $stranded);
			} catch (\Exception $e) {
				$this->logError('unable to delete user ' . $old . ': ' . $e->getMessage());
			}

			if ($stranded) {
				$this->extensions->forgetAstDb($old);
			}
		}

		// After the old extension is gone, so User Manager is not left
		// unassigning the extension it has just been pointed at
		$this->userman->move($account, $old, $new, $displayname, $tech, $email);

		// Handsets and softphones registered against the old extension follow it
		$this->extensions->repointDevices($old, $new);

		// So do the phones this module provisions for it
		if ($this->clients) {
			$this->clients->repointDevice($old, $new);
		}

		// And the services it was assigned, which name it by its extension
		if ($this->services) {
			$this->services->moveUser($old, $new);
		}

		// What the account is allowed to open, before the history it opens
		$this->ucp->move($old, $new);

		// The call history keeps the number as it stood when the call was
		// placed, and no part of FreePBX moves it
		$this->cdr->migrate($old, $new);

		return true;
	}
}
