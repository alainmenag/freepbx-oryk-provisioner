<?php

// src/Matcher.php

namespace FreePBX\Modules\Oryk_Provisioner;

use PDO;

/**
 * A filename is a MAC and a name, read both ways.
 *
 * Take this client's MAC back out of what the phone asked for and what is
 * left is the file it wants -- .cfg, phone.cfg, cfg.xml -- and that is
 * what a resource is named after. matchResource() goes from a request to
 * a resource; resourceRequest() is the same thinking backwards, from a
 * resource and a client to the request that reaches it, which is what the
 * preview links are drawn from.
 */
class Matcher extends Service
{
	/**
	 * @var Template
	 */
	private $template;

	/**
	 * @param object $freepbx FreePBX application instance.
	 */
	public function __construct($freepbx, Template $template)
	{
		parent::__construct($freepbx);

		$this->template = $template;
	}

	/**
	 * Which of a profile's resources answers to a requested filename.
	 *
	 * Two ways, and a name written out in full wins:
	 *
	 *   {{device.mac}}-phone.cfg  rendered with this client's values and
	 *                             compared to what was actually asked for, so
	 *                             one resource covers every client on the
	 *                             profile -- and a vendor that does not put
	 *                             the MAC at the front, or anywhere, can
	 *                             still be named exactly.
	 *   phone.cfg                 compared against the request with the MAC
	 *                             taken off the front, so the ordinary case
	 *                             can be typed as the tail on its own.
	 *
	 * Both are the same string in the same column, which is the point: the
	 * second is only what the first becomes when it has no placeholders in
	 * it. There is nothing to declare and nothing to migrate later.
	 *
	 * @param int                   $profileId Profile the resources belong to.
	 * @param string                $requested Filename as it was asked for.
	 * @param string                $suffix    The same, with this client's MAC removed.
	 * @param array<string, string> $values    Placeholder name to value.
	 *
	 * @return array<string, mixed>|null The resource, or null when none answers.
	 */
	public function matchResource($profileId, $requested, $suffix, array $values)
	{
		$stmt = $this->db->prepare(
			"SELECT id, name, type, template, file_size
			FROM `{$this->resourcesTable}`
			WHERE profile_id = :profile_id
			ORDER BY name"
		);
		$stmt->execute([':profile_id' => (int) $profileId]);

		$fallback = null;

		foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $resource) {
			$name = (string) $resource['name'];

			// Filenames are matched without regard to case throughout: a
			// phone asking for 0004F282E824.cfg and one asking for
			// 0004f282e824.cfg are the same phone asking for the same file.
			if (strcasecmp($this->template->renderTemplate($name, $values), $requested) === 0) {
				return $resource;
			}

			// Held rather than returned. A rendered name is what its author
			// wrote out in full, and it wins over one that only matches the
			// tail of the request.
			if ($fallback === null && $suffix !== '' && strcasecmp($name, $suffix) === 0) {
				$fallback = $resource;
			}
		}

		return $fallback;
	}

	/**
	 * A requested filename with this client's own MAC taken off the front.
	 *
	 * Phones ask by MAC, in whatever separator style they favour:
	 * 0004f282e824-phone.cfg, 00:04:f2:82:e8:24-phone.cfg and
	 * 0004f282e824.cfg are all one client asking. What is left is the part a
	 * resource can be named after -- phone.cfg, and .cfg for the main config,
	 * which is a resource of the profile like any other file it serves.
	 *
	 * A filename that does not begin with this client's MAC comes back
	 * unchanged: it is either meant literally or meant for somebody else, and
	 * neither is helped by having something trimmed off it.
	 *
	 * Read a character at a time, stopping the moment twelve hex digits are
	 * in hand, because a pattern that allows a dot between them will also
	 * take the dot of the extension: [mac].cfg has to come back as `.cfg`,
	 * not `cfg`. Stopping at the twelfth digit means nothing past the MAC is
	 * ever looked at, and 0004.f282.e824 grouping costs nothing extra.
	 *
	 * @param string $filename Last segment of the requested path.
	 * @param string $mac      This client's normalised MAC.
	 *
	 * @return string The filename, or what is left of it.
	 */
	public function resourceSuffix($filename, $mac)
	{
		$hex = '';
		$end = 0;

		for ($i = 0, $length = strlen($filename); $i < $length; $i++) {
			$char = $filename[$i];

			if (ctype_xdigit($char)) {
				$hex .= $char;
				$end = $i + 1;

				if (strlen($hex) === 12) {
					break;
				}

				continue;
			}

			// A separator, but only once there is something for it to
			// separate: a name starting with one is not a MAC.
			if ($hex !== '' && ($char === ':' || $char === '-' || $char === '.')) {
				continue;
			}

			break;
		}

		if (strlen($hex) !== 12 || strtolower($hex) !== $mac) {
			return $filename;
		}

		$rest = substr($filename, $end);

		// The dot of an extension belongs to the name that is left --
		// [mac].cfg is `.cfg` -- where a dash or an underscore is only the
		// vendor's way of joining the two, and part of neither.
		return ($rest !== '' && $rest[0] === '.') ? $rest : ltrim($rest, '-_');
	}

	/**
	 * What one client asks for when it asks for one resource, and where.
	 *
	 * matchResource() read backwards. A name is matched either as it renders
	 * or as the tail of a request with the MAC taken off the front, so the
	 * request that reaches this resource is one of:
	 *
	 *   {{device.mac}}-phone.cfg  renders to 0004f282e824-phone.cfg, which is
	 *                             asked for as it stands.
	 *   phone.cfg                 has no MAC to render, so the phone asks for
	 *                             0004f282e824-phone.cfg and resourceSuffix()
	 *                             takes the MAC back off. Joined by nothing
	 *                             when the name is an extension of its own
	 *                             (.cfg), by a dash otherwise.
	 *
	 * Whether that request can be *linked* is a second question, because the
	 * endpoint reads the MAC out of the path: a name that renders with this
	 * client's MAC in it says who is asking, and one with no MAC at all can
	 * say so with ?mac=. A name carrying somebody else's twelve hex digits --
	 * 000000000000-directory.xml, which is a real filename a real phone asks
	 * for -- cannot: the endpoint takes the MAC from the path over the query
	 * string, so the link would render the wrong client. Those rows get the
	 * filename and no link, which is the truth about them.
	 *
	 * @param string                $name   Resource name, as typed.
	 * @param array<string, string> $values Placeholder name to value.
	 * @param string                $mac    This client's normalised MAC.
	 *
	 * @return array{filename: string, url: string} What it asks for, and where -- '' when there is no such URL.
	 */
	public function resourceRequest($name, array $values, $mac)
	{
		$name = (string) $name;
		$rendered = $this->template->renderTemplate($name, $values);
		$carries = Mac::find($rendered);

		if ($carries === $mac) {
			return ['filename' => $rendered, 'url' => $this->engineUrl($rendered)];
		}

		// Somebody else's twelve hex digits, written into the name and asked
		// for exactly as they stand -- 000000000000-directory.xml is a phone
		// asking every profile for the same file. It is served, and it is
		// not linkable: the endpoint would read that MAC as the client.
		if ($carries !== '') {
			return ['filename' => $rendered, 'url' => ''];
		}

		// No placeholders and no MAC of its own, so the phone asks for the
		// MAC and this name joined and the endpoint takes the MAC back off
		// again.
		if ($rendered === $name && $name !== '') {
			$joined = $mac . ($name[0] === '.' ? '' : '-') . $name;

			if (Mac::find($joined) === $mac && strcasecmp($this->resourceSuffix($joined, $mac), $name) === 0) {
				return ['filename' => $joined, 'url' => $this->engineUrl($joined)];
			}
		}

		// Nothing in the name says which client is asking, so the request
		// says it the endpoint's other way.
		return ['filename' => $rendered, 'url' => $this->engineUrl($rendered) . '?mac=' . rawurlencode($mac)];
	}

	/**
	 * A resource of type File, by the name it is asked for.
	 *
	 * The lookup behind a request that reaches no profile. Matched exactly,
	 * and only against the name as it was typed: with no client there is no
	 * MAC to take out of the request and nothing to render a templated name
	 * against, so a file meant to be fetched this way is named exactly what
	 * the vendor asks for -- 3111-44500-001.sip.ld.
	 *
	 * Narrowed to type = 'file' and not to file_size IS NOT NULL, which is
	 * the same set today and a different question: what may be handed to a
	 * caller who has said nothing about who they are is what its author
	 * declared a file, and a declared file with nothing uploaded to it is
	 * refused by name rather than passed over as though it did not exist.
	 *
	 * Case is the collation's business rather than LOWER()'s. The column is
	 * utf8mb4 case-insensitive, so a plain comparison already ignores case and
	 * can use the index on `name`, where wrapping the column in a function
	 * would be just as correct and would guarantee a scan.
	 *
	 * Two profiles carrying the same firmware is the ordinary case rather than
	 * an ambiguity worth refusing -- they hold the same bytes -- so the lowest
	 * id wins rather than the request failing over which of them it meant.
	 *
	 * @param string $requested Filename as it was asked for.
	 *
	 * @return array<string, mixed>|null The resource, or null when none answers.
	 */
	public function fileByName($requested)
	{
		$stmt = $this->db->prepare(
			"SELECT id, profile_id, name, type, file_size
			FROM `{$this->resourcesTable}`
			WHERE name = :name AND type = 'file'
			ORDER BY id
			LIMIT 1"
		);
		$stmt->execute([':name' => (string) $requested]);

		return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
	}

	/**
	 * What a file is served as.
	 *
	 * Read off the name rather than stored against the resource: an author
	 * who called a file directory.xml has already said what it is, and a
	 * second field saying it again is a second field to get wrong.
	 *
	 * The two kinds of resource that are served want different answers to an
	 * extension nothing here recognises, so the type is the second argument
	 * rather than another list of extensions to keep up with. A template with
	 * an odd extension is still configuration text, which is what .cfg is and
	 * what every phone here expects; an uploaded file is bytes somebody chose,
	 * and sending a firmware image as text is how it arrives corrupted. A log
	 * is never sent anywhere, so it never reaches this.
	 *
	 * @param string $name Resource name, which is the filename it is served as.
	 * @param string $kind The resource's type -- 'template' or 'file'.
	 *
	 * @return string Content type, without the charset.
	 */
	public function contentType($name, $kind = 'template')
	{
		switch (strtolower((string) pathinfo($name, PATHINFO_EXTENSION))) {
			case 'xml':
				return 'text/xml';

			case 'json':
				return 'application/json';

			case 'cfg':
			case 'conf':
			case 'ini':
			case 'txt':
				return 'text/plain';

			default:
				return $kind === 'file' ? 'application/octet-stream' : 'text/plain';
		}
	}

	/**
	 * The URL a phone is given for a filename.
	 *
	 * The web-root symlink rather than the module's own path, because that is
	 * the URL a phone is provisioned with and so the one worth showing.
	 *
	 * @param string $filename Filename as it would be asked for.
	 *
	 * @return string Absolute path under the engine link.
	 */
	public function engineUrl($filename)
	{
		// A colon is legal in a path segment and is how half the vendors
		// separate a MAC, so it is left as it is rather than escaped into
		// something the endpoint's own reading of the path would miss.
		return '/' . $this->engineLink . '/' . str_replace('%3A', ':', rawurlencode((string) $filename));
	}
}
