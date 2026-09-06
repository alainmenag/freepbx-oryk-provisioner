<?php

namespace Oryk\Provisioner;

use Oryk\Provisioner\Database\Connection;
use Oryk\Provisioner\Database\Schema;
use Oryk\Provisioner\Freepbx\Facade;
use Oryk\Provisioner\Provisioning\Engine;
use Oryk\Provisioner\Provisioning\HttpEndpoint;
use Oryk\Provisioner\Provisioning\ParameterResolver;
use Oryk\Provisioner\Provisioning\UrlBuilder;
use Oryk\Provisioner\Repository\DeviceRepository;
use Oryk\Provisioner\Repository\LogRepository;
use Oryk\Provisioner\Repository\TemplateRepository;
use Oryk\Provisioner\Seed\TemplateSeeder;
use Oryk\Provisioner\Template\Renderer;

/**
 * Service container for the module.
 *
 * Everything - the BMO class, the AJAX controller, the GraphQL API, the
 * provisioning endpoints and the installer - resolves its collaborators from
 * here, so there is exactly one wiring of the engine.
 *
 *   $provisioner = Provisioner::boot();
 *   $device = $provisioner->devices()->findByIdentifier('alain-softphone');
 *   $files = $provisioner->engine()->renderAll($device);
 */
class Provisioner
{
	/** @var self|null */
	private static $instance = null;

	/** @var \PDO */
	private $pdo;

	/** @var array Lazily created services. */
	private $services = array();

	private function __construct($pdo)
	{
		$this->pdo = $pdo;
	}

	/**
	 * Boot (or return) the shared container.
	 *
	 * @param \PDO|null $pdo
	 * @return self
	 */
	public static function boot($pdo = null)
	{
		if ($pdo !== null) {
			Connection::set($pdo);
			self::$instance = new self($pdo);

			return self::$instance;
		}

		if (self::$instance === null) {
			self::$instance = new self(Connection::get());
		}

		return self::$instance;
	}

	/**
	 * Drop the shared container (tests).
	 */
	public static function reset()
	{
		self::$instance = null;
		Connection::reset();
	}

	/**
	 * @return \PDO
	 */
	public function pdo()
	{
		return $this->pdo;
	}

	/**
	 * @return Settings
	 */
	public function settings()
	{
		return $this->service('settings', function ($container) {
			return new Settings($container->pdo());
		});
	}

	/**
	 * @return Facade
	 */
	public function freepbx()
	{
		return $this->service('freepbx', function () {
			return new Facade();
		});
	}

	/**
	 * @return TemplateRepository
	 */
	public function templates()
	{
		return $this->service('templates', function ($container) {
			return new TemplateRepository($container->pdo());
		});
	}

	/**
	 * @return DeviceRepository
	 */
	public function devices()
	{
		return $this->service('devices', function ($container) {
			$repository = new DeviceRepository($container->pdo(), $container->templates());
			$repository->setTokenLength($container->settings()->get('token_length'));

			return $repository;
		});
	}

	/**
	 * @return LogRepository
	 */
	public function logs()
	{
		return $this->service('logs', function ($container) {
			return new LogRepository($container->pdo());
		});
	}

	/**
	 * @return UrlBuilder
	 */
	public function urls()
	{
		return $this->service('urls', function ($container) {
			return new UrlBuilder($container->settings(), $container->freepbx());
		});
	}

	/**
	 * @return Renderer
	 */
	public function renderer()
	{
		return $this->service('renderer', function () {
			return new Renderer();
		});
	}

	/**
	 * @return ParameterResolver
	 */
	public function resolver()
	{
		return $this->service('resolver', function ($container) {
			return new ParameterResolver($container->settings(), $container->freepbx(), $container->urls());
		});
	}

	/**
	 * @return Engine
	 */
	public function engine()
	{
		return $this->service('engine', function ($container) {
			return new Engine(
				$container->devices(),
				$container->resolver(),
				$container->renderer(),
				$container->settings(),
				$container->logs(),
				$container->freepbx()
			);
		});
	}

	/**
	 * @return HttpEndpoint
	 */
	public function endpoint()
	{
		return $this->service('endpoint', function ($container) {
			return new HttpEndpoint($container->engine());
		});
	}

	/**
	 * @return TemplateSeeder
	 */
	public function seeder()
	{
		return $this->service('seeder', function ($container) {
			return new TemplateSeeder($container->templates());
		});
	}

	/**
	 * @return Schema
	 */
	public function schema()
	{
		return $this->service('schema', function ($container) {
			return new Schema($container->pdo());
		});
	}

	/**
	 * Install/upgrade the database schema and load the bundled templates.
	 *
	 * @return array Summary for the installer output.
	 */
	public function install()
	{
		$this->schema()->install();

		return $this->seeder()->seed();
	}

	/**
	 * Remove every table the module owns.
	 */
	public function uninstall()
	{
		$this->schema()->uninstall();
	}

	/**
	 * Lazily build and remember a service.
	 *
	 * @return mixed
	 */
	private function service($key, $factory)
	{
		if (!isset($this->services[$key])) {
			$this->services[$key] = call_user_func($factory, $this);
		}

		return $this->services[$key];
	}
}
