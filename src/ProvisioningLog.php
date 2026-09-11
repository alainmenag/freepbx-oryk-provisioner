<?php

// src/ProvisioningLog.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * One row per request the endpoint answered.
 *
 * Metadata only -- who asked, what for, and how it went. Never the
 * rendered body, which carries device.secret whenever a template asks for
 * it. Nothing in here may fail a request: a phone whose configuration is
 * ready does not go without it because the log table is missing.
 */
class ProvisioningLog extends Service
{
	/**
	 * Rows for a Logs table.
	 *
	 * One statement asked two ways, the way listClients is: every request on the
	 * module page, and one client's own on the client editor's Logs tab.
	 *
	 * It asks with a MAC rather than a client id, and that is the point. Rows are
	 * written for a MAC whether or not a client exists, so the requests a phone
	 * made before somebody wrote its client are on that client's tab the moment it
	 * exists -- the run of 404s that says what it has been asking for all along.
	 *
	 * Newest first unless asked otherwise, with the id breaking the tie: a phone
	 * that has just booted asks for six files inside one second.
	 *
	 * @return array<string, mixed> Total row count and the page of rows.
	 */
	public function listLogs()
	{
		$sortable = [
			'created_at' => 'l.created_at',
			'mac' => 'l.mac',
			'filename' => 'l.filename',
			'status' => 'l.status',
			'ip' => 'l.ip',
		];

		$sort = $sortable[(string) ($_REQUEST['sort'] ?? '')] ?? $sortable['created_at'];

		// The other way round from every other table here: a log is read from
		// the end, so anything that is not explicitly ascending is descending.
		$order = strtolower((string) ($_REQUEST['order'] ?? '')) === 'asc' ? 'ASC' : 'DESC';

		$limit = (int) ($_REQUEST['limit'] ?? 10);
		$offset = (int) ($_REQUEST['offset'] ?? 0);
		$search = (string) ($_REQUEST['search'] ?? '');

		$params = [];
		$clauses = [];

		if (isset($_REQUEST['mac'])) {
			$clauses[] = 'l.mac = :mac';
			$params[':mac'] = Mac::stored($_REQUEST['mac']);
		}

		if ($search !== '') {
			$clauses[] = "(l.mac LIKE :search
				OR l.filename LIKE :search
				OR l.message LIKE :search
				OR l.ip LIKE :search
				OR l.user_agent LIKE :search)";
			$params[':search'] = '%' . $search . '%';
		}

		$where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

		// Joined on the MAC rather than on an id stored in the row, for the
		// reason there is no such id: which client a MAC belongs to is a
		// question about now, not about when the request came in.
		$from = "FROM `{$this->logsTable}` l
			LEFT JOIN `{$this->clientsTable}` pc ON pc.mac = l.mac
			LEFT JOIN devices d ON d.id = pc.device_id";

		try {
			$countStmt = $this->db->prepare("SELECT COUNT(*) $from $where");
			$countStmt->execute($params);
			$total = (int) $countStmt->fetchColumn();

			$sql = "
				SELECT
					l.id,
					l.mac,
					l.filename,
					l.status,
					l.message,
					l.method,
					l.ip,
					l.user_agent,
					l.created_at,
					pc.id AS client_id,
					d.description AS description
				$from
				$where
				ORDER BY $sort $order, l.id DESC
				LIMIT :limit OFFSET :offset
			";

			$stmt = $this->db->prepare($sql);
			foreach ($params as $key => $value) {
				$stmt->bindValue($key, $value);
			}
			$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
			$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
			$stmt->execute();

			$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
		} catch (\Exception $e) {
			// The table arrives with install(); a module upgraded without it
			// is a module whose log is empty, not one whose pages are broken.
			$this->log('oryk_provisioner: could not read the provisioning log', $e->getMessage(), 'WARNING');

			return ['total' => 0, 'rows' => []];
		}

		return [
			'total' => $total,
			'rows' => $rows,
		];
	}

	/**
	 * Empty the log, or one client's part of it.
	 *
	 * Narrowed the same way the table is. A provisioning log grows by a row per
	 * file per boot per phone and nothing prunes it, so this is the only thing
	 * standing between a busy site and a table larger than the rest of the module.
	 *
	 * @param array<string, mixed> $request Submitted values; `mac` narrows it.
	 *
	 * @return array<string, mixed> Status of the removal.
	 */
	public function clearLogs($request)
	{
		try {
			if (isset($request['mac'])) {
				$mac = Mac::stored($request['mac']);

				if ($mac === '') {
					return ['status' => false, 'message' => _('No MAC address to clear the log for.')];
				}

				$stmt = $this->db->prepare("DELETE FROM `{$this->logsTable}` WHERE mac = :mac");
				$stmt->execute([':mac' => $mac]);

				return ['status' => true];
			}

			// A DELETE rather than the TRUNCATE that would be quicker: TRUNCATE
			// is DDL, it commits whatever transaction it lands in, and this is
			// a button on a page rather than a maintenance job.
			$this->db->exec("DELETE FROM `{$this->logsTable}`");
		} catch (\Exception $e) {
			return ['status' => false, 'message' => _('The provisioning log could not be cleared.')];
		}

		return ['status' => true];
	}

	/**
	 * Record one provisioning request.
	 *
	 * Written on the way out of serve(), whichever way that went, and by the
	 * endpoint itself for the requests that never reach serve() at all. A request
	 * nobody can answer is the one worth having a record of.
	 *
	 * Metadata only, which is why there is no column for the rendered body: it
	 * carries device.secret whenever a template asks for it. Which resource
	 * answered is not kept either -- the filename as the phone spelled it is the
	 * fact of the request, where which file of which profile it reached is a
	 * question the profile may answer differently tomorrow.
	 *
	 * Nothing in here may fail a request.
	 *
	 * @param mixed       $mac       MAC address, written however it was written.
	 * @param string|null $requested Filename asked for, '' when none was.
	 * @param int         $status    HTTP status the request was answered with.
	 * @param string|null $message   Why, when it was not answered with a file.
	 *
	 * @return void
	 */
	public function logRequest($mac, $requested, $status, $message = null)
	{
		try {
			$stmt = $this->db->prepare(
				"INSERT INTO `{$this->logsTable}`
					(mac, filename, status, message, method, ip, user_agent)
				VALUES (:mac, :filename, :status, :message, :method, :ip, :user_agent)"
			);
			$stmt->execute([
				':mac' => $this->clip(Mac::stored($mac), 64),
				':filename' => $this->clip($requested, 255),
				':status' => (int) $status,
				':message' => $message === null ? null : $this->clip($message, 255),
				':method' => $this->clip($_SERVER['REQUEST_METHOD'] ?? '', 10),
				':ip' => $this->clip($_SERVER['REMOTE_ADDR'] ?? '', 45) ?: null,
				':user_agent' => $this->clip($_SERVER['HTTP_USER_AGENT'] ?? '', 255) ?: null,
			]);
		} catch (\Exception $e) {
			// The log is the one thing here that is allowed to go missing.
			$this->log('oryk_provisioner: could not write the provisioning log', $e->getMessage(), 'WARNING');
		}
	}

	/**
	 * A value cut to what its column holds.
	 *
	 * Everything logged comes off the wire, so none of it has a length anyone here
	 * decided. Cut rather than refused: a truncated User-Agent still says which
	 * phone asked, and a row that failed to insert says nothing at all.
	 *
	 * @param mixed $value  Value as it arrived.
	 * @param int   $length Characters the column takes.
	 *
	 * @return string The value, or as much of it as fits.
	 */
	private function clip($value, $length)
	{
		$value = (string) $value;

		return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
	}
}
