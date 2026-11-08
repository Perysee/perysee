<?php

declare(strict_types=1);

namespace App\Analytics\Audit;

/**
 * One control of the configuration audit (ADR 009). It reads settings and counts through the context and returns a
 * finding; it never writes, never reads events and never calls out of the server. An exception thrown by `run()` is
 * caught by {@see ConfigurationAudit}, which reports the check as unavailable.
 *
 * The identifier is `category.name`; the category groups the checks on the page.
 */
interface Check
{
    public function id(): string;

    public function run(AuditContext $context): Finding;
}
