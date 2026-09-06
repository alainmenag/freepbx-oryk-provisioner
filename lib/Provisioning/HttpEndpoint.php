<?php

namespace Oryk\Provisioner\Provisioning;

use Oryk\Provisioner\Exception\NotFoundException;

/**
 * HTTP front for the provisioning engine.
 *
 * Responses are deliberately boring:
 *   200 + the template's content type on success
 *   404 for an invalid token, a disabled device or an unknown filename
 *       (identical in all three cases, so nothing is revealed)
 *   500 when a template fails to render, with the detail kept in the log
 */
class HttpEndpoint
{
	/** @var Engine */
	private $engine;

	public function __construct(Engine $engine)
	{
		$this->engine = $engine;
	}

	/**
	 * Handle a provisioning request and write the response.
	 *
	 * @param string|null $token    Defaults to the request.
	 * @param string|null $filename Defaults to the request.
	 */
	public function handle($token = null, $filename = null)
	{
		$request = self::requestInfo();

		if ($token === null || $filename === null) {
			$parsed = self::parseRequest();
			$token = $token === null ? $parsed['token'] : $token;
			$filename = $filename === null ? $parsed['filename'] : $filename;
		}

		try {
			$file = $this->engine->provision($token, $filename, $request);
			$this->emitFile($file);
		} catch (NotFoundException $e) {
			$this->emitNotFound();
		} catch (\Exception $e) {
			$this->emitError();
		}
	}

	/**
	 * Pull the token and filename out of the request.
	 *
	 * Supported shapes:
	 *   ?token=x&filename=y                     (config.php and provision.php)
	 *   provision.php/{token}/{filename}        (PATH_INFO)
	 *   /provisioner/{token}/{filename}         (rewrite)
	 *
	 * @return array
	 */
	public static function parseRequest()
	{
		$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
		$filename = isset($_GET['filename']) ? (string) $_GET['filename'] : '';

		if ($token !== '' && $filename !== '') {
			return array('token' => $token, 'filename' => $filename);
		}

		$path = isset($_SERVER['PATH_INFO']) ? (string) $_SERVER['PATH_INFO'] : '';

		if ($path === '' && isset($_SERVER['REQUEST_URI'])) {
			$uri = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);

			if (is_string($uri) && strpos($uri, UrlBuilder::FRIENDLY_PREFIX . '/') !== false) {
				$path = substr($uri, strpos($uri, UrlBuilder::FRIENDLY_PREFIX . '/') + strlen(UrlBuilder::FRIENDLY_PREFIX));
			}
		}

		$parts = array_values(array_filter(explode('/', trim($path, '/')), function ($part) {
			return $part !== '';
		}));

		if (count($parts) >= 2) {
			$token = $token !== '' ? $token : rawurldecode($parts[0]);
			$filename = $filename !== '' ? $filename : rawurldecode($parts[1]);
		} elseif (count($parts) === 1 && $token === '') {
			$token = rawurldecode($parts[0]);
		}

		return array('token' => $token, 'filename' => $filename);
	}

	/**
	 * Client details used for logging and access checks.
	 *
	 * @return array
	 */
	public static function requestInfo()
	{
		$secure = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
			|| (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
				&& strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

		return array(
			'ip'        => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
			'userAgent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
			'secure'    => $secure,
		);
	}

	/**
	 * Send a rendered configuration file.
	 */
	public function emitFile(RenderedFile $file)
	{
		$this->cleanOutput();

		if (!headers_sent()) {
			header('HTTP/1.1 200 OK');
			header('Content-Type: ' . $file->contentType);
			header('Content-Length: ' . $file->size());
			header('Content-Disposition: inline; filename="' . $file->filename . '"');
			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			header('Pragma: no-cache');
			header('X-Content-Type-Options: nosniff');
		}

		echo $file->content;
	}

	/**
	 * Identical answer for every unsuccessful lookup.
	 */
	public function emitNotFound()
	{
		$this->cleanOutput();

		if (!headers_sent()) {
			header('HTTP/1.1 404 Not Found');
			header('Content-Type: text/plain');
			header('Cache-Control: no-store');
		}

		echo "Not Found\n";
	}

	/**
	 * Rendering failed. No parameter values are ever exposed here.
	 */
	public function emitError()
	{
		$this->cleanOutput();

		if (!headers_sent()) {
			header('HTTP/1.1 500 Internal Server Error');
			header('Content-Type: text/plain');
			header('Cache-Control: no-store');
		}

		echo "Internal Server Error\n";
	}

	/**
	 * Drop anything FreePBX may already have buffered so the device receives
	 * the raw configuration and nothing else.
	 */
	private function cleanOutput()
	{
		while (ob_get_level() > 0) {
			@ob_end_clean();
		}
	}
}
