<?php

// src/Tokens.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * A client's token: hashing one, and checking one.
 *
 * Typed as user:password and hashed on save, so the column holds the
 * hash from then on. Kept apart from the rest of the client because it
 * is the one part of a client that is a security boundary.
 */
class Tokens extends Service
{
	/**
	 * Hash a token for storage.
	 *
	 * password_hash() rather than a digest of the string: a token is a secret
	 * somebody chose -- `username:password` is the shape this field invites -- and
	 * a fast digest of a chosen secret is a wordlist away from the secret.
	 *
	 * The cost is that the column cannot be looked up, since the same token hashed
	 * twice gives two different strings. Right here, because the MAC in the path
	 * already says who is asking; a token meant to *identify* a client would need a
	 * second, indexed column.
	 *
	 * @param string $token Token as typed.
	 *
	 * @return string|null The hash, or null when one could not be made.
	 */
	public function hashToken($token)
	{
		$hash = password_hash((string) $token, PASSWORD_DEFAULT);

		return is_string($hash) && $hash !== '' ? $hash : null;
	}

	/**
	 * Whether a client's token is the one presented.
	 *
	 * The same check Endpoint::resolveRequest() makes inline against the client row
	 * it already has, for a caller that has only a MAC.
	 *
	 * A client with no token set is false rather than true: the question is "is
	 * this the client's token", and a client that has none has no token that this
	 * is. Whether a request with nothing to check is let through belongs to the
	 * caller.
	 *
	 * @param string $mac   MAC address of the client, in any separator style.
	 * @param string $token Token as presented.
	 *
	 * @return bool True when the client has a token and this is it.
	 */
	public function verifyToken($mac, $token)
	{
		$token = (string) $token;
		$hash = $this->clientTokenHash($mac);

		if ($hash === null || $token === '') {
			return false;
		}

		return password_verify($token, $hash);
	}

	/**
	 * The stored token hash for a MAC address, if there is one.
	 *
	 * Private, and returning the hash rather than the row: it has one use and no
	 * reason to travel further. Deliberately not part of clientRow(), so no page of
	 * this module is ever handed it.
	 *
	 * @param string $mac MAC address, in any separator style.
	 *
	 * @return string|null The hash, or null when the client or the token is not there.
	 */
	private function clientTokenHash($mac)
	{
		$mac = Mac::normalize($mac);

		if ($mac === '') {
			return null;
		}

		$stmt = $this->db->prepare(
			"SELECT token FROM `{$this->clientsTable}` WHERE mac = :mac"
		);
		$stmt->execute([':mac' => $mac]);
		$hash = (string) $stmt->fetchColumn();

		return $hash === '' ? null : $hash;
	}
}
