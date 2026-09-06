<?php

namespace Oryk\Provisioner\Model;

use Oryk\Provisioner\Support\Arr;
use Oryk\Provisioner\Support\Json;
use Oryk\Provisioner\Support\Str;
use Oryk\Provisioner\Support\Token;

/**
 * A provisioned endpoint: an instance of a template plus the parameters needed
 * to render its configuration.
 */
class Device
{
	/** @var int */
	public $id = 0;

	/** @var string Stable, human readable key (alain-softphone). */
	public $identifier = '';

	/** @var string */
	public $name = '';

	/** @var int */
	public $templateId = 0;

	/** @var string FreePBX extension this device registers as. */
	public $extension = '';

	/** @var string Bare uppercase MAC. */
	public $mac = '';

	/** @var string */
	public $vendor = '';

	/** @var string */
	public $model = '';

	/** @var bool A disabled device never returns configuration. */
	public $enabled = true;

	/** @var string */
	public $token = '';

	/** @var array Device level overrides, highest priority. */
	public $parameters = array();

	/** @var string */
	public $notes = '';

	/** @var string|null */
	public $lastProvisionedAt = null;

	/** @var string|null */
	public $lastProvisionedIp = null;

	/** @var string|null */
	public $createdAt = null;

	/** @var string|null */
	public $updatedAt = null;

	/** @var Template|null Hydrated on demand by the repository. */
	public $template = null;

	/**
	 * @param array $row
	 * @return self
	 */
	public static function fromRow(array $row)
	{
		$device = new self();
		$device->id = isset($row['id']) ? (int) $row['id'] : 0;
		$device->identifier = isset($row['identifier']) ? (string) $row['identifier'] : '';
		$device->name = isset($row['name']) ? (string) $row['name'] : '';
		$device->templateId = isset($row['template_id']) ? (int) $row['template_id'] : 0;
		$device->extension = isset($row['extension']) ? (string) $row['extension'] : '';
		$device->mac = isset($row['mac']) ? (string) $row['mac'] : '';
		$device->vendor = isset($row['vendor']) ? (string) $row['vendor'] : '';
		$device->model = isset($row['model']) ? (string) $row['model'] : '';
		$device->enabled = !isset($row['enabled']) || !empty($row['enabled']);
		$device->token = isset($row['token']) ? (string) $row['token'] : '';
		$device->parameters = Arr::flatten(Json::decode(isset($row['parameters']) ? $row['parameters'] : ''));
		$device->notes = isset($row['notes']) ? (string) $row['notes'] : '';
		$device->lastProvisionedAt = isset($row['last_provisioned_at']) ? $row['last_provisioned_at'] : null;
		$device->lastProvisionedIp = isset($row['last_provisioned_ip']) ? $row['last_provisioned_ip'] : null;
		$device->createdAt = isset($row['created_at']) ? $row['created_at'] : null;
		$device->updatedAt = isset($row['updated_at']) ? $row['updated_at'] : null;

		return $device;
	}

	/**
	 * @param array $input
	 * @return self
	 */
	public static function fromArray(array $input)
	{
		$device = new self();
		$device->id = isset($input['id']) ? (int) $input['id'] : 0;
		$device->name = isset($input['name']) ? trim((string) $input['name']) : '';
		$device->identifier = isset($input['identifier']) ? Str::slug($input['identifier']) : '';
		$device->extension = isset($input['extension']) ? trim((string) $input['extension']) : '';
		$device->mac = isset($input['mac']) ? Str::normalizeMac($input['mac']) : '';
		$device->vendor = isset($input['vendor']) ? trim((string) $input['vendor']) : '';
		$device->model = isset($input['model']) ? trim((string) $input['model']) : '';
		$device->enabled = !array_key_exists('enabled', $input) || !empty($input['enabled']);
		$device->notes = isset($input['notes']) ? trim((string) $input['notes']) : '';
		$device->token = isset($input['token']) && Token::isWellFormed($input['token'])
			? strtolower((string) $input['token'])
			: '';
		$device->parameters = Arr::flatten(Arr::keyValue(isset($input['parameters']) ? $input['parameters'] : array()));

		if (isset($input['templateId'])) {
			$device->templateId = (int) $input['templateId'];
		} elseif (isset($input['template_id'])) {
			$device->templateId = (int) $input['template_id'];
		}

		if ($device->identifier === '') {
			$device->identifier = Str::slug($device->name);
		}

		return $device;
	}

	/**
	 * @return array
	 */
	public function toArray()
	{
		return array(
			'id'                => $this->id,
			'identifier'        => $this->identifier,
			'name'              => $this->name,
			'templateId'        => $this->templateId,
			'templateName'      => $this->template === null ? '' : $this->template->name,
			'templateSlug'      => $this->template === null ? '' : $this->template->slug,
			'extension'         => $this->extension,
			'mac'               => $this->mac,
			'vendor'            => $this->vendor,
			'model'             => $this->model,
			'enabled'           => $this->enabled,
			'token'             => $this->token,
			'parameters'        => $this->parameters,
			'notes'             => $this->notes,
			'lastProvisionedAt' => $this->lastProvisionedAt,
			'lastProvisionedIp' => $this->lastProvisionedIp,
			'createdAt'         => $this->createdAt,
			'updatedAt'         => $this->updatedAt,
		);
	}

	/**
	 * Export shape without the provisioning token, for use where credentials
	 * must not leak (see README - Security).
	 *
	 * @return array
	 */
	public function toSafeArray()
	{
		$data = $this->toArray();
		unset($data['token']);

		return $data;
	}

	/**
	 * @return array field => error
	 */
	public function validate()
	{
		$errors = array();

		if ($this->name === '') {
			$errors['name'] = 'A device name is required.';
		}

		if ($this->identifier === '') {
			$errors['identifier'] = 'A device identifier is required.';
		} elseif (!preg_match('/^[a-z0-9][a-z0-9\-]{0,99}$/', $this->identifier)) {
			$errors['identifier'] = 'Identifiers may only contain lowercase letters, numbers and dashes.';
		}

		if ($this->templateId <= 0) {
			$errors['templateId'] = 'Select a provisioning template.';
		}

		if ($this->mac !== '' && !Str::isMac($this->mac)) {
			$errors['mac'] = 'A MAC address must be 12 hexadecimal characters.';
		}

		if ($this->extension !== '' && !preg_match('/^[0-9A-Za-z_\-]{1,50}$/', $this->extension)) {
			$errors['extension'] = 'That extension does not look valid.';
		}

		return $errors;
	}

	/**
	 * Intrinsic device values exposed to templates as device.*
	 *
	 * @return array
	 */
	public function contextValues()
	{
		return array(
			'device.id'         => $this->id,
			'device.identifier' => $this->identifier,
			'device.name'       => $this->name,
			'device.mac'        => $this->mac,
			'device.mac_lower'  => strtolower($this->mac),
			'device.mac_colon'  => Str::formatMac($this->mac, ':'),
			'device.mac_dash'   => Str::formatMac($this->mac, '-'),
			'device.vendor'     => $this->vendor,
			'device.model'      => $this->model,
			'device.enabled'    => $this->enabled,
			'device.token'      => $this->token,
			'device.notes'      => $this->notes,
		);
	}
}
