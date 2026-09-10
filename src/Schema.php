<?php

// src/Schema.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The tables, as they are added to.
 *
 * Every step here is additive and asks the database what is already there
 * rather than trusting a dbversion: the question that matters is "is the
 * column there?", and it answers the same whether the module arrived by
 * upgrade, by reinstall, or by a restore of an older backup.
 */
class Schema extends Service
{
	/**
	 * Bring a resources table written before 1.0.6 up to date.
	 *
	 * Asked of information_schema rather than tried and caught: a failed DDL
	 * statement is not something a PDO exception cleanly tells apart from a
	 * connection that has gone, on every MySQL build this has to run on. Not
	 * gated on dbversion either -- the question that matters is whether the
	 * column is there, and asking it directly answers the same whether the
	 * module arrived here by upgrade, by reinstall or by a restore.
	 *
	 * @return void
	 */
	public function addResourceFileColumns()
	{
		$columns = [
			'file_size' => 'ADD COLUMN `file_size` INT(10) UNSIGNED NULL DEFAULT NULL AFTER `template`',
			'file_uploaded_at' => 'ADD COLUMN `file_uploaded_at` DATETIME NULL DEFAULT NULL AFTER `file_size`',
		];

		foreach ($columns as $column => $clause) {
			if (!$this->schemaHas($this->resourcesTable, 'column', $column)) {
				$this->db->exec("ALTER TABLE `{$this->resourcesTable}` $clause");
			}
		}

		if (!$this->schemaHas($this->resourcesTable, 'index', 'name')) {
			$this->db->exec("ALTER TABLE `{$this->resourcesTable}` ADD KEY `name` (`name`)");
		}
	}

	/**
	 * Bring a clients table written before 1.0.12 up to date.
	 *
	 * Asked of information_schema rather than tried and caught, for the reason
	 * addResourceFileColumns() gives, and additive in the same way: a client
	 * with no token is a client with nothing to check, which is every client
	 * on a site upgrading into this.
	 *
	 * @return void
	 */
	public function addClientTokenColumn()
	{
		if (!$this->schemaHas($this->clientsTable, 'column', 'token')) {
			$this->db->exec(
				"ALTER TABLE `{$this->clientsTable}`
				ADD COLUMN `token` VARCHAR(255) NULL DEFAULT NULL AFTER `profile_id`"
			);
		}
	}

	/**
	 * Whether a table already has a column, or an index, by that name.
	 *
	 * One question of two catalogues, because there is one thing the answer
	 * is for: whether to ALTER. A question that cannot be asked is answered
	 * yes -- not knowing is a reason to leave the table alone rather than to
	 * change it blind.
	 *
	 * @param string $table Table name.
	 * @param string $kind  'column' or 'index'.
	 * @param string $name  Name to look for.
	 *
	 * @return bool True when it is there, or when it could not be asked.
	 */
	private function schemaHas($table, $kind, $name)
	{
		// Chosen here rather than passed through, so nothing that reaches
		// this method can reach the two names written into the statement.
		$catalogue = $kind === 'index' ? 'STATISTICS' : 'COLUMNS';
		$field = $kind === 'index' ? 'INDEX_NAME' : 'COLUMN_NAME';

		try {
			$stmt = $this->db->prepare(
				"SELECT COUNT(*) FROM information_schema.$catalogue
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND $field = :name"
			);
			$stmt->execute([':table' => $table, ':name' => $name]);

			return (bool) $stmt->fetchColumn();
		} catch (\Exception $e) {
			$this->log('oryk_provisioner: could not read information_schema', $e->getMessage(), 'WARNING');

			return true;
		}
	}
}
