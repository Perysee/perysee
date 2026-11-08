<?php

declare(strict_types=1);

namespace App\Analytics\Continuity;

use App\Analytics\Maintenance\RetentionPurge;
use App\Models\Website;
use App\Modules\Events\ContinuityBreakRecorded;
use App\Modules\Events\DomainEvents;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The single writer of the continuity log: the settings changes that make a site's figures before and after
 * incomparable (measurement mode, consent policy, accepted hosts, excluded paths, currency) and the retention
 * settings of the instance. It records that something changed and what the old and new values were, never why.
 *
 * Privacy: a payload holds settings (a mode, a flag, host names, path prefixes), never a visitor, a session or
 * an address, and never more than 1 KiB: long values are cut and lists keep their first items and a count.
 *
 * The log never runs on the collection path (ADR 004, 008). Callers write inside the transaction of the change
 * they describe, so that a rolled back or replayed change leaves no entry behind.
 */
final class ContinuityLog
{
    public const BREAKS = 'breaks';

    public const NOTE = 'note';

    public const SCOPES = ['all', 'sessions', 'pageviews', 'events', 'goals', 'segments'];

    public const KINDS = ['tracking_mode', 'consent', 'allowed_domains', 'excluded_paths', 'currency', 'timezone', 'retention_change', 'retention_purge', 'storage_tier', 'core_version', 'tracker_version', 'rebuild'];

    /** Days an entry is kept (the statistics retention default), and the rows kept per site or for the instance. */
    public const RETENTION_DAYS = 1095;

    public const MAX_PER_SITE = 500;

    private const MAX_PAYLOAD_BYTES = 1024;

    private const MAX_TEXT_LENGTH = 64;

    /** List items kept in a payload, from the most to the least generous until it fits. */
    private const ITEM_STEPS = [5, 3, 1, 0];

    /** The tracked fields of a site: the kind of the entry and the reports it makes incomparable. */
    private const TRACKED = [
        'tracking_mode' => ['tracking_mode', 'sessions'],
        'consent_required' => ['consent', 'all'],
        'allowed_domains' => ['allowed_domains', 'all'],
        'excluded_paths' => ['excluded_paths', 'all'],
        'currency' => ['currency', 'events'],
        'timezone' => ['timezone', 'all'],
    ];

    /**
     * @param  array<string, scalar|list<scalar>|null>  $payload  Old and new values; text is cut, lists are shortened with a count.
     * @param  string|null  $dataFrom  First report day (Y-m-d) the change applies to.
     * @return ContinuityBreakRecorded|null The event to publish once the caller's transaction is committed: only for a
     *                                      `breaks` entry, and only when a module listens (nothing is built for nobody).
     */
    public function record(string $kind, ?int $siteId, string $severity, string $scope, array $payload, ?string $dataFrom = null, ?int $actorId = null): ?ContinuityBreakRecorded
    {
        if (! in_array($kind, self::KINDS, true) || ! in_array($severity, [self::BREAKS, self::NOTE], true) || ! in_array($scope, self::SCOPES, true)) {
            throw new \InvalidArgumentException("Unknown continuity log entry \"{$kind}\" / \"{$severity}\" / \"{$scope}\".");
        }
        DB::table('continuity_log')->insert([
            'website_id' => $siteId,
            'occurred_at' => now('UTC')->format('Y-m-d H:i:s.v'),
            'kind' => $kind,
            'severity' => $severity,
            'scope' => $scope,
            'data_from' => $dataFrom,
            'payload' => $this->encode($payload),
            'actor_id' => $actorId,
        ]);

        return $severity === self::BREAKS && app(DomainEvents::class)->listening(ContinuityBreakRecorded::class) ? new ContinuityBreakRecorded($siteId, $kind, $scope, $dataFrom) : null;
    }

    /**
     * A note that is written once: the same kind, scope and first day for the same site (or the instance) leaves a
     * single entry, however often the system comes back to it. Callers hold a lock or a transaction of their own.
     *
     * @param  array<string, scalar|list<scalar>|null>  $payload
     * @return bool Whether an entry was written.
     */
    public function noteOnce(string $kind, ?int $siteId, string $scope, string $dataFrom, array $payload): bool
    {
        $exists = DB::table('continuity_log')->where('kind', $kind)->where('scope', $scope)->where('data_from', $dataFrom)
            ->when($siteId === null, fn ($query) => $query->whereNull('website_id'), fn ($query) => $query->where('website_id', $siteId))->exists();
        if ($exists) {
            return false;
        }
        $this->record($kind, $siteId, self::NOTE, $scope, $payload, $dataFrom);

        return true;
    }

    /**
     * What an automatic retention purge removed (rows deleted by table, as RetentionPurge::purge returns them):
     * one note per kind of data and cutoff day. Nothing deleted, nothing read, nothing written.
     *
     * @param  array<string, int>  $deleted
     */
    public function expired(array $deleted, Carbon $cutoff): void
    {
        $day = $cutoff->copy()->utc()->toDateString();
        foreach (RetentionPurge::EXPIRY_SCOPES as $target => $scope) {
            if (array_sum(array_intersect_key($deleted, array_flip(RetentionPurge::TARGETS[$target]))) > 0) {
                $this->noteOnce('retention_purge', null, $scope, $day, ['target' => $target]);
            }
        }
    }

    /**
     * Record one entry per tracked field that the update changes. Call it before the site is saved, with the
     * locked site as it still is, and inside the transaction of the update. A field absent from $after is untouched.
     *
     * @param  array<string, mixed>  $after  The validated attributes about to be saved.
     * @return list<ContinuityBreakRecorded> Events to publish once the transaction is committed.
     */
    public function diffWebsite(Website $before, array $after, ?int $actorId = null): array
    {
        $events = [];
        $dataFrom = now((string) ($after['timezone'] ?? $before->timezone))->toDateString();
        foreach (self::TRACKED as $field => [$kind, $scope]) {
            if (! array_key_exists($field, $after)) {
                continue;
            }
            $from = $this->normalise($field, $before->getAttribute($field));
            $to = $this->normalise($field, $after[$field]);
            if ($from === $to) {
                continue;
            }
            // Accepting one more host or changing a timezone nobody has measured in yet loses nothing.
            $breaks = match ($field) {
                'allowed_domains' => array_diff($from, $to) !== [],
                'timezone' => false,
                default => true,
            };
            $events[] = $this->record($kind, $before->id, $breaks ? self::BREAKS : self::NOTE, $scope, ['from' => $from, 'to' => $to], $dataFrom, $actorId);
        }

        return array_values(array_filter($events));
    }

    /**
     * The breaks that happened to the site, or to the whole instance, between two instants.
     *
     * @return list<array{kind: string, scope: string, occurred_at: string, data_from: string|null, payload: array<string, mixed>}>
     */
    public function breaksSince(Website $site, CarbonInterface $from, CarbonInterface $to): array
    {
        return DB::table('continuity_log')
            ->where(fn ($query) => $query->where('website_id', $site->id)->orWhereNull('website_id'))
            ->where('severity', self::BREAKS)
            ->whereBetween('occurred_at', [$from->copy()->utc()->format('Y-m-d H:i:s.v'), $to->copy()->utc()->format('Y-m-d H:i:s.v')])
            ->orderBy('occurred_at')->orderBy('id')
            ->get(['kind', 'scope', 'occurred_at', 'data_from', 'payload'])
            ->map(fn ($row) => ['kind' => $row->kind, 'scope' => $row->scope, 'occurred_at' => $row->occurred_at, 'data_from' => $row->data_from, 'payload' => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR)])
            ->all();
    }

    /**
     * The entries that apply to the site (or to the whole instance) on the local days $from to $to, oldest first,
     * in one read: what a report shows next to its period, never the payload. An entry applies from its
     * `data_from` day, or from the local day it was written on when it has none.
     *
     * @param  string  $from  Y-m-d, in the timezone of the site
     * @param  string  $to  Y-m-d, in the timezone of the site
     * @return list<array{at: string, kind: string, scope: string, severity: string}>
     */
    public function between(Website $site, string $from, string $to): array
    {
        $start = Carbon::createFromFormat('!Y-m-d', $from, $site->timezone)->utc();
        $end = Carbon::createFromFormat('!Y-m-d', $to, $site->timezone)->addDay()->utc();

        return DB::table('continuity_log')
            ->where(fn ($query) => $query->where('website_id', $site->id)->orWhereNull('website_id'))
            ->where(fn ($query) => $query->whereBetween('data_from', [$from, $to])
                ->orWhere(fn ($undated) => $undated->whereNull('data_from')->where('occurred_at', '>=', $start->format('Y-m-d H:i:s.v'))->where('occurred_at', '<', $end->format('Y-m-d H:i:s.v'))))
            ->orderBy('occurred_at')->orderBy('id')
            ->get(['kind', 'scope', 'severity', 'occurred_at', 'data_from'])
            ->map(fn ($row) => [
                'at' => $row->data_from ?? Carbon::parse($row->occurred_at, 'UTC')->setTimezone($site->timezone)->toDateString(),
                'kind' => $row->kind, 'scope' => $row->scope, 'severity' => $row->severity,
            ])
            ->all();
    }

    /** Lists are compared as sets, the way the collector reads them. */
    private function normalise(string $field, mixed $value): mixed
    {
        return match ($field) {
            'consent_required' => (bool) $value,
            'allowed_domains', 'excluded_paths' => $this->set($value),
            default => $value === null ? null : (string) $value,
        };
    }

    /** @return list<string> */
    private function set(mixed $value): array
    {
        $items = array_values(array_unique(array_map('strval', (array) $value)));
        sort($items);

        return $items;
    }

    /** The payload as JSON of at most 1 KiB, valid UTF-8 (MariaDB refuses anything else in a JSON column). */
    private function encode(array $payload): string
    {
        foreach (self::ITEM_STEPS as $items) {
            $json = json_encode($this->bound($payload, $items), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (strlen($json) <= self::MAX_PAYLOAD_BYTES) {
                return $json;
            }
        }

        return json_encode(['truncated' => true], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function bound(array $payload, int $items): array
    {
        $bounded = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $bounded[$key] = array_map($this->clean(...), array_slice(array_values($value), 0, $items));
                $bounded[$key.'_count'] = count($value);
            } else {
                $bounded[$key] = $this->clean($value);
            }
        }

        return $bounded;
    }

    private function clean(mixed $value): mixed
    {
        return is_string($value) ? mb_substr(mb_scrub($value, 'UTF-8'), 0, self::MAX_TEXT_LENGTH) : $value;
    }
}
