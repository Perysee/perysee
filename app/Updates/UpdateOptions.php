<?php

declare(strict_types=1);

namespace App\Updates;

/**
 * What the person starting an update chose.
 */
final readonly class UpdateOptions
{
    /**
     * @param  ?string  $version  a specific release tag; the latest stable release when null
     * @param  ?string  $archive  a release archive already downloaded, for a server that cannot reach GitHub
     * @param  ?string  $sha256  the published checksum of that archive
     * @param  string  $dump  "auto", "native", "php" or "external"
     * @param  ?string  $dumpFile  the dump made by hand when $dump is "external"
     */
    public function __construct(
        public ?string $version = null,
        public ?string $archive = null,
        public ?string $sha256 = null,
        public string $dump = 'auto',
        public ?string $dumpFile = null,
    ) {}
}
