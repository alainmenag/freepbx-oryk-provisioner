<?php

namespace Oryk\Provisioner\Repository;

use Oryk\Provisioner\Database\Schema;
use Oryk\Provisioner\Support\Str;

/**
 * Provisioning request log.
 *
 * Only metadata is recorded - never rendered configuration and never resolved
 * parameter values (see README - Security).
 */
class LogRepository extends BaseRepository
{
	const STATUS_SUCCESS  = 'success';
	const STATUS_DENIED   = 'denied';
	const STATUS_NOTFOUND = 'notfound';
	const STATUS_ERROR    = 'error';

	/**
	 * Record one provisioning attempt.
	 *
	 * @param array $entry deviceId, identifier, filename, status, httpCode, message, ip, userAgent
	 */
	public function record(array $entry)
	{
		try {
			$this->execute(
				'INSERT INTO `' . Schema::TABLE_LOGS . '`
					(created_at, device_id, identifier, filename, status, http_code, message, ip, user_agent)
				 VALUES (:created_at, :device_id, :identifier, :filename, :status, :http_code, :message, :ip, :user_agent)',
				array(
					':created_at' => $this->now(),
					':device_id'  => empty($entry['deviceId']) ? null : (int) $entry['deviceId'],
					':identifier' => Str::limit(isset($entry['identifier']) ? $entry['identifier'] : '', 100),
					':filename'   => Str::limit(isset($entry['filename']) ? $entry['filename'] : '', 190),
					':status'     => Str::limit(isset($entry['status']) ? $entry['status'] : '', 20),
					':http_code'  => isset($entry['httpCode']) ? (int) $entry['httpCode'] : 0,
					':message'    => Str::limit(isset($entry['message']) ? $entry['message'] : '', 255),
					':ip'         => Str::limit(isset($entry['ip']) ? $entry['ip'] : '', 45),
					':user_agent' => Str::limit(isset($entry['userAgent']) ? $entry['userAgent'] : '', 255),
				)
			);
		} catch (\Exception $e) {
			// Logging must never break a provisioning response.
		}
	}

	/**
	 * @param array $filters limit, status, deviceId, search
	 * @return array
	 */
	public function recent(array $filters = array())
	{
		$limit = isset($filters['limit']) ? (int) $filters['limit'] : 200;
		$limit = max(1, min(1000, $limit));

		$sql = 'SELECT * FROM `' . Schema::TABLE_LOGS . '`';
		$where = array();
		$bindings = array();

		if (!empty($filters['status'])) {
			$where[] = 'status = :status';
			$bindings[':status'] = (string) $filters['status'];
		}

		if (!empty($filters['deviceId'])) {
			$where[] = 'device_id = :device_id';
			$bindings[':device_id'] = (int) $filters['deviceId'];
		}

		if (!empty($filters['search'])) {
			$where[] = '(identifier LIKE :search OR filename LIKE :search OR ip LIKE :search)';
			$bindings[':search'] = '%' . $filters['search'] . '%';
		}

		if (!empty($where)) {
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}

		$sql .= ' ORDER BY id DESC LIMIT ' . $limit;

		return $this->select($sql, $bindings);
	}

	/**
	 * Remove every log entry.
	 */
	public function clear()
	{
		$this->execute('DELETE FROM `' . Schema::TABLE_LOGS . '`');

		return true;
	}

	/**
	 * Drop entries older than the retention window.
	 *
	 * @param int $days 0 disables pruning.
	 */
	public function prune($days)
	{
		$days = (int) $days;

		if ($days <= 0) {
			return 0;
		}

		$statement = $this->execute(
			'DELETE FROM `' . Schema::TABLE_LOGS . '` WHERE created_at < :cutoff',
			array(':cutoff' => date('Y-m-d H:i:s', time() - ($days * 86400)))
		);

		return $statement->rowCount();
	}

	/**
	 * Counts per status for the dashboard.
	 *
	 * @return array
	 */
	public function stats($since = '-24 hours')
	{
		$rows = $this->select(
			'SELECT status, COUNT(*) AS total FROM `' . Schema::TABLE_LOGS . '`
			 WHERE created_at >= :since GROUP BY status',
			array(':since' => date('Y-m-d H:i:s', strtotime($since)))
		);

		$stats = array();

		foreach ($rows as $row) {
			$stats[$row['status']] = (int) $row['total'];
		}

		return $stats;
	}
}
