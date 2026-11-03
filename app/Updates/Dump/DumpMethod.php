<?php

declare(strict_types=1);

namespace App\Updates\Dump;

/**
 * One way of writing the whole database to a SQL file.
 */
interface DumpMethod
{
    /** Short identifier used by the command line option: "native" or "php". */
    public function name(): string;

    public function label(): string;

    public function available(): bool;

    /**
     * Writes a complete, restorable SQL dump to $path.
     */
    public function dump(string $path): void;
}
