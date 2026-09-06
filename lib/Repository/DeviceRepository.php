<?php

namespace Oryk\Provisioner\Repository;

use Oryk\Provisioner\Database\Schema;
use Oryk\Provisioner\Exception\ValidationException;
use Oryk\Provisioner\Model\Device;
use Oryk\Provisioner\Support\Json;
use Oryk\Provisioner\Support\Str;
use Oryk\Provisioner\Support\Token;

/**
 * Persistence for provisioned devices.
 */
class DeviceRepository extends BaseRepository
{
	/** @var TemplateRepository */
	private $templates;

	/** @var int Token length used when generating new tokens. */
	private $tokenLength = Token::DEFAULT_LENGTH;

	public function __construct($pdo = null, ?TemplateRepository $templates = null)
	{
		parent::__construct($pdo);
		$this->templates = $templates === null ? new TemplateRepository($this->pdo) : $templates;
	}

	public function setTokenLength($length)
	{
		$this->tokenLength = (int) $length;

		return $this;
	}

	/**
	 * @param array $filters search, templateId, enabled, extension
	 * @return Device[]
	 */
	public function all(array $filters = array())
	{
		$sql = 'SELECT d.*, t.name AS template_name, t.slug AS template_slug
				FROM `' . Schema::TABLE_DEVICES . '` d
				LEFT JOIN `' . Schema::TABLE_TEMPLATES . '` t ON t.id = d.template_id';

		$where = array();
		$bindings = array();

		if (isset($filters['search']) && $filters['search'] !== '') {
			$where[] = '(d.name LIKE :search OR d.identifier LIKE :search OR d.extension LIKE :search OR d.mac LIKE :search)';
			$bindings[':search'] = '%' . $filters['search'] . '%';
		}

		if (!empty($filters['templateId'])) {
			$where[] = 'd.template_id = :template_id';
			$bindings[':template_id'] = (int) $filters['templateId'];
		}

		if (isset($filters['enabled']) && $filters['enabled'] !== '' && $filters['enabled'] !== null) {
			$where[] = 'd.enabled = :enabled';
			$bindings[':enabled'] = $filters['enabled'] ? 1 : 0;
		}

		if (isset($filters['extension']) && $filters['extension'] !== '') {
			$where[] = 'd.extension = :extension';
			$bindings[':extension'] = (string) $filters['extension'];
		}

		if (!empty($where)) {
			$sql .= ' WHERE ' . implode(' AND ', $where);
		}

		$sql .= ' ORDER BY d.name ASC';

		$devices = array();

		foreach ($this->select($sql, $bindings) as $row) {
			$device = Device::fromRow($row);
			$device->template = null;
			$devices[] = $this->attachTemplateSummary($device, $row);
		}

		return $devices;
	}

	/**
	 * @param bool $withTemplate Hydrate the full template (outputs + schema).
	 * @return Device|null
	 */
	public function find($id, $withTemplate = true)
	{
		$row = $this->selectOne(
			'SELECT * FROM `' . Schema::TABLE_DEVICES . '` WHERE id = :id',
			array(':id' => (int) $id)
		);

		return $this->hydrate($row, $withTemplate);
	}

	/**
	 * @return Device|null
	 */
	public function findByIdentifier($identifier, $withTemplate = true)
	{
		$row = $this->selectOne(
			'SELECT * FROM `' . Schema::TABLE_DEVICES . '` WHERE identifier = :identifier',
			array(':identifier' => (string) $identifier)
		);

		return $this->hydrate($row, $withTemplate);
	}

	/**
	 * Look a device up by provisioning token.
	 *
	 * Malformed tokens never reach the database, and the comparison is done in
	 * constant time once the row is loaded.
	 *
	 * @return Device|null
	 */
	public function findByToken($token, $withTemplate = true)
	{
		$token = (string) $token;

		if (!Token::isWellFormed($token)) {
			return null;
		}

		$row = $this->selectOne(
			'SELECT * FROM `' . Schema::TABLE_DEVICES . '` WHERE token = :token',
			array(':token' => strtolower($token))
		);

		if ($row === null || !Token::equals($row['token'], strtolower($token))) {
			return null;
		}

		return $this->hydrate($row, $withTemplate);
	}

	/**
	 * Insert or update a device. A new device always receives a fresh token.
	 *
	 * @throws ValidationException
	 * @return Device
	 */
	public function save(Device $device)
	{
		$errors = $device->validate();

		if (empty($errors) && $this->templates->find($device->templateId) === null) {
			$errors['templateId'] = 'That provisioning template no longer exists.';
		}

		if (!empty($errors)) {
			throw ValidationException::withErrors($errors);
		}

		$device->identifier = $this->uniqueIdentifier($device->identifier, $device->id);

		if ($device->token === '') {
			$device->token = $this->generateToken();
		}

		$now = $this->now();
		$bindings = array(
			':identifier'  => $device->identifier,
			':name'        => $device->name,
			':template_id' => $device->templateId,
			':extension'   => $device->extension,
			':mac'         => $device->mac,
			':vendor'      => $device->vendor,
			':model'       => $device->model,
			':enabled'     => $device->enabled ? 1 : 0,
			':token'       => $device->token,
			':parameters'  => Json::encode($device->parameters),
			':notes'       => $device->notes,
			':updated_at'  => $now,
		);

		if ($device->id > 0) {
			$bindings[':id'] = $device->id;
			$this->execute(
				'UPDATE `' . Schema::TABLE_DEVICES . '` SET
					identifier = :identifier, name = :name, template_id = :template_id,
					extension = :extension, mac = :mac, vendor = :vendor, model = :model,
					enabled = :enabled, token = :token, parameters = :parameters,
					notes = :notes, updated_at = :updated_at
				 WHERE id = :id',
				$bindings
			);
		} else {
			$bindings[':created_at'] = $now;
			$this->execute(
				'INSERT INTO `' . Schema::TABLE_DEVICES . '`
					(identifier, name, template_id, extension, mac, vendor, model, enabled, token, parameters, notes, created_at, updated_at)
				 VALUES
					(:identifier, :name, :template_id, :extension, :mac, :vendor, :model, :enabled, :token, :parameters, :notes, :created_at, :updated_at)',
				$bindings
			);
			$device->id = (int) $this->pdo->lastInsertId();
		}

		return $this->find($device->id);
	}

	public function delete($id)
	{
		$this->execute('DELETE FROM `' . Schema::TABLE_DEVICES . '` WHERE id = :id', array(':id' => (int) $id));

		return true;
	}

	/**
	 * Enable or disable a device. A disabled device stops provisioning at once
	 * without its configuration or token being lost.
	 */
	public function setEnabled($id, $enabled)
	{
		$this->execute(
			'UPDATE `' . Schema::TABLE_DEVICES . '` SET enabled = :enabled, updated_at = :updated_at WHERE id = :id',
			array(':enabled' => $enabled ? 1 : 0, ':updated_at' => $this->now(), ':id' => (int) $id)
		);

		return $this->find($id, false);
	}

	/**
	 * Issue a new token, immediately invalidating the previous provisioning URL.
	 *
	 * @return Device|null
	 */
	public function regenerateToken($id)
	{
		$this->execute(
			'UPDATE `' . Schema::TABLE_DEVICES . '` SET token = :token, updated_at = :updated_at WHERE id = :id',
			array(':token' => $this->generateToken(), ':updated_at' => $this->now(), ':id' => (int) $id)
		);

		return $this->find($id, false);
	}

	/**
	 * Record a successful provisioning request against the device.
	 */
	public function touchProvisioned($id, $ip)
	{
		$this->execute(
			'UPDATE `' . Schema::TABLE_DEVICES . '`
			 SET last_provisioned_at = :at, last_provisioned_ip = :ip WHERE id = :id',
			array(':at' => $this->now(), ':ip' => Str::limit((string) $ip, 45), ':id' => (int) $id)
		);
	}

	/**
	 * Produce an identifier that is not taken by another device.
	 */
	public function uniqueIdentifier($identifier, $ignoreId = 0)
	{
		$identifier = Str::slug($identifier, 'device');
		$candidate = $identifier;
		$suffix = 2;

		while ($this->identifierTaken($candidate, $ignoreId)) {
			$candidate = $identifier . '-' . $suffix;
			$suffix++;
		}

		return $candidate;
	}

	/**
	 * A token that is guaranteed to be free.
	 */
	public function generateToken()
	{
		do {
			$token = Token::generate($this->tokenLength);
			$row = $this->selectOne(
				'SELECT id FROM `' . Schema::TABLE_DEVICES . '` WHERE token = :token',
				array(':token' => $token)
			);
		} while ($row !== null);

		return $token;
	}

	public function countAll()
	{
		$row = $this->selectOne('SELECT COUNT(*) AS total FROM `' . Schema::TABLE_DEVICES . '`');

		return $row === null ? 0 : (int) $row['total'];
	}

	private function identifierTaken($identifier, $ignoreId)
	{
		$row = $this->selectOne(
			'SELECT id FROM `' . Schema::TABLE_DEVICES . '` WHERE identifier = :identifier AND id <> :id',
			array(':identifier' => $identifier, ':id' => (int) $ignoreId)
		);

		return $row !== null;
	}

	/**
	 * @return Device|null
	 */
	private function hydrate($row, $withTemplate)
	{
		if ($row === null) {
			return null;
		}

		$device = Device::fromRow($row);

		if ($withTemplate && $device->templateId > 0) {
			$device->template = $this->templates->find($device->templateId);
		}

		return $device;
	}

	/**
	 * List views only need the template name/slug, not its outputs.
	 */
	private function attachTemplateSummary(Device $device, array $row)
	{
		if (empty($row['template_name'])) {
			return $device;
		}

		$template = new \Oryk\Provisioner\Model\Template();
		$template->id = $device->templateId;
		$template->name = (string) $row['template_name'];
		$template->slug = (string) $row['template_slug'];
		$device->template = $template;

		return $device;
	}
}
