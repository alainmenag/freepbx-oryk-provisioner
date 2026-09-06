<?php

namespace Oryk\Provisioner\Model;

use Oryk\Provisioner\Support\Arr;
use Oryk\Provisioner\Support\Json;

/**
 * The collection of parameter definitions declared by a template.
 *
 * The schema makes parameters discoverable: the admin interface builds device
 * forms from it and the engine uses it for defaults, validation and for knowing
 * which values are secret (so they can be masked in previews and logs).
 */
class ParameterSchema implements \IteratorAggregate, \Countable
{
	/** @var ParameterDefinition[] keyed by parameter name */
	private $definitions = array();

	/**
	 * @param array|string $schema JSON string or decoded map.
	 * @return self
	 */
	public static function fromArray($schema)
	{
		$instance = new self();
		$schema = Json::decode($schema);

		foreach ($schema as $name => $definition) {
			// Repeater rows from the admin UI arrive as a list of objects.
			if (is_int($name) && is_array($definition) && isset($definition['name'])) {
				$name = $definition['name'];
				unset($definition['name']);
			}

			$name = trim((string) $name);

			if ($name === '') {
				continue;
			}

			$instance->definitions[$name] = ParameterDefinition::fromArray($name, $definition);
		}

		return $instance;
	}

	/**
	 * @return array JSON schema representation.
	 */
	public function toArray()
	{
		$schema = array();

		foreach ($this->definitions as $name => $definition) {
			$schema[$name] = $definition->toArray();
		}

		return $schema;
	}

	/**
	 * A list representation, convenient for the admin interface repeater.
	 *
	 * @return array
	 */
	public function toList()
	{
		$list = array();

		foreach ($this->definitions as $name => $definition) {
			$row = $definition->toArray();
			$row['name'] = $name;
			$row['allowed'] = isset($row['allowed']) ? implode(', ', $row['allowed']) : '';
			$row['required'] = !empty($row['required']);
			$row['secret'] = !empty($row['secret']);
			$row['default'] = isset($row['default']) ? $row['default'] : '';
			$row['description'] = isset($row['description']) ? $row['description'] : '';
			$list[] = $row;
		}

		return $list;
	}

	/**
	 * @param string $name
	 * @return ParameterDefinition|null
	 */
	public function get($name)
	{
		return isset($this->definitions[$name]) ? $this->definitions[$name] : null;
	}

	public function has($name)
	{
		return isset($this->definitions[$name]);
	}

	/**
	 * @return ParameterDefinition[]
	 */
	public function definitions()
	{
		return $this->definitions;
	}

	/**
	 * Default values declared by the schema. These sit at the very bottom of the
	 * resolution order.
	 *
	 * @return array
	 */
	public function defaults()
	{
		$defaults = array();

		foreach ($this->definitions as $name => $definition) {
			if ($definition->default !== null) {
				$defaults[$name] = $definition->default;
			}
		}

		return $defaults;
	}

	/**
	 * Names of every parameter flagged as secret.
	 *
	 * @return array
	 */
	public function secrets()
	{
		$secrets = array();

		foreach ($this->definitions as $name => $definition) {
			if ($definition->secret) {
				$secrets[] = $name;
			}
		}

		return $secrets;
	}

	/**
	 * Validate a resolved parameter map against the schema.
	 *
	 * @param array $values Flat dotted map.
	 * @return array parameter => error message
	 */
	public function validate(array $values)
	{
		$errors = array();

		foreach ($this->definitions as $name => $definition) {
			$value = Arr::get($values, $name);
			$error = $definition->validate($value);

			if ($error !== '') {
				$errors[$name] = $error;
			}
		}

		return $errors;
	}

	/**
	 * Cast submitted values using the declared types, dropping empty entries.
	 *
	 * @param array $values
	 * @return array
	 */
	public function cast(array $values)
	{
		$cast = array();

		foreach ($values as $name => $value) {
			$definition = $this->get($name);
			$cast[$name] = $definition === null ? $value : $definition->castValue($value);
		}

		return $cast;
	}

	#[\ReturnTypeWillChange]
	public function getIterator()
	{
		return new \ArrayIterator($this->definitions);
	}

	#[\ReturnTypeWillChange]
	public function count()
	{
		return count($this->definitions);
	}
}
