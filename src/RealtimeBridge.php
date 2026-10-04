<?php

// src/RealtimeBridge.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * A sign-up's endpoint, auth and AOR, live before Apply Config writes them.
 *
 * Asterisk is told (sorcery.conf, extconfig.conf) to look up PJSIP endpoints,
 * auths and AORs in its config files first and in three tables of this
 * module's second, so a row here is found the moment a phone registers, and
 * once Apply Config has written the same id to the files the files win. The
 * minute job deletes the rows after that -- see ARCHITECTURE.md, "The
 * Realtime bridge".
 *
 * **Unverified on a PBX.** ODBC, the table names in extconfig.conf and the
 * sorcery mapping are written from Asterisk's documentation; the checklist in
 * the open-signup plan has to pass on a test PBX before this is relied on.
 * Nothing fails when the Asterisk side is missing: available() says no, and a
 * sign-up waits for Apply Config like any other user.
 */
class RealtimeBridge extends Service
{
	/**
	 * The res_odbc connection Asterisk reads the tables through: the one
	 * FreePBX defines for CDR, which logs in as the FreePBX database user and
	 * so can read this database too. The tables are named database-qualified
	 * in extconfig.conf for that reason.
	 */
	const ODBC = 'asteriskcdrdb';

	/** This module's tables, by sorcery object type. Interpolated, so literals. */
	const TABLES = [
		'endpoint' => 'oryk_provisioner_ps_endpoints',
		'auth' => 'oryk_provisioner_ps_auths',
		'aor' => 'oryk_provisioner_ps_aors',
	];

	/** The Realtime family Asterisk asks for, by sorcery object type. */
	const FAMILIES = [
		'endpoint' => 'ps_endpoints',
		'auth' => 'ps_auths',
		'aor' => 'ps_aors',
	];

	/** Columns each table has: the options written, each named as Asterisk names it. */
	const COLUMNS = [
		'endpoint' => ['transport', 'aors', 'auth', 'context', 'disallow', 'allow', 'direct_media',
			'dtmf_mode', 'force_rport', 'rewrite_contact', 'rtp_symmetric', 'ice_support', 'mailboxes',
			'callerid', 'media_encryption', 'media_encryption_optimistic', 'allow_transfer'],
		'auth' => ['auth_type', 'username', 'password'],
		'aor' => ['max_contacts', 'remove_existing', 'qualify_frequency'],
	];

	/** First and last lines of what install() adds to an Asterisk config file. */
	const BEGIN = '; BEGIN oryk_provisioner realtime bridge -- written by the module; see its ARCHITECTURE.md';
	const END = '; END oryk_provisioner realtime bridge';

	/** @var string Asterisk's config directory. */
	private $etc;

	/** @var bool|null Whether the tables and config are there; asked once a request. */
	private $available;

	/** @var bool|null Whether the three tables exist; asked once a request. */
	private $tables;

	/** @var Settings */
	private $settings;

	/**
	 * @param object      $freepbx FreePBX application instance.
	 * @param string|null $etc     Asterisk's config directory, if not ASTETCDIR.
	 */
	public function __construct($freepbx, $etc = null)
	{
		parent::__construct($freepbx);

		$this->settings = new Settings($freepbx);

		if ($etc === null) {
			try {
				$etc = (string) \FreePBX::Config()->get('ASTETCDIR');
			} catch (\Throwable $e) {
				$etc = '';
			}
		}

		$this->etc = rtrim($etc !== '' ? $etc : '/etc/asterisk', '/');
	}

	/**
	 * Whether a row written here would be read by Asterisk: the tables exist
	 * and both config files carry install()'s block.
	 *
	 * Not whether Asterisk has been restarted since -- a sorcery mapping is read
	 * at start -- which only means a row written before that is not used.
	 *
	 * @return bool True when the bridge is set up.
	 */
	public function available()
	{
		if ($this->available === null) {
			$this->available = $this->tablesExist()
				&& $this->hasBlock('extconfig.conf')
				&& $this->hasBlock('sorcery.conf');
		}

		return $this->available;
	}

	/**
	 * Put a user's endpoint, auth and AOR in the bridge, replacing any it had.
	 *
	 * Built from the device as Core now holds it, so a call after store() gets
	 * what Apply Config will write -- see rows().
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return bool True when the rows were written.
	 */
	public function add($extension)
	{
		$extension = trim((string) $extension);

		if ($extension === '' || !ctype_digit($extension) || !$this->available()) {
			return false;
		}

		$device = \FreePBX::Core()->getDevice($extension);

		if (empty($device['id'])) {
			return false;
		}

		$rows = self::rows($device, $this->lobbyContext());

		try {
			$this->delete($extension);

			foreach ($rows as $type => $row) {
				$columns = array_keys($row);
				$table = self::TABLES[$type];

				$this->db->prepare(
					"INSERT INTO `$table` (`" . implode('`, `', $columns) . "`)
					VALUES (:" . implode(', :', $columns) . ')'
				)->execute(array_combine(array_map(function ($column) {
					return ':' . $column;
				}, $columns), array_values($row)));
			}
		} catch (\Exception $e) {
			$this->logError('could not bridge ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Take a user out of the bridge.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return bool True when nothing of it is left there.
	 */
	public function remove($extension)
	{
		$extension = trim((string) $extension);

		if ($extension === '' || !$this->tablesExist()) {
			return true;
		}

		try {
			$this->delete($extension);
		} catch (\Exception $e) {
			$this->logError('could not unbridge ' . $extension . ': ' . $e->getMessage());

			return false;
		}

		return true;
	}

	/**
	 * Whether a user is in the bridge.
	 *
	 * @param int|string $extension Extension/user number.
	 *
	 * @return bool True when its endpoint row is there.
	 */
	public function has($extension)
	{
		if (!$this->tablesExist()) {
			return false;
		}

		try {
			$stmt = $this->db->prepare('SELECT COUNT(*) FROM `' . self::TABLES['endpoint'] . '` WHERE id = :id');
			$stmt->execute([':id' => (string) $extension]);

			return (bool) $stmt->fetchColumn();
		} catch (\Exception $e) {
			return false;
		}
	}

	/**
	 * The three rows a device is bridged as.
	 *
	 * Named as FreePBX names them -- endpoint and AOR the extension, auth
	 * `<extension>-auth` -- so Apply Config's objects replace these one for one.
	 * The security-relevant options -- context, credentials, media encryption --
	 * are the device's own; transfer is off for a lobby endpoint, as
	 * Users::endpointExtras() makes it in the files.
	 *
	 * @param array<string, mixed> $device Core's device: devices row plus its `sip` keywords.
	 * @param string               $lobby  ORYK_OPEN_CONTEXT.
	 *
	 * @return array<string, array<string, string|null>> Rows by type, each with its `id`.
	 */
	public static function rows(array $device, $lobby)
	{
		$id = (string) $device['id'];
		$value = function ($keyword, $default = null) use ($device) {
			$found = isset($device[$keyword]) ? trim((string) $device[$keyword]) : '';

			return $found !== '' ? $found : $default;
		};
		$context = $value('context', 'from-internal');
		$name = str_replace(['"', '<', '>'], '', (string) $value('description', $id));

		return [
			'endpoint' => [
				'id' => $id,
				'transport' => $value('transport'),
				'aors' => $id,
				'auth' => $id . '-auth',
				'context' => $context,
				'disallow' => 'all',
				'allow' => $value('allow', 'ulaw,alaw,g722'),
				'direct_media' => $value('direct_media', 'no'),
				'dtmf_mode' => $value('dtmfmode', 'rfc4733'),
				'force_rport' => $value('force_rport', 'yes'),
				'rewrite_contact' => $value('rewrite_contact', 'yes'),
				'rtp_symmetric' => $value('rtp_symmetric', 'yes'),
				'ice_support' => $value('icesupport', 'no'),
				'mailboxes' => $id . '@default',
				'callerid' => '"' . $name . '" <' . $id . '>',
				'media_encryption' => $value('media_encryption', 'sdes'),
				'media_encryption_optimistic' => $value('media_encryption_optimistic', 'yes'),
				'allow_transfer' => $context === (string) $lobby ? 'no' : 'yes',
			],
			'auth' => [
				'id' => $id . '-auth',
				'auth_type' => 'userpass',
				'username' => $id,
				'password' => (string) $value('secret', ''),
			],
			'aor' => [
				'id' => $id,
				'max_contacts' => $value('max_contacts', '1'),
				'remove_existing' => $value('remove_existing', 'yes'),
				'qualify_frequency' => $value('qualifyfreq', '60'),
			],
		];
	}

	/**
	 * Create the tables and write the Asterisk side.
	 *
	 * Nothing here fails an install: a step that cannot be done is reported,
	 * and the bridge stays unavailable.
	 *
	 * @return array<int, string> What an operator should be told.
	 */
	public function install()
	{
		$messages = [];

		foreach (self::TABLES as $type => $table) {
			$columns = array_map(function ($column) {
				return "`$column` VARCHAR(255) NULL DEFAULT NULL";
			}, self::COLUMNS[$type]);

			try {
				$this->db->exec(
					"CREATE TABLE IF NOT EXISTS `$table` (
						`id` VARCHAR(40) NOT NULL,
						" . implode(",\n\t\t\t\t\t\t", $columns) . ",
						PRIMARY KEY (`id`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
				);
			} catch (\Exception $e) {
				$messages[] = 'could not create ' . $table . ': ' . $e->getMessage();
			}
		}

		$database = self::databaseName();
		$families = [];

		foreach (self::FAMILIES as $type => $family) {
			$families[] = $family . ' => odbc,' . self::ODBC . ',' . $database . '.' . self::TABLES[$type];
		}

		$wrote = $this->writeBlock('extconfig.conf', 'settings', $families, $messages);

		$mappings = [];

		foreach (self::FAMILIES as $type => $family) {
			$mappings[] = $type . '=config,pjsip.conf,criteria=type=' . $type;
			$mappings[] = $type . '=realtime,' . $family;
		}

		$wrote = $this->writeBlock('sorcery.conf', 'res_pjsip', $mappings, $messages) && $wrote;

		if ($wrote) {
			$messages[] = 'sign-ups register before Apply Config once Asterisk has been restarted (fwconsole restart), '
				. 'with res_odbc, res_config_odbc and res_sorcery_realtime loaded';
		}

		$this->available = null;
		$this->tables = null;

		return $messages;
	}

	/**
	 * Take the Asterisk side back out. The tables are left, as every table is.
	 *
	 * @return void
	 */
	public function uninstall()
	{
		foreach (['extconfig.conf', 'sorcery.conf'] as $file) {
			$path = $this->etc . '/' . $file;
			$text = is_file($path) ? (string) @file_get_contents($path) : '';

			if (strpos($text, self::BEGIN) !== false) {
				@file_put_contents($path, self::withoutBlock($text), LOCK_EX);
			}
		}
	}

	/**
	 * A config file's text with install()'s block taken out.
	 *
	 * @param string $text The file.
	 *
	 * @return string The file without it.
	 */
	public static function withoutBlock($text)
	{
		$pattern = '/\n?' . preg_quote(self::BEGIN, '/') . '.*?' . preg_quote(self::END, '/') . '\n?/s';

		return (string) preg_replace($pattern, "\n", (string) $text);
	}

	/**
	 * The block install() adds to a config file.
	 *
	 * `(+)` adds to a section the file already has; a file without one gets it.
	 *
	 * @param string             $section  Section the lines belong in.
	 * @param array<int, string> $lines    The lines.
	 * @param bool               $existing Whether the file already has that section.
	 *
	 * @return string The block, ending in a newline.
	 */
	public static function block($section, array $lines, $existing)
	{
		return self::BEGIN . "\n[" . $section . ']' . ($existing ? '(+)' : '') . "\n"
			. implode("\n", $lines) . "\n" . self::END . "\n";
	}

	/**
	 * Add, or replace, this module's block in an Asterisk config file.
	 *
	 * sorcery.conf is refused when something else already maps res_pjsip: two
	 * mappings for one type would read the config files twice.
	 *
	 * @param string             $file     File name under Asterisk's config directory.
	 * @param string             $section  Section the lines belong in.
	 * @param array<int, string> $lines    The lines.
	 * @param array<int, string> $messages Added to when it cannot be written.
	 *
	 * @return bool True when the file carries the block.
	 */
	private function writeBlock($file, $section, array $lines, array &$messages)
	{
		$path = $this->etc . '/' . $file;
		$text = is_file($path) ? (string) @file_get_contents($path) : '';
		$text = rtrim(self::withoutBlock($text), "\n");
		$existing = (bool) preg_match('/^\s*\[' . preg_quote($section, '/') . '\]/m', $text);

		if ($file === 'sorcery.conf' && $existing) {
			$messages[] = $path . ' already maps res_pjsip; add the realtime mappings there by hand to let sign-ups register before Apply Config';

			return false;
		}

		$written = @file_put_contents($path, ($text !== '' ? $text . "\n\n" : '') . self::block($section, $lines, $existing), LOCK_EX);

		if ($written === false) {
			$messages[] = 'could not write ' . $path;

			return false;
		}

		return true;
	}

	/**
	 * Whether a config file carries this module's block.
	 *
	 * @param string $file File name under Asterisk's config directory.
	 *
	 * @return bool True when it does.
	 */
	private function hasBlock($file)
	{
		$path = $this->etc . '/' . $file;

		return is_readable($path) && strpos((string) @file_get_contents($path), self::BEGIN) !== false;
	}

	/**
	 * Whether all three tables exist.
	 *
	 * @return bool True when they do.
	 */
	private function tablesExist()
	{
		if ($this->tables !== null) {
			return $this->tables;
		}

		try {
			$stmt = $this->db->prepare(
				'SELECT COUNT(*) FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (:endpoint, :auth, :aor)'
			);
			$stmt->execute([':endpoint' => self::TABLES['endpoint'], ':auth' => self::TABLES['auth'], ':aor' => self::TABLES['aor']]);
			$this->tables = (int) $stmt->fetchColumn() === 3;
		} catch (\Exception $e) {
			$this->tables = false;
		}

		return $this->tables;
	}

	/**
	 * Delete a user's rows from all three tables.
	 *
	 * @param string $extension Extension/user number.
	 *
	 * @return void
	 */
	private function delete($extension)
	{
		foreach (self::TABLES as $type => $table) {
			$this->db->prepare("DELETE FROM `$table` WHERE id = :id")
				->execute([':id' => $type === 'auth' ? $extension . '-auth' : $extension]);
		}
	}

	/**
	 * The context sign-ups are put in.
	 *
	 * @return string ORYK_OPEN_CONTEXT.
	 */
	private function lobbyContext()
	{
		return (string) $this->settings->get(Settings::OPEN_CONTEXT);
	}

	/**
	 * The FreePBX database's name, as it is written into extconfig.conf.
	 *
	 * @return string AMPDBNAME, or asterisk.
	 */
	private static function databaseName()
	{
		try {
			$name = (string) \FreePBX::Config()->get('AMPDBNAME');
		} catch (\Throwable $e) {
			$name = '';
		}

		return preg_match('/^[A-Za-z0-9_]+$/', $name) ? $name : 'asterisk';
	}
}
