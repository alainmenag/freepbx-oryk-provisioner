<?php

// services/src/Extensions.php

namespace FreePBX\Modules\Oryk_Services;

/**
 * What the services' own jobs write to a FreePBX extension that FreePBX has no
 * call for.
 */
class Extensions extends Component
{
	/**
	 * Point an extension's voicemail at a context, in the users table and astdb.
	 *
	 * @param int|string $extension Extension to write.
	 * @param string     $context   Voicemail context the mailbox is in.
	 *
	 * @return bool True when the row was written.
	 */
	public function setVoicemailContext($extension, $context)
	{
		$sth = $this->db->prepare('UPDATE users SET voicemail = ? WHERE extension = ?');
		$sth->execute([$context, $extension]);

		if ($this->astmanReady()) {
			$this->astman->database_put('AMPUSER', $extension . '/voicemail', $context);
		}

		return true;
	}
}
