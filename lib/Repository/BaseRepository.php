<?php

namespace Oryk\Provisioner\Repository;

use Oryk\Provisioner\Database\Connection;

/**
 * Shared PDO plumbing for the module repositories.
 */
abstract class BaseRepository
{
	/** @var \PDO */
	protected $pdo;

	public function __construct($pdo = null)
	{
		$this->pdo = $pdo === null ? Connection::get() : $pdo;
	}

	/**
	 * @return \PDO
	 */
	public function pdo()
	{
		return $this->pdo;
	}

	/**
	 * Run a prepared statement and return every row.
	 *
	 * @return array
	 */
	protected function select($sql, array $bindings = array())
	{
		$statement = $this->pdo->prepare($sql);
		$statement->execute($bindings);

		return $statement->fetchAll(\PDO::FETCH_ASSOC);
	}

	/**
	 * Run a prepared statement and return the first row (or null).
	 *
	 * @return array|null
	 */
	protected function selectOne($sql, array $bindings = array())
	{
		$rows = $this->select($sql, $bindings);

		return empty($rows) ? null : $rows[0];
	}

	/**
	 * Run a write statement.
	 *
	 * @return \PDOStatement
	 */
	protected function execute($sql, array $bindings = array())
	{
		$statement = $this->pdo->prepare($sql);
		$statement->execute($bindings);

		return $statement;
	}

	/**
	 * Current timestamp in MySQL format.
	 */
	protected function now()
	{
		return date('Y-m-d H:i:s');
	}
}
