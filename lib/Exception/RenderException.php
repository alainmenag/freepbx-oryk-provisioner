<?php

namespace Oryk\Provisioner\Exception;

/**
 * Thrown when a template cannot be parsed or rendered. Results in a 500 at the
 * provisioning endpoint; the detail is logged internally and never returned.
 */
class RenderException extends ProvisionerException
{
}
