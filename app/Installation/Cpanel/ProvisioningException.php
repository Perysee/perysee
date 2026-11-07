<?php

declare(strict_types=1);

namespace App\Installation\Cpanel;

use RuntimeException;

/** A cPanel provisioning step failed or was refused; the message is safe to show to the person installing. */
final class ProvisioningException extends RuntimeException {}
