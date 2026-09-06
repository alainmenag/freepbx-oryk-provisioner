<?php

namespace Oryk\Provisioner\Model;

/**
 * A single entry of a template parameter schema.
 *
 * {
 *   "sip.transport": {
 *     "type": "string",
 *     "default": "udp",
 *     "allowed": ["udp", "tcp", "tls"],
 *     "required": false,
 *     "secret": false,
 *     "description": "SIP transport"
 *   }
 * }
 */
class ParameterDefinition
{
	const TYPE_STRING  = 'string';
	const TYPE_INTEGER = 'integer';
	const TYPE_BOOLEAN = 'boolean';
	const TYPE_TEXT    = 'text';

	/** @var string */
	public $name = '';

	/** @var string */
	public $type = self::TYPE_STRING;

	/** @var bool */
	public $required = false;

	/** @var mixed */
	public $default = null;

	/** @var bool */
	public $secret = false;

	/** @var array */
	public $allowed = array();

	/** @var string */
	public $description = '';

	/**
	 * @return array Supported types keyed by value with a human label.
	 */
	public static function types()
	{
		return array(
			self::TYPE_STRING  => 'String',
			self::TYPE_INTEGER => 'Integer',
			self::TYPE_BOOLEAN => 'Boolean',
			self::TYPE_TEXT    => 'Text',
		);
	}

	/**
	 * Build from the JSON schema representation.
	 *
	 * @param string $name
	 * @param array  $definition
	 * @return self
	 */
	public static function fromArray($name, $definition)
	{
		$parameter = new self();
		$parameter->name = trim((string) $name);

		if (!is_array($definition)) {
			return $parameter;
		}

		$type = isset($definition['type']) ? strtolower((string) $definition['type']) : self::TYPE_STRING;
		$parameter->type = array_key_exists($type, self::types()) ? $type : self::TYPE_STRING;

		$parameter->required = !empty($definition['required']);
		$parameter->secret = !empty($definition['secret']);
		$parameter->description = isset($definition['description']) ? (string) $definition['description'] : '';

		if (array_key_exists('default', $definition) && $definition['default'] !== null && $definition['default'] !== '') {
			$parameter->default = $parameter->castValue($definition['default']);
		}

		if (isset($definition['allowed']) && is_array($definition['allowed'])) {
			$parameter->allowed = array_values(array_filter(array_map('strval', $definition['allowed']), function ($value) {
				return $value !== '';
			}));
		}

		return $parameter;
	}

	/**
	 * JSON schema representation (without the name, which is the map key).
	 *
	 * @return array
	 */
	public function toArray()
	{
		$definition = array('type' => $this->type);

		if ($this->required) {
			$definition['required'] = true;
		}

		if ($this->default !== null) {
			$definition['default'] = $this->default;
		}

		if ($this->secret) {
			$definition['secret'] = true;
		}

		if (!empty($this->allowed)) {
			$definition['allowed'] = $this->allowed;
		}

		if ($this->description !== '') {
			$definition['description'] = $this->description;
		}

		return $definition;
	}

	/**
	 * Cast a submitted value to the declared type.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public function castValue($value)
	{
		switch ($this->type) {
			case self::TYPE_INTEGER:
				return is_numeric($value) ? (int) $value : $value;
			case self::TYPE_BOOLEAN:
				if (is_bool($value)) {
					return $value;
				}

				return in_array(strtolower((string) $value), array('1', 'true', 'yes', 'on'), true);
			default:
				return is_scalar($value) ? (string) $value : $value;
		}
	}

	/**
	 * Validate a value against this definition.
	 *
	 * @param mixed $value
	 * @return string Empty string when valid, otherwise the error message.
	 */
	public function validate($value)
	{
		$empty = $value === null || $value === '';

		if ($empty) {
			return $this->required ? sprintf('%s is required', $this->name) : '';
		}

		if ($this->type === self::TYPE_INTEGER && !is_numeric($value)) {
			return sprintf('%s must be an integer', $this->name);
		}

		if (!empty($this->allowed) && !in_array((string) $value, $this->allowed, true)) {
			return sprintf('%s must be one of: %s', $this->name, implode(', ', $this->allowed));
		}

		return '';
	}
}
