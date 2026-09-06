<?php

namespace Oryk\Provisioner\Exception;

/**
 * Thrown by models/repositories when submitted data is not valid. Carries a
 * per-field error map so the admin interface can highlight inputs.
 */
class ValidationException extends ProvisionerException
{
	/** @var array field => message */
	protected $errors = array();

	public function __construct($message = 'Validation failed', array $errors = array())
	{
		parent::__construct($message);
		$this->errors = $errors;
	}

	public function getErrors()
	{
		return $this->errors;
	}

	/**
	 * Build an exception from a field error map.
	 */
	public static function withErrors(array $errors)
	{
		$first = reset($errors);

		return new self($first === false ? 'Validation failed' : $first, $errors);
	}
}
