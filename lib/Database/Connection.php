<?php

namespace Oryk\Provisioner\Database;

/**
 * Database connection resolver.
 *
 * FreePBX exposes a PDO instance through FreePBX::Database(). We resolve it
 * lazily (and allow it to be injected) so the engine can be exercised outside
 * of FreePBX - for example by the bundled test harness.
 */
class Connection
{
	/** @var \PDO|null */
	private static $pdo = null;

	/**
	 * Inject a connection (tests, CLI tools).
	 */
	public static function set($pdo)
	{
		self::$pdo = $pdo;
	}

	/**
	 * @return \PDO
	 */
	public static function get()
	{
		if (self::$pdo !== null) {
			return self::$pdo;
		}

		if (class_exists('\FreePBX')) {
			self::$pdo = \FreePBX::Database();

			return self::$pdo;
		}

		throw new \RuntimeException('No database connection available. FreePBX has not been bootstrapped.');
	}

	/**
	 * Reset the cached connection.
	 */
	public static function reset()
	{
		self::$pdo = null;
	}
}
