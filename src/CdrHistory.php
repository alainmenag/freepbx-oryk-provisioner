<?php

// src/CdrHistory.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The call history belonging to an extension.
 *
 * Nothing in FreePBX moves or removes this. A call detail record keeps the
 * number as it stood when the call was placed, the CDR module subscribes to
 * no core hook, and there is no renumbering of its own to hook into, so an
 * extension that is renumbered leaves its history on a number that no
 * longer answers, and one that is deleted leaves a set of records belonging
 * to somebody who no longer exists -- records that come back into view the
 * moment the number is reissued.
 *
 * Which tables exist and which columns they have is up to the site: the
 * transient copy only appears once the CDR trigger is set up, channel event
 * logging is optional, and the columns move with the FreePBX version. So
 * both of the operations here work out what is actually in front of them
 * rather than assuming, and step over a statement that names a column this
 * site does not have instead of abandoning the rest of the work.
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
	 * Both of the operations here begin the same way, and the reason is the
	 * same for both: the CDR module keeps its own database handle, which may
	 * be a different server from the one everything else uses, and the site
	 * may not have the module at all.
	 *
	 * The time limit goes with it. Of the columns these statements match on
	 * only dst and dstchannel are indexed, so on a system with a long history
	 * they read the table end to end. Being cut off half way through leaves
	 * the history in a worse state than either finishing or never starting --
	 * split across two numbers, or half deleted -- so this is allowed to take
	 * as long as it takes.
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
	 * Nothing in FreePBX does this. A call detail record keeps the number as
	 * it stood when the call was placed, the CDR module subscribes to no
	 * core hook, and FreePBX has no renumbering of its own to hook into, so
	 * the history is rewritten here or it stays behind on a number that no
	 * longer answers.
	 *
	 * What is rewritten is what the reports read: the CDR report matches on
	 * src, dst, cnum and the two channel names, and displays clid. Recording
	 * file names carry the extension as well and are deliberately left
	 * alone, because the name has to keep matching the file on disk or the
	 * recording stops being playable.
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
	 * A deleted Extension/User leaves its records behind, because nothing in
	 * FreePBX removes them: the CDR module subscribes to no core hook and
	 * call detail records outlive the extension that made them. Left alone
	 * they are a set of records belonging to somebody who no longer exists,
	 * and they come back into view the moment the number is reissued.
	 *
	 * What goes is worked out from the call detail records and applied to
	 * both tables. The records naming the extension are found first, and the
	 * call identifiers on them -- the identifier of the record itself, and
	 * the identifier of the chain it belongs to -- are what everything else
	 * is deleted by. A call is more than one row in both tables: the sample
	 * of a plain extension-to-extension call has one call detail record and
	 * fifteen events across two channels, and only one of those two channels
	 * carries the record's own identifier. Deleting by the chain is what
	 * takes the other one with it.
	 *
	 * A call between two extensions belongs to both of them, and one of the
	 * two may still be in service. It is removed all the same: the point of
	 * this is that the deleted extension leaves nothing behind, and half a
	 * call naming only the surviving party would be a record of a call with
	 * nobody. The surviving extension loses those calls from its own history
	 * as a consequence, and there is no undo.
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
	 * Both identifiers are collected from every matching record: the
	 * identifier of the record itself, and the identifier of the chain it
	 * belongs to. The second is what reaches the other channels of the same
	 * call, which carry an identifier of their own and would otherwise be
	 * left behind.
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
	 * Delete every row belonging to a set of calls.
	 *
	 * Used for the events and for the records alike: both tables carry the
	 * same two identifiers, so both are cleared the same way.
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
	 * Break a set of calls into conditions a statement can carry.
	 *
	 * A busy extension is a great many calls, and one statement naming all
	 * of them at once is a statement no database will take, so they are
	 * handed out in batches.
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
	 * A recording belongs to a call rather than to one leg of it, and its
	 * name is written onto every record of that call, so one named by the
	 * records that have just gone may still be named by a record that stayed.
	 * Being unable to tell counts as still in use: an orphaned file costs
	 * disk, and a wrongly deleted one costs the call.
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
	 * Recordings are filed by the date in their own name rather than by the
	 * call, which is how the CDR module finds them to play. The name is
	 * taken as a name and nothing else -- the path is built here -- so a row
	 * carrying something unexpected cannot reach outside the recordings
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
	 * The main table is named in Advanced Settings, and the name the CDR
	 * module reports is the one it is currently reading, which on a system
	 * running the CDR trigger is the transient copy rather than the
	 * configured table. Both are asked for, and the defaults kept alongside.
	 *
	 * The transient copy exists because the trigger behind it only fires on
	 * insert, so it is a second set of the same rows that nothing else
	 * maintains and every one of them has to be handled in its own right.
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
	 * Which of the CDR tables exist depends on the site: the transient copy
	 * only appears once the CDR trigger has been set up, and channel event
	 * logging is optional.
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
	 * Which columns are present varies with the FreePBX version and with the
	 * optional modules a site has installed. A statement naming a column
	 * that is not there fails as a whole, and unlike the rewrites -- which
	 * run one column at a time and can afford to lose one -- a match clause
	 * is a single condition, so it is built from what is really there.
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
	 * Two columns, matched exactly: the two ends of the call. Nothing else a
	 * record holds is matched on directly, and the reason is that a false
	 * match here is not one row. Each record found contributes the call it
	 * is and the chain it belongs to, and everything carrying either is
	 * deleted from both tables -- so a caller id name that happens to read
	 * as this number, or an account code a site uses for a tenant, would
	 * take whole calls belonging to somebody else with it.
	 *
	 * The rest of a call is reached through those identifiers rather than by
	 * matching, which is what makes two columns enough: the other channels
	 * of the same call carry identifiers of their own and are found through
	 * the chain, not through the number.
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
	 * A channel name carries the number between the technology and the call
	 * identifier, in three shapes: `PJSIP/1001-0000abcd`,
	 * `Local/1001@from-internal-0000abcd`, and the follow-me channel
	 * `Local/FMPR-1001@findmefollow-ringallv2-0000abcd`, which is the one the
	 * CDR module's own history query looks for as `%-1001@%`. Matching on the
	 * delimiters either side is what keeps 1001 from being found inside 11001
	 * or inside the call identifier that follows it.
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
	 * The columns present in the CDR database vary with the FreePBX version
	 * and with which optional modules the site has installed, so a statement
	 * naming a column that is not there is logged and stepped over rather
	 * than being allowed to abandon the rest of the move.
	 *
	 * A caller that is deleting rather than rewriting cannot read a return
	 * of zero as nothing to do, because a statement that failed returns zero
	 * as well, so it is told which of the two happened.
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
