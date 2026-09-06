<?php
/**
 * Oryk Provisioner - GraphQL management API.
 *
 * Exposed through the FreePBX API module at POST /admin/api/api/gql and secured
 * by its normal OAuth scopes. Rendering goes through the same provisioning
 * engine as the admin preview and the device endpoints.
 *
 * Queries:
 *   provisionerTemplates, provisionerTemplate(id|slug)
 *   provisionerDevices, provisionerDevice(id|identifier)
 *   provisionerRenderDevice(id), provisionerRenderFile(deviceId, filename)
 *
 * Mutations:
 *   provisionerCreateDevice, provisionerUpdateDevice, provisionerDeleteDevice
 *   provisionerEnableDevice, provisionerDisableDevice, provisionerRegenerateToken
 *   provisionerCreateTemplate, provisionerUpdateTemplate, provisionerDeleteTemplate
 */

namespace FreePBX\modules\Oryk_provisioner\Api\Gql;

use GraphQL\Type\Definition\Type;
use GraphQLRelay\Relay;
use FreePBX\modules\Api\Gql\Base;
use Oryk\Provisioner\Model\Device;
use Oryk\Provisioner\Model\Template;
use Oryk\Provisioner\Provisioner;
use Oryk\Provisioner\Support\Json;

class Oryk_provisioner extends Base
{
	protected $module = 'oryk_provisioner';

	/** @var Provisioner|null */
	private $container = null;

	/**
	 * OAuth scopes this module understands.
	 *
	 * @return array
	 */
	public static function getScopes()
	{
		return array(
			'read:provisioner' => array(
				'description' => _('Read provisioning devices, templates and rendered configuration'),
			),
			'write:provisioner' => array(
				'description' => _('Create, update and delete provisioning devices and templates'),
			),
		);
	}

	// -------------------------------------------------------------------- types

	public function initializeTypes()
	{
		$this->outputType();
		$this->templateType();
		$this->deviceType();
		$this->fileType();
		$this->statusType();
	}

	private function outputType()
	{
		$type = $this->typeContainer->create('provisionerOutput');
		$type->setDescription(_('One file produced by a provisioning template'));
		$type->addFieldCallback(function () {
			return array(
				'id'          => array('type' => Type::id()),
				'filename'    => array('type' => Type::string(), 'description' => _('Filename, itself a template')),
				'contentType' => array('type' => Type::string()),
				'template'    => array('type' => Type::string(), 'description' => _('Template body')),
			);
		});
	}

	private function templateType()
	{
		$type = $this->typeContainer->create('provisionerTemplate');
		$type->setDescription(_('A provisioning template'));
		$type->addFieldCallback(function () {
			return array(
				'id'          => array('type' => Type::id()),
				'name'        => array('type' => Type::string()),
				'slug'        => array('type' => Type::string()),
				'vendor'      => array('type' => Type::string()),
				'family'      => array('type' => Type::string()),
				'description' => array('type' => Type::string()),
				'enabled'     => array('type' => Type::boolean()),
				'builtin'     => array('type' => Type::boolean()),
				'defaults'    => array(
					'type'        => Type::string(),
					'description' => _('Template defaults as a JSON object'),
					'resolve'     => function ($row) {
						return Json::encode(isset($row['defaults']) ? $row['defaults'] : array());
					},
				),
				'parameters'  => array(
					'type'        => Type::string(),
					'description' => _('Parameter schema as a JSON object'),
					'resolve'     => function ($row) {
						return Json::encode(isset($row['parameters']) ? $row['parameters'] : array());
					},
				),
				'outputs'     => array(
					'type'    => Type::listOf($this->typeContainer->get('provisionerOutput')->getObject()),
					'resolve' => function ($row) {
						return isset($row['outputs']) ? $row['outputs'] : array();
					},
				),
			);
		});
	}

	private function deviceType()
	{
		$type = $this->typeContainer->create('provisionerDevice');
		$type->setDescription(_('A provisioned endpoint'));
		$type->addFieldCallback(function () {
			return array(
				'id'                => array('type' => Type::id()),
				'identifier'        => array('type' => Type::string()),
				'name'              => array('type' => Type::string()),
				'extension'         => array('type' => Type::string()),
				'mac'               => array('type' => Type::string()),
				'vendor'            => array('type' => Type::string()),
				'model'             => array('type' => Type::string()),
				'enabled'           => array('type' => Type::boolean()),
				'notes'             => array('type' => Type::string()),
				'templateId'        => array('type' => Type::id()),
				'templateName'      => array('type' => Type::string()),
				'templateSlug'      => array('type' => Type::string()),
				'lastProvisionedAt' => array('type' => Type::string()),
				'lastProvisionedIp' => array('type' => Type::string()),
				'parameters'        => array(
					'type'        => Type::string(),
					'description' => _('Device parameter overrides as a JSON object'),
					'resolve'     => function ($row) {
						return Json::encode(isset($row['parameters']) ? $row['parameters'] : array());
					},
				),
				'token'             => array(
					'type'        => Type::string(),
					'description' => _('Provisioning token. Only returned with the write scope.'),
					'resolve'     => function ($row) {
						return $this->checkWriteScope('provisioner') && isset($row['token']) ? $row['token'] : null;
					},
				),
				'provisioningUrl'   => array(
					'type'        => Type::string(),
					'description' => _('Provisioning root URL for this device'),
					'resolve'     => function ($row) {
						if (!$this->checkWriteScope('provisioner') || empty($row['token'])) {
							return null;
						}

						return $this->provisioner()->urls()->rootUrl($row['token']);
					},
				),
			);
		});
	}

	private function fileType()
	{
		$type = $this->typeContainer->create('provisionerFile');
		$type->setDescription(_('A rendered configuration file'));
		$type->addFieldCallback(function () {
			return array(
				'filename'    => array('type' => Type::string()),
				'contentType' => array('type' => Type::string()),
				'content'     => array('type' => Type::string()),
				'size'        => array('type' => Type::int()),
			);
		});
	}

	private function statusType()
	{
		$type = $this->typeContainer->create('provisionerStatus');
		$type->setDescription(_('Result of a provisioner mutation'));
		$type->addFieldCallback(function () {
			return array(
				'status'  => array('type' => Type::boolean()),
				'message' => array('type' => Type::string()),
				'id'      => array('type' => Type::id()),
			);
		});
	}

	// ------------------------------------------------------------------ queries

	public function queryCallback()
	{
		if (!$this->checkReadScope('provisioner')) {
			return null;
		}

		return function () {
			return array(
				'provisionerTemplates' => array(
					'type'        => Type::listOf($this->typeContainer->get('provisionerTemplate')->getObject()),
					'description' => _('Every provisioning template'),
					'resolve'     => function () {
						$templates = array();

						foreach ($this->provisioner()->templates()->all() as $template) {
							$templates[] = $template->toArray();
						}

						return $templates;
					},
				),

				'provisionerTemplate' => array(
					'type'        => $this->typeContainer->get('provisionerTemplate')->getObject(),
					'description' => _('One provisioning template, by id or slug'),
					'args'        => array(
						'id'   => array('type' => Type::id()),
						'slug' => array('type' => Type::string()),
					),
					'resolve'     => function ($root, $args) {
						$template = $this->findTemplate($args);

						return $template === null ? null : $template->toArray();
					},
				),

				'provisionerDevices' => array(
					'type'        => Type::listOf($this->typeContainer->get('provisionerDevice')->getObject()),
					'description' => _('Every provisioned device'),
					'args'        => array(
						'templateId' => array('type' => Type::id()),
						'extension'  => array('type' => Type::string()),
					),
					'resolve'     => function ($root, $args) {
						$devices = array();

						foreach ($this->provisioner()->devices()->all($args) as $device) {
							$devices[] = $device->toArray();
						}

						return $devices;
					},
				),

				'provisionerDevice' => array(
					'type'        => $this->typeContainer->get('provisionerDevice')->getObject(),
					'description' => _('One device, by id or identifier'),
					'args'        => array(
						'id'         => array('type' => Type::id()),
						'identifier' => array('type' => Type::string()),
					),
					'resolve'     => function ($root, $args) {
						$device = $this->findDevice($args);

						return $device === null ? null : $device->toArray();
					},
				),

				'provisionerRenderDevice' => array(
					'type'        => Type::listOf($this->typeContainer->get('provisionerFile')->getObject()),
					'description' => _('Render every file a device would receive'),
					'args'        => array(
						'id'         => array('type' => Type::id()),
						'identifier' => array('type' => Type::string()),
					),
					'resolve'     => function ($root, $args) {
						$device = $this->findDevice($args);

						if ($device === null) {
							return array();
						}

						$files = array();

						foreach ($this->provisioner()->engine()->renderAll($device) as $file) {
							$files[] = $file->toArray();
						}

						return $files;
					},
				),

				'provisionerRenderFile' => array(
					'type'        => $this->typeContainer->get('provisionerFile')->getObject(),
					'description' => _('Render one file for a device'),
					'args'        => array(
						'deviceId' => array('type' => Type::nonNull(Type::id())),
						'filename' => array('type' => Type::nonNull(Type::string())),
					),
					'resolve'     => function ($root, $args) {
						$device = $this->provisioner()->devices()->find((int) $args['deviceId']);

						if ($device === null) {
							return null;
						}

						return $this->provisioner()->engine()->renderFilename($device, $args['filename'])->toArray();
					},
				),
			);
		};
	}

	// ---------------------------------------------------------------- mutations

	public function mutationCallback()
	{
		if (!$this->checkWriteScope('provisioner')) {
			return null;
		}

		return function () {
			return array(
				'provisionerCreateDevice' => Relay::mutationWithClientMutationId(array(
					'name'               => 'provisionerCreateDevice',
					'description'        => _('Create a provisioned device'),
					'inputFields'        => $this->deviceInputFields(true),
					'outputFields'       => $this->statusOutputFields(),
					'mutateAndGetPayload' => function ($input) {
						return $this->saveDevice($input, 0);
					},
				)),

				'provisionerUpdateDevice' => Relay::mutationWithClientMutationId(array(
					'name'               => 'provisionerUpdateDevice',
					'description'        => _('Update a provisioned device'),
					'inputFields'        => array_merge(
						array('id' => array('type' => Type::nonNull(Type::id()))),
						$this->deviceInputFields(false)
					),
					'outputFields'       => $this->statusOutputFields(),
					'mutateAndGetPayload' => function ($input) {
						return $this->saveDevice($input, (int) $input['id']);
					},
				)),

				'provisionerDeleteDevice' => Relay::mutationWithClientMutationId(array(
					'name'               => 'provisionerDeleteDevice',
					'description'        => _('Delete a provisioned device'),
					'inputFields'        => array('id' => array('type' => Type::nonNull(Type::id()))),
					'outputFields'       => $this->statusOutputFields(),
					'mutateAndGetPayload' => function ($input) {
						$this->provisioner()->devices()->delete((int) $input['id']);

						return array('status' => true, 'message' => _('Device deleted'), 'id' => $input['id']);
					},
				)),

				'provisionerEnableDevice' => Relay::mutationWithClientMutationId(array(
					'name'               => 'provisionerEnableDevice',
					'description'        => _('Allow a device to fetch its configuration'),
					'inputFields'        => array('id' => array('type' => Type::nonNull(Type::id()))),
					'outputFields'       => $this->statusOutputFields(),
					'mutateAndGetPayload' => function ($input) {
						$this->provisioner()->devices()->setEnabled((int) $input['id'], true);

						return array('status' => true, 'message' => _('Device enabled'), 'id' => $input['id']);
					},
				)),

				'provisionerDisableDevice' => Relay::mutationWithClientMutationId(array(
					'name'               => 'provisionerDisableDevice',
					'description'        => _('Stop a device from fetching its configuration'),
					'inputFields'        => array('id' => array('type' => Type::nonNull(Type::id()))),
					'outputFields'       => $this->statusOutputFields(),
					'mutateAndGetPayload' => function ($input) {
						$this->provisioner()->devices()->setEnabled((int) $input['id'], false);

						return array('status' => true, 'message' => _('Device disabled'), 'id' => $input['id']);
					},
				)),

				'provisionerRegenerateToken' => Relay::mutationWithClientMutationId(array(
					'name'               => 'provisionerRegenerateToken',
					'description'        => _('Issue a new provisioning token, invalidating the previous URL'),
					'inputFields'        => array('id' => array('type' => Type::nonNull(Type::id()))),
					'outputFields'       => $this->statusOutputFields(),
					'mutateAndGetPayload' => function ($input) {
						$device = $this->provisioner()->devices()->regenerateToken((int) $input['id']);

						return array(
							'status'  => $device !== null,
							'message' => _('Provisioning token regenerated'),
							'id'      => $input['id'],
						);
					},
				)),

				'provisionerCreateTemplate' => Relay::mutationWithClientMutationId(array(
					'name'               => 'provisionerCreateTemplate',
					'description'        => _('Create a provisioning template from its JSON definition'),
					'inputFields'        => array(
						'definition' => array(
							'type'        => Type::nonNull(Type::string()),
							'description' => _('Template definition as JSON'),
						),
					),
					'outputFields'       => $this->statusOutputFields(),
					'mutateAndGetPayload' => function ($input) {
						return $this->saveTemplate($input['definition'], 0);
					},
				)),

				'provisionerUpdateTemplate' => Relay::mutationWithClientMutationId(array(
					'name'               => 'provisionerUpdateTemplate',
					'description'        => _('Replace a provisioning template from its JSON definition'),
					'inputFields'        => array(
						'id'         => array('type' => Type::nonNull(Type::id())),
						'definition' => array('type' => Type::nonNull(Type::string())),
					),
					'outputFields'       => $this->statusOutputFields(),
					'mutateAndGetPayload' => function ($input) {
						return $this->saveTemplate($input['definition'], (int) $input['id']);
					},
				)),

				'provisionerDeleteTemplate' => Relay::mutationWithClientMutationId(array(
					'name'               => 'provisionerDeleteTemplate',
					'description'        => _('Delete a provisioning template'),
					'inputFields'        => array(
						'id'    => array('type' => Type::nonNull(Type::id())),
						'force' => array('type' => Type::boolean()),
					),
					'outputFields'       => $this->statusOutputFields(),
					'mutateAndGetPayload' => function ($input) {
						$this->provisioner()->templates()->delete(
							(int) $input['id'],
							!empty($input['force'])
						);

						return array('status' => true, 'message' => _('Template deleted'), 'id' => $input['id']);
					},
				)),
			);
		};
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * @return array
	 */
	private function deviceInputFields($requireName)
	{
		return array(
			'name'       => array('type' => $requireName ? Type::nonNull(Type::string()) : Type::string()),
			'identifier' => array('type' => Type::string()),
			'templateId' => array('type' => Type::id()),
			'extension'  => array('type' => Type::string()),
			'mac'        => array('type' => Type::string()),
			'vendor'     => array('type' => Type::string()),
			'model'      => array('type' => Type::string()),
			'enabled'    => array('type' => Type::boolean()),
			'notes'      => array('type' => Type::string()),
			'parameters' => array(
				'type'        => Type::string(),
				'description' => _('Device parameter overrides as a JSON object'),
			),
		);
	}

	/**
	 * @return array
	 */
	private function statusOutputFields()
	{
		return array(
			'status'  => array(
				'type'    => Type::boolean(),
				'resolve' => function ($payload) {
					return !empty($payload['status']);
				},
			),
			'message' => array(
				'type'    => Type::string(),
				'resolve' => function ($payload) {
					return isset($payload['message']) ? $payload['message'] : '';
				},
			),
			'id'      => array(
				'type'    => Type::id(),
				'resolve' => function ($payload) {
					return isset($payload['id']) ? $payload['id'] : null;
				},
			),
		);
	}

	/**
	 * Create or update a device from mutation input.
	 *
	 * @return array
	 */
	private function saveDevice(array $input, $id)
	{
		$existing = $id > 0 ? $this->provisioner()->devices()->find($id, false) : null;

		if ($id > 0 && $existing === null) {
			return array('status' => false, 'message' => _('Device not found'), 'id' => $id);
		}

		$data = $existing === null ? array() : $existing->toArray();

		foreach (array('name', 'identifier', 'templateId', 'extension', 'mac', 'vendor', 'model', 'notes') as $field) {
			if (isset($input[$field])) {
				$data[$field] = $input[$field];
			}
		}

		if (array_key_exists('enabled', $input)) {
			$data['enabled'] = !empty($input['enabled']);
		}

		if (isset($input['parameters'])) {
			$data['parameters'] = Json::decode($input['parameters']);
		}

		$data['id'] = $id;

		try {
			$device = $this->provisioner()->devices()->save(Device::fromArray($data));
		} catch (\Exception $e) {
			return array('status' => false, 'message' => $e->getMessage(), 'id' => $id);
		}

		return array(
			'status'  => true,
			'message' => $id > 0 ? _('Device updated') : _('Device created'),
			'id'      => $device->id,
		);
	}

	/**
	 * Create or replace a template from a JSON definition.
	 *
	 * @return array
	 */
	private function saveTemplate($definition, $id)
	{
		$decoded = Json::decode($definition);

		if (empty($decoded)) {
			return array('status' => false, 'message' => _('The definition is not valid JSON'), 'id' => $id);
		}

		$decoded['id'] = $id;

		if ($id > 0) {
			$existing = $this->provisioner()->templates()->find($id);

			if ($existing === null) {
				return array('status' => false, 'message' => _('Template not found'), 'id' => $id);
			}
		}

		try {
			$template = $this->provisioner()->templates()->save(Template::fromArray($decoded));
		} catch (\Exception $e) {
			return array('status' => false, 'message' => $e->getMessage(), 'id' => $id);
		}

		return array(
			'status'  => true,
			'message' => $id > 0 ? _('Template updated') : _('Template created'),
			'id'      => $template->id,
		);
	}

	/**
	 * @return \Oryk\Provisioner\Model\Device|null
	 */
	private function findDevice(array $args)
	{
		if (!empty($args['id'])) {
			return $this->provisioner()->devices()->find((int) $args['id']);
		}

		if (!empty($args['identifier'])) {
			return $this->provisioner()->devices()->findByIdentifier($args['identifier']);
		}

		return null;
	}

	/**
	 * @return \Oryk\Provisioner\Model\Template|null
	 */
	private function findTemplate(array $args)
	{
		if (!empty($args['id'])) {
			return $this->provisioner()->templates()->find((int) $args['id']);
		}

		if (!empty($args['slug'])) {
			return $this->provisioner()->templates()->findBySlug($args['slug']);
		}

		return null;
	}

	/**
	 * The provisioning container - the same one the admin interface uses.
	 *
	 * @return Provisioner
	 */
	private function provisioner()
	{
		if ($this->container === null) {
			require_once dirname(dirname(__DIR__)) . '/lib/autoload.php';
			$this->container = Provisioner::boot(\FreePBX::Database());
		}

		return $this->container;
	}
}
