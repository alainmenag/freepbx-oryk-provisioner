<?php

// src/HandlerFailed.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * A handler of a service event threw: the step it was on fails, and the job stops there.
 */
class HandlerFailed extends \RuntimeException
{
	/** @var string Rawname of the module whose handler threw; the module's own is `oryk_provisioner`. */
	public $handler;

	/**
	 * @param string $handler Rawname of the module whose handler threw.
	 * @param string $message What it said.
	 */
	public function __construct($handler, $message)
	{
		$this->handler = (string) $handler;

		parent::__construct($this->handler . ': ' . ((string) $message !== '' ? (string) $message : _('failed, and said nothing')));
	}
}
