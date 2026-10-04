<?php

// src/SignupRefused.php

namespace FreePBX\Modules\Oryk_Provisioner;

/**
 * An open-provisioning sign-up refused for a reason the security log names:
 * a reserved username, or one of the sign-up limits.
 */
class SignupRefused extends \RuntimeException
{
	/** @var string What refused it, as the security log says it: reserved, per-minute, per-day, daily total. */
	private $reason;

	/** @var int HTTP status the phone is answered with. */
	private $status;

	/** @var int|null Seconds the phone is told to wait, for a 429. */
	private $retry;

	/**
	 * @param string   $reason  What refused it.
	 * @param int      $status  HTTP status to answer with.
	 * @param string   $message What the provisioning log records.
	 * @param int|null $retry   Seconds until a retry could succeed, or null.
	 */
	public function __construct($reason, $status, $message, $retry = null)
	{
		parent::__construct($message);

		$this->reason = (string) $reason;
		$this->status = (int) $status;
		$this->retry = $retry === null ? null : max(1, (int) $retry);
	}

	/**
	 * @return string What refused it.
	 */
	public function reason()
	{
		return $this->reason;
	}

	/**
	 * @return int HTTP status to answer with.
	 */
	public function status()
	{
		return $this->status;
	}

	/**
	 * @return int|null Seconds until a retry could succeed, or null.
	 */
	public function retry()
	{
		return $this->retry;
	}
}
