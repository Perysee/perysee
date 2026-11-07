<?php

declare(strict_types=1);

namespace App\Installation\Cpanel;

/** What a provisioning run made, so that a failed run can remove exactly that and nothing else. */
final class Provisioned
{
    /** @var list<string> */
    public array $warnings = [];

    /** The subdomain this run created, or null when the hostname already pointed at the application. */
    public ?string $subdomain = null;

    public bool $databaseCreated = false;

    public bool $userCreated = false;

    public function __construct(
        public readonly string $domain,
        public readonly string $databaseName,
        public readonly string $databaseUser,
        public readonly string $databasePassword,
        public readonly string $phpCli,
        public readonly string $databaseHost = 'localhost',
    ) {}
}
