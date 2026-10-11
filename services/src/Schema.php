<?php

// services/src/Schema.php

namespace FreePBX\Modules\Oryk_Services;

/**
 * The services' tables: made, and added to.
 *
 * The same rule as the provisioner's Schema: every step is additive and asks
 * information_schema what is already there, never a dbversion.
 */
class Schema extends Component
{
	/**
	 * Make every table that is not there yet. Rows already there are left alone;
	 * what an older table is missing is the steps' to add.
	 *
	 * @return void
	 */
	public function createTables()
	{
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->servicesTable}` (
				`slug` VARCHAR(64) NOT NULL,
				`name` VARCHAR(191) NOT NULL,
				`owner` INT UNSIGNED NOT NULL DEFAULT 0,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
				PRIMARY KEY (`slug`),
				UNIQUE KEY `name` (`name`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// Both ends are slugs, not ids. The pair is the key, so a link exists
		// once; `child` has an index of its own for the walk upwards, which the
		// key's left column cannot serve. `kind` '' is a pack link, 'requires'
		// a requirement.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->serviceLinksTable}` (
				`parent` VARCHAR(64) NOT NULL,
				`child` VARCHAR(64) NOT NULL,
				`kind` VARCHAR(16) NOT NULL DEFAULT '',
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`parent`, `child`),
				KEY `child` (`child`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// A user is its extension and a service its slug, as everywhere else.
		// `service` has an index of its own: a renamed or deleted service is
		// looked up by it. `status` NULL is a service given to the user; 0 is
		// one withheld from it, that a pack it is given would give otherwise.
		// See ARCHITECTURE.md, "Services".
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->serviceAssignmentsTable}` (
				`extension` VARCHAR(20) NOT NULL,
				`service` VARCHAR(64) NOT NULL,
				`status` TINYINT NULL DEFAULT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (`extension`, `service`),
				KEY `service` (`service`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// One change to what one user holds. `service` and `name` are the
		// service the change was made to, the name a snapshot: a deleted
		// service's job still says what it was. An upgrade's job names none.
		// `admin` is who was signed in when a page made it, '' for anything else.
		// See ARCHITECTURE.md, "Jobs".
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->jobsTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`extension` VARCHAR(20) NOT NULL,
				`service` VARCHAR(64) NOT NULL DEFAULT '',
				`name` VARCHAR(191) NOT NULL DEFAULT '',
				`reason` VARCHAR(16) NOT NULL,
				`source` VARCHAR(16) NOT NULL DEFAULT 'gui',
				`admin` VARCHAR(64) NOT NULL DEFAULT '',
				`state` VARCHAR(16) NOT NULL DEFAULT 'queued',
				`attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
				`error` TEXT NULL,
				`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
				`started_at` DATETIME NULL DEFAULT NULL,
				`finished_at` DATETIME NULL DEFAULT NULL,
				PRIMARY KEY (`id`),
				KEY `extension_state` (`extension`, `state`, `id`),
				KEY `state_finished` (`state`, `finished_at`),
				KEY `service` (`service`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);

		// A job's services, in the order they run: revokes first. `via` is the
		// assigned service it came or went through, NULL for the service itself;
		// `done_by` the handlers that have finished it, comma-separated rawnames;
		// `own_job` the src/Jobs/ class this module ran for it, NULL for none.
		$this->db->exec(
			"CREATE TABLE IF NOT EXISTS `{$this->jobStepsTable}` (
				`id` INT(11) NOT NULL AUTO_INCREMENT,
				`job_id` INT(11) NOT NULL,
				`position` SMALLINT(5) UNSIGNED NOT NULL,
				`service` VARCHAR(64) NOT NULL,
				`name` VARCHAR(191) NOT NULL DEFAULT '',
				`via` VARCHAR(64) NULL DEFAULT NULL,
				`event` VARCHAR(8) NOT NULL,
				`state` VARCHAR(16) NOT NULL DEFAULT 'pending',
				`done_by` VARCHAR(255) NOT NULL DEFAULT '',
				`own_job` VARCHAR(64) NULL DEFAULT NULL,
				`attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
				`error` TEXT NULL,
				`finished_at` DATETIME NULL DEFAULT NULL,
				PRIMARY KEY (`id`),
				UNIQUE KEY `job_position` (`job_id`, `position`),
				KEY `service` (`service`),
				KEY `via` (`via`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
		);
	}

	/**
	 * Bring a services table written before services had a slug up to date.
	 *
	 * Nullable, because the rows already there have none until
	 * Services::seed() makes each one from its name, straight after this.
	 * NULLs never collide on the unique key; dropServiceIdColumn() then makes
	 * the column the primary key. A table keyed by slug already is left alone.
	 *
	 * @return void
	 */
	public function addServiceSlugColumn()
	{
		if (!$this->schemaHas($this->servicesTable, 'column', 'id')) {
			return;
		}

		if (!$this->schemaHas($this->servicesTable, 'column', 'slug')) {
			$this->db->exec(
				"ALTER TABLE `{$this->servicesTable}`
				ADD COLUMN `slug` VARCHAR(64) NULL DEFAULT NULL AFTER `name`"
			);
		}

		if (!$this->schemaHas($this->servicesTable, 'index', 'slug')) {
			$this->db->exec(
				"ALTER TABLE `{$this->servicesTable}` ADD UNIQUE KEY `slug` (`slug`)"
			);
		}
	}

	/**
	 * Give a services table written before it its `owner`: 0 is the module,
	 * and the column's default.
	 *
	 * Backfilled once, on the pass that adds it: every row but the module's
	 * own is made an operator's (1), or Services::seed(), which deletes the
	 * module's rows straight after this, would take them all.
	 *
	 * @param array<int, string> $defaults The module's slugs: Services::DEFAULTS' keys.
	 *
	 * @return void
	 */
	public function addServiceOwnerColumn(array $defaults)
	{
		if ($this->schemaHas($this->servicesTable, 'column', 'owner')) {
			return;
		}

		$this->db->exec(
			"ALTER TABLE `{$this->servicesTable}`
			ADD COLUMN `owner` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `name`"
		);

		$mine = $defaults ? ' OR slug NOT IN (' . implode(', ', array_fill(0, count($defaults), '?')) . ')' : ' OR 1 = 1';
		$stmt = $this->db->prepare(
			"UPDATE `{$this->servicesTable}` SET owner = 1, updated_at = updated_at WHERE slug IS NULL" . $mine
		);
		$stmt->execute(array_map('strval', array_values($defaults)));
	}

	/**
	 * Give a links table written before it its `kind`: '' is a pack link,
	 * the column's default, so every row already there stays one.
	 *
	 * @return void
	 */
	public function addServiceLinkKindColumn()
	{
		if (!$this->schemaHas($this->serviceLinksTable, 'column', 'kind')) {
			$this->db->exec(
				"ALTER TABLE `{$this->serviceLinksTable}`
				ADD COLUMN `kind` VARCHAR(16) NOT NULL DEFAULT '' AFTER `child`"
			);
		}
	}

	/**
	 * Bring a job steps table written before a step said which of the
	 * module's own jobs ran for it up to date. NULL on every older step.
	 *
	 * @return void
	 */
	public function addJobStepOwnJobColumn()
	{
		if (!$this->schemaHas($this->jobStepsTable, 'column', 'own_job')) {
			$this->db->exec(
				"ALTER TABLE `{$this->jobStepsTable}`
				ADD COLUMN `own_job` VARCHAR(64) NULL DEFAULT NULL AFTER `done_by`"
			);
		}
	}

	/**
	 * Give a jobs table written before it its `admin`: who made the job.
	 *
	 * @return void
	 */
	public function addJobAdminColumn()
	{
		if (!$this->schemaHas($this->jobsTable, 'column', 'admin')) {
			$this->db->exec(
				"ALTER TABLE `{$this->jobsTable}`
				ADD COLUMN `admin` VARCHAR(64) NOT NULL DEFAULT '' AFTER `source`"
			);
		}
	}

	/**
	 * Give a service assignments table written before it its `status`: NULL,
	 * a service given, on every older row.
	 *
	 * @return void
	 */
	public function addServiceAssignmentStatusColumn()
	{
		if (!$this->schemaHas($this->serviceAssignmentsTable, 'column', 'status')) {
			$this->db->exec(
				"ALTER TABLE `{$this->serviceAssignmentsTable}`
				ADD COLUMN `status` TINYINT NULL DEFAULT NULL AFTER `service`"
			);
		}
	}

	/**
	 * Re-key a services table written with an `id`: the slug becomes the
	 * primary key and the id goes.
	 *
	 * **Must run after Services::seed()**, which gives every older row its
	 * slug. A row still without one stops this -- it cannot be a key -- and
	 * the table is left as it was for the next install to try again. One
	 * statement, so the table is never without a primary key.
	 *
	 * @return bool False when the table still has its id afterwards.
	 */
	public function dropServiceIdColumn()
	{
		if (!$this->schemaHas($this->servicesTable, 'column', 'id')) {
			return true;
		}

		try {
			$bare = $this->db->query(
				"SELECT COUNT(*) FROM `{$this->servicesTable}` WHERE slug IS NULL OR slug = ''"
			);

			if ($bare->fetchColumn()) {
				$this->log('oryk_services: services not re-keyed by slug', 'a service has no slug', 'WARNING');

				return false;
			}

			$this->db->exec(
				"ALTER TABLE `{$this->servicesTable}`
				DROP COLUMN `id`,
				DROP INDEX `slug`,
				MODIFY `slug` VARCHAR(64) NOT NULL FIRST,
				ADD PRIMARY KEY (`slug`)"
			);
		} catch (\Exception $e) {
			$this->log('oryk_services: services not re-keyed by slug', $e->getMessage(), 'WARNING');

			return false;
		}

		return true;
	}

	/**
	 * Whether a table already has a column, or an index, by that name.
	 *
	 * One question of two catalogues, because there is one thing the answer is
	 * for: whether to ALTER. A question that cannot be asked is answered yes --
	 * not knowing is a reason to leave the table alone rather than change it blind.
	 *
	 * @param string $table Table name.
	 * @param string $kind  'column' or 'index'.
	 * @param string $name  Name to look for.
	 *
	 * @return bool True when it is there, or when it could not be asked.
	 */
	private function schemaHas($table, $kind, $name)
	{
		// Chosen here rather than passed through, so nothing that reaches this
		// method can reach the two names written into the statement.
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
			$this->log('oryk_services: could not read information_schema', $e->getMessage(), 'WARNING');

			return true;
		}
	}
}
