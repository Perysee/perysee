<?php

declare(strict_types=1);

namespace App\Modules;

use RuntimeException;

/** A module archive that cannot be installed. The message is written for the administrator who uploaded it. */
final class InvalidModuleBundle extends RuntimeException {}
