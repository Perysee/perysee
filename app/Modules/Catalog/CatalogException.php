<?php

declare(strict_types=1);

namespace App\Modules\Catalog;

use RuntimeException;

/**
 * A catalog entry or download that cannot be trusted or used. The message is written for an administrator.
 */
final class CatalogException extends RuntimeException {}
