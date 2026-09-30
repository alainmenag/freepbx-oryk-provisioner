<?php

// src/CdrHistory.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The call history belonging to an extension.
 *
 * Nothing in FreePBX moves or removes CDR rows when an extension is
 * renumbered or deleted. Which tables and columns exist varies by site, so
 * both operations here discover them and step over a statement naming a
 * missing column rather than abandoning the rest. See ARCHITECTURE.md,
 * "Users".
 */
class CdrHistory extends Service
{
	/**
	 * Columns of each CDR table, as they were read this request.
	 *
	 * @var array<string, array<int, string>>
	 */
	private $columns = [];

	/**
	 * What a mailbox is dialled as, which the history records as a
	 * destination like any other number.
	 *
	 * @var VoicemailManager
	 */
	private $voicemail;

	/**
	 * @param object           $freepbx   FreePBX application instance.
	 * @param VoicemailManager $voicemail Mailbox numbering.
	 */
	public function __construct($freepbx, VoicemailManager $voicemail)
	{
		parent::__construct($freepbx);

		$this->voicemail = $voicemail;
	}

	/**
	 * Open the CDR database for a job that will take a while.
	 *
	 * The CDR module keeps its own handle, possibly on another server, and
	 * may be absent. The time limit is lifted: only dst and dstchannel are
	 * indexed, so these statements scan the table, and being cut off half
	 * way leaves history split across two numbers or half deleted.
	 *
	 * @param string $doing What is about to be done, for the log.
	 *
	 * @return object|null The handle, or null when there is none to be had.
	 */
	private function handle($doing)
	{
		if (!$this->moduleActive('cdr')) {
			return null;
		}

		try {
			$cdrdb = \FreePBX::Cdr()->getCdrDbHandle();
		} catch (\Exception $e) {
			$this->logError('no CDR database to ' . $doing . ': ' . $e->getMessage());

			return null;
		}

		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}

		return $cdrdb;
	}

	/**
	 * Carry the call history over to a new extension number.
	 *
	 * Rewrites what the CDR report matches on (src, dst, cnum, channel,
	 * dstchannel) and displays (clid). Recording file names are left alone:
	 * they must keep matching the file on disk to stay playable.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return int How many rows were rewritten.
	 */
	public function migrate($old, $new)
	{
		$cdrdb = $this->handle('move ' . $old . ' in');

		if ($cdrdb === null) {
			return 0;
		}

		$rows = 0;

		foreach ($this->tables($cdrdb) as $table) {
			$rows += $this->migrateTable($cdrdb, $table, $old, $new);
		}

		// Channel event logging, when the site records it
		if ($this->tableExists($cdrdb, 'cel')) {
			$rows += $this->migrateCel($cdrdb, 'cel', $old, $new);
		}

		$this->logInfo('moved ' . $rows . ' call history rows from ' . $old . ' to ' . $new);

		return $rows;
	}

	/**
	 * Rewrite one call detail table for a number that has moved.
	 *
	 * @param object     $cdrdb CDR database handle.
	 * @param string     $table Table to rewrite.
	 * @param int|string $old   Number being left behind.
	 * @param int|string $new   Number being moved to.
	 *
	 * @return int How many rows were rewritten.
	 */
	private function migrateTable($cdrdb, $table, $old, $new)
	{
		$t = '`' . $table . '`';
		$rows = 0;

		// Columns holding the number on its own. accountcode and peeraccount
		// only hold an extension on a site that has chosen to put one there,
		// so they are matched exactly and are a no-op everywhere else.
		foreach (['src', 'dst', 'cnum', 'accountcode', 'peeraccount'] as $column) {
			$rows += $this->runUpdate(
				$cdrdb,
				'UPDATE ' . $t . ' SET `' . $column . '` = :new WHERE `' . $column . '` = :old',
				[':old' => $old, ':new' => $new]
			);
		}

		// dst also carries the voicemail pseudo extensions that Core adds to
		// the dialplan, and the prefix that dials a mailbox directly
		$to = $this->voicemail->dialableNumbers($new);

		foreach ($this->voicemail->dialableNumbers($old) as $index => $dialled) {
			if (!isset($to[$index])) {
				continue;
			}

			$rows += $this->runUpdate(
				$cdrdb,
				'UPDATE ' . $t . ' SET dst = :new WHERE dst = :old',
				[':old' => $dialled, ':new' => $to[$index]]
			);
		}

		// The number inside a channel name, in both the shapes it takes:
		// PJSIP/1001-0000abcd and Local/1001@from-internal-0000abcd
		foreach (['channel', 'dstchannel'] as $column) {
			$rows += $this->replaceInColumn($cdrdb, $t, $column, $old, $new);
		}

		// The caller id string the reports display
		$rows += $this->runUpdate(
			$cdrdb,
			'UPDATE ' . $t . ' SET clid = REPLACE(clid, :needle, :replacement) WHERE clid LIKE :match',
			[
				':needle' => '<' . $old . '>',
				':replacement' => '<' . $new . '>',
				':match' => '%<' . $old . '>%',
			]
		);

		return $rows;
	}

	/**
	 * Rewrite the channel event log for a number that has moved.
	 *
	 * @param object     $cdrdb CDR database handle.
	 * @param string     $table Table to rewrite.
	 * @param int|string $old   Number being left behind.
	 * @param int|string $new   Number being moved to.
	 *
	 * @return int How many rows were rewritten.
	 */
	private function migrateCel($cdrdb, $table, $old, $new)
	{
		$t = '`' . $table . '`';
		$rows = 0;

		foreach (['cid_num', 'cid_ani', 'exten', 'accountcode', 'peeraccount'] as $column) {
			$rows += $this->runUpdate(
				$cdrdb,
				'UPDATE ' . $t . ' SET `' . $column . '` = :new WHERE `' . $column . '` = :old',
				[':old' => $old, ':new' => $new]
			);
		}

		$to = $this->voicemail->dialableNumbers($new);

		foreach ($this->voicemail->dialableNumbers($old) as $index => $dialled) {
			if (!isset($to[$index])) {
				continue;
			}

			$rows += $this->runUpdate(
				$cdrdb,
				'UPDATE ' . $t . ' SET exten = :new WHERE exten = :old',
				[':old' => $dialled, ':new' => $to[$index]]
			);
		}

		foreach (['channame', 'peer'] as $column) {
			$rows += $this->replaceInColumn($cdrdb, $t, $column, $old, $new);
		}

		return $rows;
	}

	/**
	 * Take an extension's call history out of the CDR database.
	 *
	 * Records naming the extension seed the set of calls; every row sharing
	 * their uniqueid or linkedid then goes from the call detail tables and
	 * cel. A call between two extensions is removed whole, so a surviving
	 * extension loses it from its own history too, with no undo. See
	 * ARCHITECTURE.md, "Users".
	 *
	 * @param int|string $extension Number being deleted.
	 *
	 * @return array{rows: int, recordings: int} What was removed.
	 */
	public function purge($extension)
	{
		$removed = ['rows' => 0, 'recordings' => 0];
		$extension = trim((string) $extension);

		// The match below is a set of ORs against columns that are empty on
		// plenty of rows, so an extension that is not a number would not
		// select this extension's history: it would select the whole table.
		// The length allows for extensions Core made as well as this module's.
		if (!preg_match('/^[0-9]{1,20}$/', $extension)) {
			$this->logError('refusing to purge the call history for "' . $extension . '", which is not a number');

			return $removed;
		}

		$cdrdb = $this->handle('purge ' . $extension . ' from');

		if ($cdrdb === null) {
			return $removed;
		}

		$tables = $this->tables($cdrdb);
		$complete = true;

		// Which calls the extension was part of
		$calls = $this->findCalls($cdrdb, $tables, $extension);

		if (!$calls) {
			$this->logInfo('no call history found for ' . $extension);

			return $removed;
		}

		// What those calls recorded, read before the records naming it go,
		// because a call detail record is the only index into its audio
		$recordings = $this->findRecordings($cdrdb, $tables, $calls);

		// The events first, then the records. A record whose events have
		// gone is still a record; an event whose record has gone is not
		// reachable by anything, so this is the order that fails better.
		if ($this->tableExists($cdrdb, 'cel')) {
			$removed['rows'] += $this->deleteCalls($cdrdb, 'cel', $calls, $complete);
		}

		foreach ($tables as $table) {
			$removed['rows'] += $this->deleteCalls($cdrdb, $table, $calls, $complete);
		}

		// The audio goes last, and only once the records that named it are
		// actually gone. A deletion that failed leaves records still
		// standing, and those records still need something to play.
		if ($complete) {
			foreach ($recordings as $file => $ignored) {
				if ($this->recordingIsOrphaned($cdrdb, $tables, $file) && $this->deleteRecording($file)) {
					$removed['recordings']++;
				}
			}
		} else {
			$this->logError('the call history for ' . $extension . ' was not fully removed, so its recordings have been left on disk');
		}

		$this->logInfo('removed ' . $removed['rows'] . ' call history rows and ' . $removed['recordings'] . ' recordings across ' . count($calls) . ' calls for ' . $extension);

		return $removed;
	}

	/**
	 * Find the calls an extension was part of.
	 *
	 * Both the record's own identifier and its chain identifier are
	 * collected; the chain is what reaches the call's other channels.
	 *
	 * @param object             $cdrdb     CDR database handle.
	 * @param array<int, string> $tables    Call detail tables to look in.
	 * @param int|string         $extension Number to find.
	 *
	 * @return array<string, bool> Call identifiers, as keys.
	 */
	private function findCalls($cdrdb, $tables, $extension)
	{
		$calls = [];

		foreach ($tables as $table) {
			$columns = $this->tableColumns($cdrdb, $table);
			$match = $this->matchClause($extension, $columns);
			$wanted = array_values(array_intersect(['uniqueid', 'linkedid'], $columns));

			if ($match === null || !$wanted) {
				continue; // nothing to match on, or nothing to collect
			}

			try {
				$sth = $cdrdb->prepare(
					'SELECT ' . implode(', ', array_map(function ($column) {
						return '`' . $column . '`';
					}, $wanted)) . ' FROM `' . $table . '` WHERE ' . $match['sql']
				);
				$sth->execute($match['params']);

				while ($row = $sth->fetch(\PDO::FETCH_ASSOC)) {
					foreach ($wanted as $column) {
						if (!empty($row[$column])) {
							$calls[$row[$column]] = true;
						}
					}
				}
			} catch (\Exception $e) {
				$this->logWarning('unable to read ' . $table . ' for ' . $extension . ': ' . $e->getMessage());
			}
		}

		return $calls;
	}

	/**
	 * Find the recordings a set of calls made.
	 *
	 * @param object                $cdrdb  CDR database handle.
	 * @param array<int, string>    $tables Call detail tables to look in.
	 * @param array<string, bool>   $calls  Call identifiers, as keys.
	 *
	 * @return array<string, bool> Recording file names, as keys.
	 */
	private function findRecordings($cdrdb, $tables, $calls)
	{
		$recordings = [];

		foreach ($tables as $table) {
			$columns = $this->tableColumns($cdrdb, $table);

			if (!in_array('recordingfile', $columns, true)) {
				continue; // this table never names a recording
			}

			foreach ($this->callBatches($calls, $columns) as $batch) {
				try {
					$sth = $cdrdb->prepare(
						'SELECT DISTINCT recordingfile FROM `' . $table . '`'
							. ' WHERE (' . $batch['sql'] . ") AND recordingfile <> ''"
					);
					$sth->execute($batch['params']);

					while (($file = $sth->fetchColumn()) !== false) {
						if ((string) $file !== '') {
							$recordings[$file] = true;
						}
					}
				} catch (\Exception $e) {
					$this->logWarning('unable to read recordings from ' . $table . ': ' . $e->getMessage());
				}
			}
		}

		return $recordings;
	}

	/**
	 * Delete every row belonging to a set of calls, in any table carrying both identifiers.
	 *
	 * @param object              $cdrdb    CDR database handle.
	 * @param string              $table    Table to clear.
	 * @param array<string, bool> $calls    Call identifiers, as keys.
	 * @param bool                $complete Set to false when a delete fails.
	 *
	 * @return int How many rows were removed.
	 */
	private function deleteCalls($cdrdb, $table, $calls, &$complete)
	{
		$columns = $this->tableColumns($cdrdb, $table);
		$rows = 0;

		foreach ($this->callBatches($calls, $columns) as $batch) {
			$rows += $this->runUpdate(
				$cdrdb,
				'DELETE FROM `' . $table . '` WHERE ' . $batch['sql'],
				$batch['params'],
				$failed
			);

			if ($failed) {
				$complete = false;
			}
		}

		return $rows;
	}

	/**
	 * Break a set of calls into batched conditions a statement can carry.
	 *
	 * @param array<string, bool> $calls   Call identifiers, as keys.
	 * @param array<int, string>  $columns Columns the table has.
	 *
	 * @return array<int, array{sql: string, params: array<string, string>}>
	 *         One condition per batch, empty when there is nothing to match.
	 */
	private function callBatches($calls, $columns)
	{
		$keys = array_values(array_intersect(['uniqueid', 'linkedid'], $columns));

		if (!$calls || !$keys) {
			return [];
		}

		$batches = [];

		foreach (array_chunk(array_keys($calls), 250) as $batch) {
			$holders = [];
			$params = [];

			foreach ($batch as $index => $call) {
				$holders[] = ':c' . $index;
				$params[':c' . $index] = $call;
			}

			$in = ' IN (' . implode(', ', $holders) . ')';
			$clauses = [];

			foreach ($keys as $key) {
				$clauses[] = '`' . $key . '`' . $in;
			}

			$batches[] = ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
		}

		return $batches;
	}

	/**
	 * Report whether nothing points at a recording any more.
	 *
	 * A recording is named on every record of its call, so a surviving
	 * record may still name it. Unable to tell counts as in use: an orphaned
	 * file costs disk, a wrongly deleted one costs the call.
	 *
	 * @param object             $cdrdb  CDR database handle.
	 * @param array<int, string> $tables Call detail tables to look in.
	 * @param string             $file   Recording file name.
	 *
	 * @return bool True when no record names the recording.
	 */
	private function recordingIsOrphaned($cdrdb, $tables, $file)
	{
		foreach ($tables as $table) {
			if (!in_array('recordingfile', $this->tableColumns($cdrdb, $table), true)) {
				continue; // this table never names a recording
			}

			try {
				$sth = $cdrdb->prepare('SELECT 1 FROM `' . $table . '` WHERE recordingfile = ? LIMIT 1');
				$sth->execute([$file]);

				if ($sth->fetchColumn()) {
					return false;
				}
			} catch (\Exception $e) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete the audio a call recording left on disk.
	 *
	 * The path is built here from the date in the name; the row supplies a
	 * name only, so an unexpected value cannot reach outside the recordings
	 * directory.
	 *
	 * @param string $file Recording file name from the call detail record.
	 *
	 * @return bool True when a file was deleted.
	 */
	private function deleteRecording($file)
	{
		$file = basename(trim((string) $file));

		if ($file === '' || $file === '.' || $file === '..') {
			return false;
		}

		$parts = explode('-', $file);

		// type-destination-source-YYYYMMDD-HHMMSS-uniqueid.fmt
		if (!isset($parts[3]) || !preg_match('/^\d{8}/', $parts[3])) {
			return false;
		}

		$spool = \FreePBX::Config()->get('ASTSPOOLDIR');
		$base = rtrim((string) (\FreePBX::Config()->get('MIXMON_DIR') ?: $spool . '/monitor'), '/');

		$path = $base . '/' . substr($parts[3], 0, 4)
			. '/' . substr($parts[3], 4, 2)
			. '/' . substr($parts[3], 6, 2)
			. '/' . $file;

		if (!is_file($path)) {
			return false;
		}

		return @unlink($path);
	}

	/**
	 * List the call detail tables this system keeps.
	 *
	 * Both the configured table and the one the CDR module reports are asked
	 * for: with the CDR trigger running, the latter is the transient copy,
	 * which the trigger fills on insert only, so it must be handled in its
	 * own right.
	 *
	 * @param object $cdrdb CDR database handle.
	 *
	 * @return array<int, string> Tables that are actually there.
	 */
	private function tables($cdrdb)
	{
		$configured = [];

		try {
			$configured[] = (string) \FreePBX::Config()->get('CDRDBTABLENAME');
			$configured[] = (string) \FreePBX::Cdr()->getDbTable();
		} catch (\Exception $e) {
			// whatever could not be read is covered by the defaults below
		}

		$tables = array_unique(array_filter(array_merge(
			$configured,
			['cdr', 'transient_cdr', 'replicate_cdr']
		)));

		return array_values(array_filter($tables, function ($table) use ($cdrdb) {
			return $this->tableExists($cdrdb, $table);
		}));
	}

	/**
	 * Report whether a table is present in the CDR database.
	 *
	 * @param object $cdrdb CDR database handle.
	 * @param string $table Table to look for.
	 *
	 * @return bool True when the table is there.
	 */
	private function tableExists($cdrdb, $table)
	{
		try {
			$sth = $cdrdb->prepare('SHOW TABLES LIKE ?');
			$sth->execute([$table]);

			return (bool) $sth->fetchColumn();
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * List the columns a table actually has.
	 *
	 * A match clause is one condition, and naming a missing column fails it
	 * whole, so it is built only from columns that are really there.
	 *
	 * @param object $cdrdb CDR database handle.
	 * @param string $table Table to describe.
	 *
	 * @return array<int, string> Column names.
	 */
	private function tableColumns($cdrdb, $table)
	{
		// Asked once per table and kept, because the recording check asks for
		// the same answer once per recording
		if (isset($this->columns[$table])) {
			return $this->columns[$table];
		}

		try {
			$sth = $cdrdb->prepare('SHOW COLUMNS FROM `' . $table . '`');
			$sth->execute();

			$this->columns[$table] = $sth->fetchAll(\PDO::FETCH_COLUMN);
		} catch (\Exception $e) {
			return [];
		}

		return $this->columns[$table];
	}

	/**
	 * Build the condition that finds an extension in a call detail record.
	 *
	 * Only src and dst, matched exactly. A false match is not one row: each
	 * record found pulls in its whole call and chain from every table, so a
	 * caller id name or account code that reads as this number would delete
	 * somebody else's calls. The call's other channels are reached through
	 * the chain, which is why two columns are enough.
	 *
	 * @param int|string         $extension Number to find.
	 * @param array<int, string> $columns   Columns the table has.
	 *
	 * @return array{sql: string, params: array<string, mixed>}|null
	 *         The condition, or null when the table holds neither of them.
	 */
	private function matchClause($extension, $columns)
	{
		$clauses = [];
		$params = [];

		foreach (['src', 'dst'] as $column) {
			if (!in_array($column, $columns, true)) {
				continue;
			}

			$key = ':m' . count($params);
			$params[$key] = $extension;
			$clauses[] = '`' . $column . '` = ' . $key;
		}

		if (!$clauses) {
			return null;
		}

		return ['sql' => '(' . implode(' OR ', $clauses) . ')', 'params' => $params];
	}

	/**
	 * Swap one number for another inside a channel name column.
	 *
	 * Handles `PJSIP/1001-…`, `Local/1001@…` and `Local/FMPR-1001@…` (the
	 * form the CDR module's history query looks for as `%-1001@%`). Matching
	 * on the delimiters keeps 1001 from being found inside 11001 or the call
	 * identifier.
	 *
	 * @param object     $cdrdb  CDR database handle.
	 * @param string     $t      Quoted table name.
	 * @param string     $column Column to rewrite.
	 * @param int|string $old    Number being left behind.
	 * @param int|string $new    Number being moved to.
	 *
	 * @return int How many rows were rewritten.
	 */
	private function replaceInColumn($cdrdb, $t, $column, $old, $new)
	{
		$rows = 0;

		foreach ([['/', '-'], ['/', '@'], ['-', '@']] as $delimiters) {
			list($opens, $closes) = $delimiters;
			$needle = $opens . $old . $closes;

			$rows += $this->runUpdate(
				$cdrdb,
				'UPDATE ' . $t . ' SET `' . $column . '` = REPLACE(`' . $column . '`, :needle, :replacement)'
					. ' WHERE `' . $column . '` LIKE :match',
				[
					':needle' => $needle,
					':replacement' => $opens . $new . $closes,
					':match' => '%' . $needle . '%',
				]
			);
		}

		return $rows;
	}

	/**
	 * Run one call history update.
	 *
	 * A statement naming a missing column is logged and stepped over. A
	 * failure also returns zero, so $failed tells a deleting caller which
	 * of the two happened.
	 *
	 * @param object                $cdrdb  CDR database handle.
	 * @param string                $sql    Statement to run.
	 * @param array<string, mixed>  $params Values to bind.
	 * @param bool|null             $failed Set to whether the statement failed.
	 *
	 * @return int How many rows the statement changed.
	 */
	private function runUpdate($cdrdb, $sql, $params, &$failed = null)
	{
		$failed = false;

		try {
			$sth = $cdrdb->prepare($sql);
			$sth->execute($params);

			return $sth->rowCount();
		} catch (\Exception $e) {
			$failed = true;

			$this->logWarning('' . $sql . ': ' . $e->getMessage());

			return 0;
		}
	}
}
