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
	 * Bring a resources table written before 1.0.11 up to date.
	 *
	 * file_size and file_uploaded_at are what an uploaded file leaves on the
	 * row, and the index on `name` is for the by-name lookup a request with no
	 * client behind it makes -- see Matcher::fileByName().
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
	 * Bring a resources table written before 1.0.14 up to date.
	 *
	 * `type` is what a resource is: a template to render, a file to hand
	 * over, or a log to receive. Before this column that question was
	 * answered by looking at the row -- a file_size meant a file and its
	 * absence meant a template -- which worked and said nothing: a resource
	 * could not be a file until a file was on it, could not be declared a
	 * log at all, and nothing on the row said what its author meant.
	 *
	 * Added with a default rather than backfilled from nothing, so every
	 * resource written before this is a template, which is what it was. The
	 * one UPDATE is the rows that were already files, and it runs only on
	 * the pass that adds the column: after that the column is the authority
	 * and file_size is a fact about the upload, so a later pass re-deriving
	 * one from the other would be the guessing this replaces.
	 *
	 * Ordered after addResourceFileColumns() by install() and not by
	 * accident -- the backfill reads file_size, which on a table written
	 * before 1.0.6 is added by that step.
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
	 * Nullable with no default, and deliberately: a client that has never
	 * been seen has no time to record, and a column that answered that with
	 * the moment the column was added would say every phone on the site
	 * checked in at once, on the day of the upgrade. NULL is the one honest
	 * value for "has not asked yet", and it is what every row starts at --
	 * including rows that have been provisioning for a year, which report
	 * themselves again the next time they ask.
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
	 * Bring a clients table written before 1.0.18 up to date.
	 *
	 * Where the phone is, as an operator knows it: the address it answers its
	 * own web interface on, and the address the site it sits behind is reached
	 * at from outside. Neither is discovered and neither is used to reach the
	 * phone from here -- they are written down on the client so that the
	 * Clients list can offer a link to the handset, and so that a template can
	 * render what only the operator knows.
	 *
	 * Nullable with no default, and for the reason last_seen is: nobody has
	 * said where a phone written before this column is, and any value that
	 * answered that would be answering it wrongly for every row at once.
	 *
	 * 45 characters is an IPv6 address at its longest, which is what the
	 * provisioning log's own `ip` column is sized for.
	 *
	 * @return void
	 */
	public function addClientAddressColumns()
	{
		$columns = [
			'public_ip' => 'ADD COLUMN `public_ip` VARCHAR(45) NULL DEFAULT NULL AFTER `last_seen`',
			'private_ip' => 'ADD COLUMN `private_ip` VARCHAR(45) NULL DEFAULT NULL AFTER `public_ip`',
		];

		foreach ($columns as $column => $clause) {
			if (!$this->schemaHas($this->clientsTable, 'column', $column)) {
				$this->db->exec("ALTER TABLE `{$this->clientsTable}` $clause");
			}
		}
	}

	/**
	 * Bring a clients table written before 1.0.19 up to date.
	 *
	 * The MAC stopped being required, so the column stops being NOT NULL. An
	 * empty box is stored as NULL and not as '', and the unique key on the
	 * column is the whole reason it has to be: MySQL counts NULLs as distinct
	 * from one another and empty strings as equal, so stored as '' the key
	 * would admit exactly one client without a MAC and refuse every one after
	 * it as a duplicate. The key is kept -- a MAC that is written is still
	 * written once.
	 *
	 * The one step here that asks what a column is rather than whether it is
	 * there, since MODIFY is not ADD and there is no new name to look for.
	 * Left alone when the question cannot be answered, for the reason
	 * schemaHas() gives: not knowing is a reason not to ALTER.
	 *
	 * Nothing is backfilled. Every row written before this has a real MAC,
	 * and a column merely allowed to be NULL changes none of them.
	 *
	 * @return void
	 */
	public function relaxClientMacColumn()
	{
		if ($this->columnIsNullable($this->clientsTable, 'mac') !== false) {
			return;
		}

		$this->db->exec(
			"ALTER TABLE `{$this->clientsTable}`
			MODIFY COLUMN `mac` VARCHAR(12) NULL DEFAULT NULL"
		);
	}

	/**
	 * Give a table the column that says whether the endpoint answers for it.
	 *
	 * The same column on two tables, added the same way, so it is added in
	 * one place: a client and a profile are switched off by the same switch
	 * and mean the same thing by it -- see src/Enabled.php, which is the
	 * other half of this.
	 *
	 * Added with a default of 1 rather than backfilled, because a row written
	 * before there was a switch is a row nobody switched off: everything on a
	 * site upgrading into this is served exactly what it was being served the
	 * moment before.
	 *
	 * NOT NULL with a default rather than nullable, and deliberately: a
	 * three-state column would have a value meaning "nobody has said", and
	 * every reader would then have to decide what that meant. There are two
	 * states and the column holds them.
	 *
	 * Both names are interpolated and neither may come from a request: the
	 * table is named by the caller above from Service's properties, and the
	 * column it goes after is written here.
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
	 * Whether a column is declared nullable.
	 *
	 * Three answers rather than two: true, false, and null for a column that
	 * is not there or a catalogue that could not be read. Both of those are
	 * reasons to leave the table alone, which is the rule schemaHas() states
	 * and the reason a caller tests against false rather than for truth.
	 *
	 * @param string $table  Table name.
	 * @param string $column Column name.
	 *
	 * @return bool|null True when nullable, false when NOT NULL, null when unknown.
	 */
	private function columnIsNullable($table, $column)
	{
		try {
			$stmt = $this->db->prepare(
				"SELECT IS_NULLABLE FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
			);
			$stmt->execute([':table' => $table, ':column' => $column]);
			$nullable = $stmt->fetchColumn();

			return $nullable === false ? null : strtoupper((string) $nullable) === 'YES';
		} catch (\Exception $e) {
			$this->log('oryk_provisioner: could not read information_schema', $e->getMessage(), 'WARNING');

			return null;
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
