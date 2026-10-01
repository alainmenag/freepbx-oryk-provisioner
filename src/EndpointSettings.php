<?php

// src/EndpointSettings.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * The pjsip settings this module pins on a device, and where they go.
 *
 * They are written as `[<id>](+)` sections in pjsip.endpoint_custom_post.conf,
 * which adds to the endpoint FreePBX generates and survives a reload. That
 * file is shared with other modules; AsteriskConfig keeps it safe, this
 * decides what goes in. See ARCHITECTURE.md, "Users", for the file and for
 * the order the from domain is resolved in. A second setting is one line
 * in settings().
 */
class EndpointSettings extends Service
{
	/**
	 * The file Asterisk reads after the endpoints FreePBX generated.
	 */
	const FILE = '/etc/asterisk/pjsip.endpoint_custom_post.conf';

	/**
	 * What makes a section add to an endpoint instead of replacing it.
	 */
	const APPEND = '(+)';

	/**
	 * The name of the from domain, on the device and in the endpoint alike.
	 */
	const FROM_DOMAIN = 'from_domain';

	/**
	 * What the PBX-wide from domain is called in Advanced Settings.
	 */
	const SETTING = 'ORYK_FROM_DOMAIN';

	/**
	 * What a domain name is allowed to look like.
	 *
	 * Used both to validate what is typed into Advanced Settings and to
	 * decide whether this machine's hostname is a domain name at all.
	 */
	const DOMAIN_PATTERN = '/^[A-Za-z0-9]([A-Za-z0-9\-.]*[A-Za-z0-9])?$/';

	/**
	 * The file, and the reading and writing of it.
	 *
	 * @var AsteriskConfig
	 */
	private $config;

	/**
	 * The domain set for this PBX, or null until looked up; an empty string
	 * means the PBX has none.
	 *
	 * @var string|null
	 */
	private $domain;

	/**
	 * @param object              $freepbx FreePBX application instance.
	 * @param AsteriskConfig|null $config  The file to write, if not the usual one.
	 * @param string|null         $domain  The PBX-wide from domain, if it is
	 *                                     already in hand rather than to be
	 *                                     looked up.
	 */
	public function __construct($freepbx, ?AsteriskConfig $config = null, $domain = null)
	{
		parent::__construct($freepbx);

		$this->config = $config ? $config : new AsteriskConfig($freepbx, self::FILE);
		$this->domain = $domain === null ? null : trim((string) $domain);
	}

	/**
	 * The file being written.
	 *
	 * @return AsteriskConfig The configuration file.
	 */
	public function config()
	{
		return $this->config;
	}

	/**
	 * What this module pins on an endpoint.
	 *
	 * A setting that works out to nothing is still named: apply() takes that
	 * as an instruction to remove it from the endpoint.
	 *
	 * @param int|string $id Device identifier, which is the endpoint name.
	 *
	 * @return array<string, string> Settings, keyed by name.
	 */
	public function settings($id)
	{
		return [
			self::FROM_DOMAIN => $this->fromDomain($id),
		];
	}

	/**
	 * The domain an endpoint puts in the From header.
	 *
	 * See ARCHITECTURE.md, "Users", for the order it is resolved in.
	 *
	 * @param int|string|null $id Device identifier, or null for the PBX-wide
	 *                            answer on its own.
	 *
	 * @return string The domain, or an empty string when there is none.
	 */
	public function fromDomain($id = null)
	{
		$device = $this->deviceDomain($id);

		if ($device !== '') {
			return $device;
		}

		$configured = $this->pbxDomain();

		if ($configured !== '') {
			return $configured;
		}

		return $this->hostname();
	}

	/**
	 * Put the from domain into FreePBX's own settings.
	 *
	 * Safe to call on every install and upgrade. The keyword is the one
	 * oryk_connect registered; the stored value is passed back in so taking
	 * the setting over can never blank it.
	 *
	 * @return bool True when the setting is registered.
	 */
	public function register()
	{
		try {
			$current = trim((string) \FreePBX::Config()->get(self::SETTING));

			\FreePBX::Config()->define_conf_setting(self::SETTING, [
				'value' => $current,
				'defaultval' => '',
				'name' => 'From Domain',
				'description' => 'The domain a PJSIP endpoint puts in the From header. '
					. 'It is written to pjsip.endpoint_custom_post.conf on the next save of each '
					. 'device, and a device given a From Domain of its own uses that instead. '
					. 'Left blank, the hostname of this PBX is used when that is a domain name, '
					. 'and nothing is written when it is not.',
				'type' => \CONF_TYPE_TEXT,
				// For a text setting this is the pattern the value has to
				// match. An empty value is allowed and is what asks for the
				// hostname, so it is not validated against this.
				'options' => self::DOMAIN_PATTERN,
				'emptyok' => 1,
				'level' => 0,
				'category' => 'Oryk Provisioner',
				// FreePBX removes a module's settings when it is uninstalled
				'module' => 'oryk_provisioner',
				'sortorder' => 10,
			], true);
		} catch (\Throwable $e) {
			$this->logError('unable to register ' . self::SETTING . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * The from domain set for this PBX, in Settings -> Advanced Settings.
	 *
	 * @return string The domain, or an empty string when none is set.
	 */
	public function pbxDomain()
	{
		if ($this->domain !== null) {
			return $this->domain;
		}

		$this->domain = '';

		try {
			$this->domain = trim((string) \FreePBX::Config()->get(self::SETTING));
		} catch (\Throwable $e) {
			$this->domain = '';
		}

		return $this->domain;
	}

	/**
	 * Set the from domain for this PBX.
	 *
	 * Endpoints pick it up on their next save; nothing already written
	 * changes until then.
	 *
	 * @param string $domain The domain, or an empty string to clear it.
	 *
	 * @return bool True when it was stored.
	 */
	public function setPbxDomain($domain)
	{
		$domain = trim((string) $domain);

		try {
			\FreePBX::Config()->update(self::SETTING, $domain);
		} catch (\Throwable $e) {
			$this->logError('unable to store ' . self::SETTING . ': ' . $e->getMessage());

			return false;
		}

		$this->domain = $domain;

		return true;
	}

	/**
	 * Write an endpoint's settings.
	 *
	 * Only the settings named are touched; the rest of the file, including
	 * other modules' sections, is left as it was. A setting that works out to
	 * nothing is taken out, so an old domain is not left announced. A failure
	 * is logged and returned, not thrown, so a saved device is not lost.
	 *
	 * @param int|string           $id    Device identifier.
	 * @param array<string, mixed> $extra Settings for this device alone, which
	 *                                    win over the ones every device gets.
	 *
	 * @return bool True when the file says what it should.
	 */
	public function apply($id, array $extra = [])
	{
		$id = trim((string) $id);

		if ($id === '') {
			return false;
		}

		$values = $extra + $this->settings($id);
		$write = [];
		$clear = [];

		foreach ($values as $key => $value) {
			if (trim((string) $value) === '') {
				$clear[] = $key;
			} else {
				$write[$key] = $value;
			}
		}

		return $this->write(function (AsteriskConfig $config) use ($id, $write, $clear) {
			if ($write) {
				$config->set($id, $write, self::APPEND);
			}

			if ($clear) {
				$config->remove($id, $clear);
			}
		}, $id);
	}

	/**
	 * Take an endpoint's section out of the file.
	 *
	 * For a deleted device and a number a renumbering has left. Asterisk does
	 * not complain about a section for a missing endpoint, so a stale one
	 * would silently apply when the number is reused.
	 *
	 * @param int|string $id Device identifier.
	 *
	 * @return bool True when the file no longer carries the section.
	 */
	public function forget($id)
	{
		$id = trim((string) $id);

		if ($id === '') {
			return false;
		}

		return $this->write(function (AsteriskConfig $config) use ($id) {
			$config->removeSection($id);
		}, $id);
	}

	/**
	 * Move an endpoint's settings to a different number.
	 *
	 * @param int|string $old Number being left behind.
	 * @param int|string $new Number being moved to.
	 *
	 * @return bool True when the file was written.
	 */
	public function move($old, $new)
	{
		$old = trim((string) $old);
		$new = trim((string) $new);

		if ($old === '' || $new === '' || $old === $new) {
			return false;
		}

		return $this->write(function (AsteriskConfig $config) use ($old, $new) {
			// What the old number was carrying follows it. The device this
			// is part of saving is written again straight afterwards, which
			// is what settles anything that should have changed with the
			// number rather than travelled with it.
			$carried = $config->values($old);

			$config->removeSection($old);

			if ($carried) {
				$config->set($new, $carried, self::APPEND);
			}
		}, $old . ' to ' . $new);
	}

	/**
	 * The from domain typed on one device.
	 *
	 * Read back off the device; on a save the device is already written by
	 * the time this is asked, so the value just typed is the one used.
	 *
	 * @param int|string|null $id Device identifier.
	 *
	 * @return string The domain, or an empty string when it has none.
	 */
	private function deviceDomain($id)
	{
		if ($id === null || trim((string) $id) === '') {
			return '';
		}

		try {
			$device = \FreePBX::Core()->getDevice($id);
		} catch (\Throwable $e) {
			return '';
		}

		return isset($device[self::FROM_DOMAIN])
			? trim((string) $device[self::FROM_DOMAIN])
			: '';
	}

	/**
	 * What this PBX calls itself, when that is a domain name.
	 *
	 * A bare name, a `.local` or localhost is worse in a From header than
	 * nothing, so it is not used.
	 *
	 * @return string The hostname, or an empty string when it is not usable.
	 */
	private function hostname()
	{
		$name = strtolower(trim((string) gethostname()));

		if ($name === '' || strpos($name, '.') === false) {
			return '';
		}

		if (!preg_match(self::DOMAIN_PATTERN, $name)) {
			return '';
		}

		foreach (['localhost', '.local', '.localdomain', '.localhost'] as $unusable) {
			if ($name === $unusable || substr($name, -strlen($unusable)) === $unusable) {
				return '';
			}
		}

		return $name;
	}

	/**
	 * Run one change against the file, logging any error and returning false.
	 *
	 * @param callable $mutator What to change.
	 * @param string   $subject What it was about, for the log.
	 *
	 * @return bool True when the file was written, or did not need to be.
	 */
	private function write(callable $mutator, $subject)
	{
		try {
			$this->config->edit($mutator);
		} catch (\Throwable $e) {
			$this->logError(sprintf(
				'unable to update %s for %s: %s',
				$this->config->path(),
				$subject,
				$e->getMessage()
			));

			return false;
		}

		return true;
	}
}
