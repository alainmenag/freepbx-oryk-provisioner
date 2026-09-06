<?php

namespace Oryk\Provisioner\Freepbx;

use Oryk\Provisioner\Database\Connection;

/**
 * Every call into FreePBX lives here.
 *
 * Keeping the integration behind one facade means the provisioning engine has
 * no direct dependency on FreePBX internals: it can be exercised without a PBX,
 * and a change in a FreePBX API only has to be handled in one place. Every
 * lookup degrades gracefully - a missing module or renamed method returns empty
 * data instead of breaking a device's provisioning run.
 */
class Facade
{
	/** @var array Extension lookups already performed this request. */
	private $extensionCache = array();

	/** @var array|null */
	private $extensionList = null;

	/** @var array|null */
	private $sipSettings = null;

	/**
	 * Is FreePBX bootstrapped in this process?
	 */
	public function isAvailable()
	{
		return class_exists('\FreePBX');
	}

	/**
	 * Normalised parameters for a FreePBX extension.
	 *
	 * @param string $extension
	 * @return array Flat dotted map, empty when the extension is unknown.
	 */
	public function extensionParameters($extension)
	{
		$extension = trim((string) $extension);

		if ($extension === '' || !$this->isAvailable()) {
			return array();
		}

		if (isset($this->extensionCache[$extension])) {
			return $this->extensionCache[$extension];
		}

		$values = array();
		$user = $this->coreCall('getUser', $extension);
		$device = $this->coreCall('getDevice', $extension);

		if (is_array($device) && !empty($device)) {
			$values['sip.username'] = isset($device['id']) ? (string) $device['id'] : $extension;
			$values['sip.auth_username'] = $values['sip.username'];

			if (isset($device['secret']) && $device['secret'] !== '') {
				$values['sip.password'] = (string) $device['secret'];
			}

			if (isset($device['tech']) && $device['tech'] !== '') {
				$values['sip.tech'] = (string) $device['tech'];
			}

			if (isset($device['description']) && $device['description'] !== '') {
				$values['device.description'] = (string) $device['description'];
			}
		}

		if (is_array($user) && !empty($user)) {
			$values['user.extension'] = isset($user['extension']) ? (string) $user['extension'] : $extension;
			$values['user.display_name'] = isset($user['name']) ? (string) $user['name'] : '';
			$values['user.cid'] = isset($user['outboundcid']) ? (string) $user['outboundcid'] : '';
			$values['user.voicemail'] = !empty($user['voicemail']) && $user['voicemail'] !== 'novm';

			if (empty($values['sip.username'])) {
				$values['sip.username'] = $values['user.extension'];
				$values['sip.auth_username'] = $values['user.extension'];
			}
		}

		if (empty($values)) {
			$this->extensionCache[$extension] = array();

			return array();
		}

		$values['user.extension'] = isset($values['user.extension']) ? $values['user.extension'] : $extension;
		$values['user.email'] = $this->extensionEmail($extension);
		$values['sip.voicemail_number'] = '*97';

		$this->extensionCache[$extension] = $values;

		return $values;
	}

	/**
	 * Every extension on the system, for pickers and directory templates.
	 *
	 * @return array list of ['extension' => ..., 'name' => ...]
	 */
	public function extensions()
	{
		if ($this->extensionList !== null) {
			return $this->extensionList;
		}

		$this->extensionList = array();

		if (!$this->isAvailable()) {
			return $this->extensionList;
		}

		try {
			$core = \FreePBX::Core();

			if (!is_object($core) || !method_exists($core, 'getAllUsers')) {
				return $this->extensionList;
			}

			$users = $core->getAllUsers();

			if (!is_array($users)) {
				return $this->extensionList;
			}

			foreach ($users as $user) {
				if (empty($user['extension'])) {
					continue;
				}

				$this->extensionList[] = array(
					'extension' => (string) $user['extension'],
					'name'      => isset($user['name']) ? (string) $user['name'] : '',
				);
			}
		} catch (\Exception $e) {
			$this->extensionList = array();
		}

		return $this->extensionList;
	}

	/**
	 * External/host address the phones should register against.
	 *
	 * @return string
	 */
	public function serverAddress()
	{
		$settings = $this->sipSettings();

		foreach (array('externip', 'externhost', 'bindaddr') as $key) {
			if (!empty($settings[$key]) && $settings[$key] !== '0.0.0.0') {
				return (string) $settings[$key];
			}
		}

		$hostname = gethostname();

		return $hostname === false ? '' : $hostname;
	}

	/**
	 * SIP port advertised to devices.
	 *
	 * @param string $transport udp|tcp|tls
	 * @return string
	 */
	public function sipPort($transport = 'udp')
	{
		$settings = $this->sipSettings();
		$transport = strtolower((string) $transport);

		$candidates = $transport === 'tls'
			? array('tlsbindport', 'bindport')
			: array('bindport', 'udpbindport');

		foreach ($candidates as $key) {
			if (!empty($settings[$key]) && (int) $settings[$key] > 0) {
				return (string) (int) $settings[$key];
			}
		}

		return $transport === 'tls' ? '5061' : '5060';
	}

	/**
	 * FreePBX web root on disk, e.g. /var/www/html.
	 *
	 * @return string
	 */
	public function webRoot()
	{
		if ($this->isAvailable()) {
			try {
				$root = \FreePBX::Config()->get('AMPWEBROOT');

				if (!empty($root)) {
					return rtrim((string) $root, '/');
				}
			} catch (\Exception $e) {
				// fall through
			}
		}

		return '/var/www/html';
	}

	/**
	 * Is the logged in admin session valid? Used to guard the admin interface
	 * when the module is reachable without FreePBX authentication.
	 */
	public function hasAdminSession()
	{
		return isset($_SESSION['AMP_user']) && is_object($_SESSION['AMP_user']);
	}

	/**
	 * Write a line to the FreePBX log, falling back to the PHP error log.
	 *
	 * @param string $message
	 * @param string $level
	 */
	public function log($message, $level = 'warning')
	{
		$message = '[oryk_provisioner] ' . $message;

		if ($this->isAvailable()) {
			try {
				$logger = \FreePBX::Logger();

				if (is_object($logger) && method_exists($logger, 'log')) {
					$logger->log($level === 'error' ? LOG_ERR : LOG_WARNING, $message);

					return;
				}
			} catch (\Exception $e) {
				// fall through
			}
		}

		error_log($message);
	}

	/**
	 * Email address associated with an extension (voicemail, then user manager).
	 *
	 * @return string
	 */
	private function extensionEmail($extension)
	{
		try {
			if (class_exists('\FreePBX')) {
				$voicemail = \FreePBX::create()->Voicemail;

				if (is_object($voicemail) && method_exists($voicemail, 'getVoicemailBoxByExtension')) {
					$box = $voicemail->getVoicemailBoxByExtension($extension);

					if (!empty($box['email'])) {
						return (string) $box['email'];
					}
				}
			}
		} catch (\Exception $e) {
			// keep going
		}

		try {
			if (class_exists('\FreePBX')) {
				$userman = \FreePBX::create()->Userman;

				if (is_object($userman) && method_exists($userman, 'getUserByDefaultExtension')) {
					$user = $userman->getUserByDefaultExtension($extension);

					if (!empty($user['email'])) {
						return (string) $user['email'];
					}
				}
			}
		} catch (\Exception $e) {
			// keep going
		}

		return '';
	}

	/**
	 * Call a method on the core module, swallowing failures.
	 *
	 * @return mixed
	 */
	private function coreCall($method, $argument)
	{
		try {
			$core = \FreePBX::Core();

			if (!is_object($core) || !method_exists($core, $method)) {
				return null;
			}

			return $core->$method($argument);
		} catch (\Exception $e) {
			return null;
		}
	}

	/**
	 * Raw sipsettings keyword/value pairs.
	 *
	 * @return array
	 */
	private function sipSettings()
	{
		if ($this->sipSettings !== null) {
			return $this->sipSettings;
		}

		$this->sipSettings = array();

		try {
			$pdo = Connection::get();
			$statement = $pdo->query('SELECT keyword, data FROM sipsettings');

			if ($statement !== false) {
				foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
					$this->sipSettings[$row['keyword']] = $row['data'];
				}
			}
		} catch (\Exception $e) {
			$this->sipSettings = array();
		}

		return $this->sipSettings;
	}
}
