<?php

// src/Template.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * A placeholder, and what it resolves to.
 *
 * {{name}} with dotted names, a flat map behind it, and a name nothing
 * answers to rendering empty -- a phone copes with an empty value, it
 * does not cope with a literal {{ }} where a value belongs. The map is
 * built here so the endpoint, the two preview tabs and the resource
 * editor's placeholder list are all reading the same one.
 */
class Template extends Service
{
	/**
	 * @var Freepbx
	 */
	private $pbx;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Freepbx $pbx)
	{
		parent::__construct($freepbx);

		$this->pbx = $pbx;
	}

	/**
	 * Fill in a template's {{ }} placeholders.
	 *
	 * The delimiters and the dotted names are the ones the full engine will
	 * use, so profiles written against this keep rendering: what is missing
	 * is filters, sections and escaping, not the syntax.
	 *
	 * A name nothing answers to renders as nothing. A phone parsing a config
	 * copes with an empty value; it does not cope with a literal `{{ }}` left
	 * where a value was meant to be.
	 *
	 * @param string                $template Template text.
	 * @param array<string, string> $values   Placeholder name to value.
	 *
	 * @return string Rendered configuration.
	 */
	public function renderTemplate($template, array $values)
	{
		return preg_replace_callback(
			'/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/',
			function ($match) use ($values) {
				return $values[$match[1]] ?? '';
			},
			$template
		);
	}

	/**
	 * What a template can refer to, as a flat map of dotted names.
	 *
	 * Flat and dotted rather than nested, because that is what the
	 * placeholders are: `{{device.mac}}` is a key here, not a path walked
	 * through arrays. The eventual resolver puts sources in precedence order
	 * behind the same names.
	 *
	 * A client with no FreePBX device still renders -- everything the
	 * client would have answered for is simply empty, which is what a profile
	 * of pure static configuration wants anyway.
	 *
	 * @param array<string, mixed> $row Client row from clientByMac().
	 *
	 * @return array<string, string> Placeholder name to value.
	 */
	public function provisioningValues(array $row)
	{
		$mac = (string) $row['mac'];
		$colon = implode(':', str_split($mac, 2));

		$sip = $this->pbx->deviceSipSettings($row['device_id'] ?? null);
		$extension = $this->pbx->extensionRow($row['extension'] ?? null);

		$values = [
			'device.mac' => $mac,
			'device.mac_upper' => strtoupper($mac),
			'device.mac_colon' => $colon,
			'device.mac_colon_upper' => strtoupper($colon),
			'device.id' => (string) ($row['device_id'] ?? ''),
			'device.description' => (string) ($row['description'] ?? ''),
			'device.tech' => (string) ($row['tech'] ?? ''),
			'device.username' => (string) ($sip['username'] ?? $row['device_id'] ?? ''),
			'device.secret' => (string) ($sip['secret'] ?? ''),
			'extension.number' => (string) ($row['extension'] ?? ''),
			'extension.name' => (string) ($extension['name'] ?? ''),
			'extension.voicemail' => (string) ($extension['voicemail'] ?? ''),
			'profile.id' => (string) ($row['profile_id'] ?? ''),
			'profile.name' => (string) ($row['profile_name'] ?? ''),
			'server.host' => $this->serverHost(),
			'server.port' => '5060',
		];

		// Everything else the device is configured with in FreePBX, under its
		// own prefix: transport, callerid, dtmfmode and the rest are vendor
		// business, so the template asks for what it needs by name rather
		// than this file deciding in advance what a phone might want.
		foreach ($sip as $keyword => $data) {
			$values['sip.' . $keyword] = $data;
		}

		return $values;
	}

	/**
	 * The host a phone would register against.
	 *
	 * Taken from the request, which is the host the administrator is looking
	 * at the PBX on and, on a single-address system, the one the phones use.
	 * A module setting overrides it once there are settings to hold one.
	 *
	 * @return string Hostname or address, without a port.
	 */
	private function serverHost()
	{
		$host = (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
		$host = preg_replace('/:\d+$/', '', $host);

		return $host !== '' ? $host : (string) ($_SERVER['SERVER_ADDR'] ?? '');
	}

	/**
	 * What a template can refer to, as the editor lists it.
	 *
	 * Written out here rather than derived from a rendering, because the
	 * resource editor has to be able to say what the names are with no client
	 * in hand -- a new resource's profile may not be assigned to anything yet.
	 * The `sip.` names are whatever the device carries in FreePBX, so the view
	 * names a few by way of example instead of listing them.
	 *
	 * @return array<string, array<string, string>> Group heading to name and note.
	 */
	public function templatePlaceholders()
	{
		return [
			_('Device') => [
				'device.mac' => _('00908f3bbcba'),
				'device.mac_upper' => _('00908F3BBCBA'),
				'device.mac_colon' => _('00:90:8f:3b:bc:ba'),
				'device.mac_colon_upper' => _('00:90:8F:3B:BC:BA'),
				'device.id' => _('FreePBX device'),
				'device.description' => _('Device description'),
				'device.tech' => _('pjsip or sip'),
				'device.username' => _('SIP username'),
				'device.secret' => _('SIP secret'),
			],
			_('Extension') => [
				'extension.number' => _('Extension the device is attached to'),
				'extension.name' => _('Display name'),
				'extension.voicemail' => _('Voicemail setting'),
			],
			_('Profile and server') => [
				'profile.name' => _('This profile'),
				'server.host' => _('Host the PBX is reached on'),
				'server.port' => _('5060'),
			],
		];
	}
}
