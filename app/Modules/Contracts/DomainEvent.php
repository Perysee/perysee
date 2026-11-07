<?php

declare(strict_types=1);

namespace App\Modules\Contracts;

use App\Modules\ModuleApi;

/**
 * A moment of the Core that modules can react to, such as a goal being converted or a module being put in quarantine.
 *
 * An event never carries personal data: payloads hold identifiers, counters, enumerations and ISO 8601 UTC dates only.
 * A field is never removed or retyped inside a schema version; that needs a new schema (and a note in the release).
 *
 * @api Part of module API 1, available from the Perysee release named by {@see ModuleApi::DOMAIN_EVENTS_SINCE}.
 */
interface DomainEvent
{
    /** The stable public name, such as "goal.converted". */
    public function name(): string;

    /** The version of the payload layout. */
    public function schema(): int;

    /**
     * @return array<string, scalar|list<scalar>|null>
     */
    public function payload(): array;

    /** The site the event concerns, or null for an event about the instance. */
    public function siteId(): ?int;
}
