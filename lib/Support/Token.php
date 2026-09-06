<?php

namespace Oryk\Provisioner\Support;

/**
 * Provisioning token generation.
 *
 * Tokens are opaque, cryptographically random values. They never encode the
 * device id, extension, MAC address or username (see README - Token Lifecycle).
 */
class Token
{
	const MIN_LENGTH = 16;
	const MAX_LENGTH = 128;
	const DEFAULT_LENGTH = 32;

	/**
	 * Generate a new hex token of the given length (characters, not bytes).
	 *
	 * @param int $length
	 * @return string
	 */
	public static function generate($length = self::DEFAULT_LENGTH)
	{
		$length = (int) $length;

		if ($length < self::MIN_LENGTH) {
			$length = self::MIN_LENGTH;
		}

		if ($length > self::MAX_LENGTH) {
			$length = self::MAX_LENGTH;
		}

		// Round up to an even number so bin2hex lands exactly on $length.
		$bytes = (int) ceil($length / 2);

		return substr(bin2hex(self::randomBytes($bytes)), 0, $length);
	}

	/**
	 * A submitted token is only usable if it looks like one of ours. Rejecting
	 * junk early keeps malformed input away from the database.
	 */
	public static function isWellFormed($token)
	{
		return (bool) preg_match('/^[a-f0-9]{' . self::MIN_LENGTH . ',' . self::MAX_LENGTH . '}$/i', (string) $token);
	}

	/**
	 * Constant time comparison.
	 */
	public static function equals($known, $supplied)
	{
		return hash_equals((string) $known, (string) $supplied);
	}

	/**
	 * random_bytes with a guarded fallback for unusual PHP builds.
	 */
	private static function randomBytes($bytes)
	{
		if (function_exists('random_bytes')) {
			try {
				return random_bytes($bytes);
			} catch (\Exception $e) {
				// fall through
			}
		}

		if (function_exists('openssl_random_pseudo_bytes')) {
			$strong = false;
			$value = openssl_random_pseudo_bytes($bytes, $strong);

			if ($strong && $value !== false) {
				return $value;
			}
		}

		throw new \RuntimeException('No cryptographically secure random source is available.');
	}
}
