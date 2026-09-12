<?php

// src/Enabled.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * Being switched on, and being switched off.
 *
 * Two tables carry an `enabled` column -- clients and profiles -- and it means
 * the same on both: the endpoint answers this row's requests, or none of them.
 * So the reading of what a form said and the one-column write that acts on it
 * are here rather than written twice.
 *
 * A trait rather than methods on Service, for the reason Logs is one: this is
 * behaviour two repositories share, not something every subsystem is given.
 *
 * What the trait does *not* hold is which row is being switched. Each
 * repository keeps its own existence check and its own refusal, because "that
 * client no longer exists" and "that profile no longer exists" are not one
 * sentence.
 */
trait Enabled
{
	/**
	 * Switch one row on or off.
	 *
	 * **The state is sent, not toggled.** What arrives is what the row is to be,
	 * so the same request twice leaves it where the first one put it and two tabs
	 * open on the same list cannot flip past each other. It is also why one method
	 * serves both the switch on the list and the Status field in the editor.
	 *
	 * One column, and nothing else on the row is read -- so a token, a profile or
	 * a name cannot be lost to a write that was only ever about whether the
	 * endpoint answers.
	 *
	 * **The table is interpolated and may never come from a request**: callers
	 * name it from the properties on Service.
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
	 * A field that was not submitted is not a field saying no: something writing a
	 * row without one -- a console command, a future import -- is writing a row to
	 * serve, which is what the column's default says too.
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
	 * Everything arrives from a browser as a string, so the falsehood to recognise
	 * is the spelling of one rather than false itself -- '0' from a select, '' from
	 * a field that was not on the page, 'false' from a boolean jQuery serialised
	 * on the way out. Anything else is true, because the column's default is.
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
