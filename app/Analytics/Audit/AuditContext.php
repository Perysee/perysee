<?php

declare(strict_types=1);

namespace App\Analytics\Audit;

use App\Analytics\InstanceHealth;
use App\Analytics\StorageBudget;
use App\Support\InstanceSecurity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What the checks may read, and nothing else: no write, no event, no call out of the server. Each source is read at
 * most once per audit and shared by the checks that need it, so the cost is one query per table whatever the number
 * of checks: `analytics_state` (settings), `websites`, `users` and `continuity_log`. The scheduler check also uses
 * {@see InstanceHealth::attention()}, whose own cheap reads are made once.
 */
final class AuditContext
{
    /** Sites examined at most: the audit is a page, not a report, and reads a bounded number of rows. */
    public const MAX_SITES = 500;

    /** Entries of the continuity log read at most, and how far back the audit looks. */
    public const MAX_BREAKS = 50;

    public const BREAKS_WINDOW_DAYS = 90;

    private const STATE = ['raw_retention_days', 'stats_retention_days', 'update_check'];

    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        public readonly AuditProfile $profile,
        private readonly InstanceHealth $health,
        private readonly Request $request,
    ) {}

    public function request(): Request
    {
        return $this->request;
    }

    /** Retention settings as the administrator saved them, the configured default otherwise. */
    public function retentionDays(string $name): int
    {
        $saved = $this->state()[$name] ?? null;

        return $saved === null ? $this->health->defaultRetentionDays($name) : (int) $saved;
    }

    /** The stored result of the last update check, null when none was ever made. */
    public function updateCheck(): ?array
    {
        $check = $this->state()['update_check'] ?? null;

        return is_array($check) ? $check : null;
    }

    /**
     * The sites that collect (demo sites and sites being deleted left out), at most {@see self::MAX_SITES}.
     *
     * @return array{sites: list<array{name: string, tracking_mode: string, consent_required: bool, allowed_domains: list<string>}>, truncated: bool}
     */
    public function sites(): array
    {
        return $this->memo['sites'] ??= (function (): array {
            $rows = DB::table('websites')->where('is_demo', false)->whereNull('deleting_at')->orderBy('name')->orderBy('id')
                ->limit(self::MAX_SITES + 1)->get(['name', 'tracking_mode', 'consent_required', 'allowed_domains']);
            $sites = $rows->take(self::MAX_SITES)->map(function (object $row): array {
                $domains = json_decode((string) $row->allowed_domains, true);

                return ['name' => (string) $row->name, 'tracking_mode' => (string) $row->tracking_mode, 'consent_required' => (bool) $row->consent_required, 'allowed_domains' => is_array($domains) ? array_values(array_filter($domains, 'is_string')) : []];
            })->all();

            return ['sites' => $sites, 'truncated' => $rows->count() > self::MAX_SITES];
        })();
    }

    /**
     * Administrators and those of them without a confirmed second factor, in one query.
     *
     * @return array{total: int, without_two_factor: int}
     */
    public function administrators(): array
    {
        return $this->memo['administrators'] ??= (function (): array {
            $row = DB::table('users')->where('users.is_admin', true)
                ->leftJoin('user_two_factor', fn ($join) => $join->on('user_two_factor.user_id', '=', 'users.id')->whereNotNull('user_two_factor.confirmed_at'))
                ->selectRaw('count(*) as total, coalesce(sum(case when user_two_factor.user_id is null then 1 else 0 end), 0) as without_two_factor')->first();

            return ['total' => (int) $row->total, 'without_two_factor' => (int) $row->without_two_factor];
        })();
    }

    /** The "two-factor required" switch of the instance (cached by its owner for a few minutes). */
    public function twoFactorRequired(): bool
    {
        return $this->memo['two_factor_required'] ??= app(InstanceSecurity::class)->twoFactorRequired();
    }

    /**
     * The warnings of the Instance health page and of the home page.
     *
     * @return list<array{code: string, message: string}>
     */
    public function attention(): array
    {
        return $this->memo['attention'] ??= $this->health->attention();
    }

    /** @return array<string, mixed> The storage budget settings, see {@see StorageBudget::settings()}. */
    public function storageBudget(): array
    {
        return $this->memo['storage_budget'] ??= app(StorageBudget::class)->settings();
    }

    /**
     * The newest `breaks` entries of the last {@see self::BREAKS_WINDOW_DAYS} days, newest first, without their payload.
     *
     * @return list<array{website_id: ?int, kind: string, scope: string, occurred_at: string}>
     */
    public function recentBreaks(): array
    {
        return $this->memo['breaks'] ??= DB::table('continuity_log')->where('severity', 'breaks')
            ->where('occurred_at', '>=', now('UTC')->subDays(self::BREAKS_WINDOW_DAYS)->format('Y-m-d H:i:s.v'))
            ->orderByDesc('occurred_at')->orderByDesc('id')->limit(self::MAX_BREAKS)
            ->get(['website_id', 'kind', 'scope', 'occurred_at'])
            ->map(fn (object $row): array => ['website_id' => $row->website_id === null ? null : (int) $row->website_id, 'kind' => (string) $row->kind, 'scope' => (string) $row->scope, 'occurred_at' => (string) $row->occurred_at])->all();
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        return $this->memo['state'] ??= $this->health->many(self::STATE);
    }
}
