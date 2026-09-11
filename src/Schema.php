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
	 * connection that has gone, on every MySQL build this has to run on.
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
	 * Bring a resources table written before 1.0.14 up to date.
	 *
	 * `type` is what a resource is: a template to render, a file to hand over, or a
	 * log to receive. Before it the row was read for that -- a file_size meant a
	 * file -- so a resource could not be a file until a file was on it.
	 *
	 * Added with a default rather than backfilled, so every resource written before
	 * this is a template. The one UPDATE catches the rows that were already files
	 * and runs only on the pass that adds the column: after that the column is the
	 * authority, not file_size.
	 *
	 * Ordered after addResourceFileColumns() by install() and not by accident --
	 * the backfill reads file_size.
	 *
	 * @return void
	 */
	public function addResourceTypeColumn()
	{
		if ($this->schemaHas($this->resourcesTable, 'column', 'type')) {
			return;
		}

		$this->db->exec(
			"ALTER TABLE `{$this->resourcesTable}`
			ADD COLUMN `type` VARCHAR(16) NOT NULL DEFAULT 'template' AFTER `name`"
		);

		$this->db->exec(
			"UPDATE `{$this->resourcesTable}` SET `type` = 'file' WHERE `file_size` IS NOT NULL"
		);
	}

	/**
	 * Bring a clients table written before 1.0.12 up to date.
	 *
	 * Additive, like the rest: a client with no token is a client with nothing to
	 * check, which is every client on a site upgrading into this.
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
	 * Bring a clients table written before 1.0.15 up to date.
	 *
	 * @return void
	 */
	public function addClientEnabledColumn()
	{
		$this->addEnabledColumn($this->clientsTable, 'token');
	}

	/**
	 * Bring a profiles table written before 1.0.16 up to date.
	 *
	 * @return void
	 */
	public function addProfileEnabledColumn()
	{
		$this->addEnabledColumn($this->profilesTable, 'name');
	}

	/**
	 * Bring a clients table written before 1.0.17 up to date.
	 *
	 * Nullable with no default, and deliberately: a column that answered "never
	 * seen" with the moment it was added would say every phone on the site checked
	 * in at once, on the day of the upgrade.
	 *
	 * @return void
	 */
	public function addClientLastSeenColumn()
	{
		if (!$this->schemaHas($this->clientsTable, 'column', 'last_seen')) {
			$this->db->exec(
				"ALTER TABLE `{$this->clientsTable}`
				ADD COLUMN `last_seen` DATETIME NULL DEFAULT NULL AFTER `enabled`"
			);
		}
	}

	/**
	 * Give a table the column that says whether the endpoint answers for it.
	 *
	 * The same column on two tables, added the same way -- see src/Enabled.php,
	 * which is the other half of this.
	 *
	 * Default 1 rather than backfilled, because a row written before there was a
	 * switch is a row nobody switched off. NOT NULL rather than nullable, because a
	 * third state would mean "nobody has said" and every reader would have to
	 * decide what that meant.
	 *
	 * Both names are interpolated and neither may come from a request.
	 *
	 * @param string $table Table to add it to.
	 * @param string $after Column it is placed after.
	 *
	 * @return void
	 */
	private function addEnabledColumn($table, $after)
	{
		if (!$this->schemaHas($table, 'column', 'enabled')) {
			$this->db->exec(
				"ALTER TABLE `$table`
				ADD COLUMN `enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `$after`"
			);
		}
	}

	/**
	 * Whether a table already has a column, or an index, by that name.
	 *
	 * One question of two catalogues, because there is one thing the answer is for:
	 * whether to ALTER. A question that cannot be asked is answered yes -- not
	 * knowing is a reason to leave the table alone rather than change it blind.
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
