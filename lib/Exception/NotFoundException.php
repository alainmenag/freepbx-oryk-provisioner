<?php

namespace Oryk\Provisioner\Exception;

/**
 * Thrown whenever a provisioning request cannot be satisfied: unknown token,
 * disabled device, unknown filename, missing template.
 *
 * The endpoint always answers 404 for these so the response never reveals
 * whether a device exists.
 */
class NotFoundException extends ProvisionerException
{
	/** @var string Internal reason, logged but never returned to the endpoint. */
	protected $reason = '';

	public function __construct($message = 'Not found', $reason = '')
	{
		parent::__construct($message);
		$this->reason = $reason !== '' ? $reason : $message;
	}

	public function getReason()
	{
		return $this->reason;
	}
}
