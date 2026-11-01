<?php

declare(strict_types=1);

namespace App\Modules;

final readonly class Module
{
    public function __construct(
        public Manifest $manifest,
        public string $path,
        public bool $enabled,
        public bool $installed = false,
    ) {}
}
