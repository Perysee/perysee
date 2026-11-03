<?php

declare(strict_types=1);

namespace App\Updates;

use RuntimeException;

/**
 * A failure the administrator can act on: the message is shown as is, so it never contains a secret.
 */
final class UpdateException extends RuntimeException {}
