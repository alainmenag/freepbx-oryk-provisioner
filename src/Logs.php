<?php

// src/Logs.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * How this module writes to the FreePBX log.
 *
 * A trait rather than a method on Service because the module class needs it
 * too and cannot extend Service -- it extends FreePBX_Helpers, the BMO
 * contract.
 */
trait Logs
{
	/**
	 * Write a line to the FreePBX log.
	 *
	 * @param mixed  $message What happened.
	 * @param mixed  $data    Anything worth carrying with it; encoded if it is not a string.
	 * @param string $level   FreePBX log level, without the FPBX_LOG_ prefix.
	 *
	 * @return void
	 */
	public function log(mixed $message = '', mixed $data = '', $level = 'DEBUG')
	{
		$constant = 'FPBX_LOG_' . $level;
		$data = is_string($data) ? $data : ($data ? json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '');
		try {
			$l = defined($constant) ? constant($constant) : $level;
			$this->FreePBX->Logger->log($l, trim($message . ' ' . $data));
		} catch (\Throwable $e) {
			// Nowhere to report it that is not the thing that just failed.
			error_log($e->getMessage());
		}
	}
}
