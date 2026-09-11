<?php

// src/Enabled.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Being switched on, and being switched off.
 *
 * Two tables carry an `enabled` column -- clients and profiles -- and what
 * that column means is the same on both: the endpoint answers this row's
 * requests, or it answers none of them. So the reading of what a form said,
 * and the one-column write that acts on it, are here rather than written
 * twice and left to drift.
 *
 * A trait rather than methods on Service, for the reason Logs is one: this is
 * behaviour two repositories share, not something every subsystem is given.
 * Endpoint, Pages and Matcher have no rows to switch, and a protected
 * setEnabled() on the base class would be offered to all of them.
 *
 * What the trait does *not* hold is which row is being switched. Each
 * repository keeps its own existence check and its own refusal, because
 * "that client no longer exists" and "that profile no longer exists" are not
 * one sentence, and the row reader that answers the question already exists on
 * each of them.
 */
trait Enabled
{
	/**
	 * Switch one row on or off.
	 *
	 * **The state is sent, not toggled.** What arrives is what the row is to
	 * be, not an instruction to flip it, so the same request made twice
	 * leaves it where the first one put it -- and two tabs open on the same
	 * list cannot flip past each other. It is also why one method serves both
	 * the switch on the list and the Status field in the editor: they are
	 * saying the same thing, not doing two similar things.
	 *
	 * One column, and nothing else on the row is read -- so a token, a
	 * profile or a name cannot be lost to a write that was only ever about
	 * whether the endpoint answers.
	 *
	 * The table is interpolated and so may never come from a request: callers
	 * name it from the properties on Service, which is where the four table
	 * names in this module live.
	 *
	 * @param string               $table   Table to write, from Service's properties.
	 * @param array<string, mixed> $request id, and the state to put it in.
	 *
	 * @return array<string, mixed> Status, and the state the row is now in.
	 */
	protected function setEnabled($table, $request)
	{
		$id = (int) ($request['id'] ?? 0);
		$enabled = self::enabledFlag($request['enabled'] ?? 1);

		$stmt = $this->db->prepare(
			"UPDATE `$table` SET enabled = :enabled WHERE id = :id"
		);
		$stmt->execute([':enabled' => $enabled, ':id' => $id]);

		return ['status' => true, 'id' => $id, 'enabled' => $enabled];
	}

	/**
	 * What a save said about the row being enabled, or nothing at all.
	 *
	 * A field that was not submitted is not a field saying no: something
	 * writing a row without one -- a console command, a future import -- is
	 * writing a row to serve, which is what the column's default says too.
	 * Both editors do submit it, because the Status field is on the tab they
	 * post from.
	 *
	 * @param array<string, mixed> $request Submitted form values.
	 *
	 * @return int 1 or 0, for the column.
	 */
	protected static function enabledSubmitted($request)
	{
		return array_key_exists('enabled', $request)
			? self::enabledFlag($request['enabled'])
			: 1;
	}

	/**
	 * What a form said, as the column holds it.
	 *
	 * Everything arrives from a browser as a string, so the falsehood to
	 * recognise is the spelling of one rather than false itself -- '0' from a
	 * select, '' from a field that was not on the page, 'false' from a
	 * boolean jQuery serialised on the way out. Anything else is true,
	 * because the column's default is true and a value nobody meant should
	 * land where a value nobody sent lands.
	 *
	 * @param mixed $value What was submitted.
	 *
	 * @return int 1 or 0, for the column.
	 */
	protected static function enabledFlag($value)
	{
		$said = strtolower(trim((string) $value));

		return in_array($said, ['0', '', 'false', 'off', 'no'], true) ? 0 : 1;
	}
}
