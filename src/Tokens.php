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
	 * password_hash() rather than a digest of the string, because a token is a
	 * secret somebody chose -- `username:password` is the shape this field
	 * invites -- and a fast digest of a chosen secret is a wordlist away from
	 * the secret. The algorithm, the cost and the salt travel inside the hash,
	 * so the column holds everything verifying needs and the module has no
	 * second thing to keep in step.
	 *
	 * What it costs is that the column cannot be looked up: the same token
	 * hashed twice gives two different strings, so there is no SELECT that
	 * finds a client by the token it presented. That is the right trade here
	 * because a request already says who is asking -- the MAC address is in
	 * the path -- and the token only has to say whether it is really them. A
	 * token meant to *identify* a client instead, the way the README's
	 * /provisioner/{token}/{file} would, has to be a digest of something
	 * random enough that a digest is safe, and would be a second column rather
	 * than a different meaning for this one.
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
	 * Nothing calls this yet -- the endpoint is still keyed on MAC address
	 * alone and authenticates nothing. It is here because a stored hash that
	 * nothing can check is not a mechanism, and it is public because what will
	 * eventually call it is engine/provisioner.php rather than a page of this
	 * module.
	 *
	 * A client with no token set is false rather than true. The question this
	 * answers is "is this the client's token", and a client that has none has
	 * no token that this is. Whether a request with nothing to check should be
	 * let through is a policy question, and it belongs to whatever asks.
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
	 * Private, and returning the hash rather than the row, because the hash
	 * has exactly one use and no reason to travel further than the method that
	 * verifies against it. It is deliberately not part of clientRow(), so no
	 * page of this module is ever handed it.
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
