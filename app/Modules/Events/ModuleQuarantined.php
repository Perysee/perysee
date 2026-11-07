<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;

/**
 * A module was put in quarantine (disabled, data kept), together with the modules that need it. The reason is left out
 * on purpose: it can quote an exception message. The quarantined modules do not receive their own event.
 *
 * @api
 */
final readonly class ModuleQuarantined implements DomainEvent
{
    public const NAME = 'module.quarantined';

    public const SCHEMA = 1;

    /**
     * @param  list<string>  $dependents  Ids of the modules disabled with it.
     */
    public function __construct(
        public string $moduleId,
        public string $stability,
        public array $dependents,
        public string $at,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function schema(): int
    {
        return self::SCHEMA;
    }

    public function siteId(): ?int
    {
        return null;
    }

    public function payload(): array
    {
        return ['module_id' => $this->moduleId, 'stability' => $this->stability, 'dependents' => array_values($this->dependents), 'at' => $this->at];
    }
}
