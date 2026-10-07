<?php

// src/SecurityLog.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Lines written to FreePBX's security log, which FreePBX's own fail2ban jail
 * watches.
 *
 * **Only an `Authentication failure for <user> from <ip>` line may match that
 * jail.** Every other line written here -- a sign-up, a refused sign-up, a rebuild --
 * is worded so it never does, or a phone that retries would get
 * its whole site banned. See ARCHITECTURE.md, "Open provisioning".
 */
class SecurityLog
{
	/** @var array<int, string> Lines written while FreePBX's logger is absent: the tests. */
	public static $written = [];

	/**
	 * Write one line.
	 *
	 * @param string $line What happened. Control characters are replaced, so a
	 *                     username cannot write a line of its own.
	 *
	 * @return void
	 */
	public static function write($line)
	{
		$line = self::scrub($line);

		if (function_exists('freepbx_log_security')) {
			\freepbx_log_security($line);

			return;
		}

		self::$written[] = $line;
	}

	/**
	 * A value made safe to put in a line: printable ASCII only.
	 *
	 * @param mixed $value Anything a caller sent.
	 *
	 * @return string The value, with everything else replaced by `?`.
	 */
	public static function scrub($value)
	{
		return (string) preg_replace('/[^\x20-\x7E]/', '?', (string) $value);
	}

	/**
	 * The administrator this request was made by, for a line saying who did it.
	 *
	 * @return string Their username, or `unknown`.
	 */
	public static function admin()
	{
		$user = $_SESSION['AMP_user'] ?? null;
		$name = is_object($user) && isset($user->username) ? (string) $user->username : '';

		return $name !== '' ? $name : 'unknown';
	}
}
