<?php

declare(strict_types=1);

namespace App\Modules\Events;

use App\Modules\Contracts\DomainEvent;

/**
 * A setting changed in a way that makes figures before and after incomparable (a `breaks` entry of the continuity
 * log, ADR 009): consent, measurement mode, excluded paths, a removed host, a retention cut, a purge ahead of the
 * retention, a storage tier raised. The payload names the kind of change and the reports it concerns, never the
 * old or new values. Published after the change is committed, by administration requests and commands, never by
 * the collector.
 *
 * Available from Perysee 0.5.0: a module that listens to it states that release in the "core" field of `module.json`.
 *
 * @api
 */
final readonly class ContinuityBreakRecorded implements DomainEvent
{
    public const NAME = 'continuity.break_recorded';

    public const SCHEMA = 1;

    /** The first Perysee release with this event; ModuleProvider::listen() refuses a module that does not require it. */
    public const SINCE = '0.5.0';

    /**
     * @param  int|null  $siteId  The site concerned, null for a change of the whole instance.
     * @param  string  $kind  One of ContinuityLog::KINDS (consent, tracking_mode, ...).
     * @param  string  $scope  The reports concerned: all, sessions, pageviews, events, goals or segments.
     * @param  string|null  $dataFrom  First report day (Y-m-d) the change applies to.
     */
    public function __construct(
        public ?int $siteId,
        public string $kind,
        public string $scope,
        public ?string $dataFrom,
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
        return $this->siteId;
    }

    public function payload(): array
    {
        return ['site_id' => $this->siteId, 'kind' => $this->kind, 'scope' => $this->scope, 'data_from' => $this->dataFrom];
    }
}
