<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\Continuity\ContinuityLog;
use App\Analytics\InstanceHealth;
use App\Analytics\Maintenance\AggregationLock;
use App\Analytics\Maintenance\RetentionPurge;
use App\Modules\Events\DomainEvents;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The operator's tool to purge earlier than the configured retention when the database is too big. It uses the
 * rules of the hourly purges ({@see RetentionPurge}), so it never deletes an event that has not been consolidated
 * and never empties the statistics while one is waiting. It is a simulation unless told otherwise.
 */
class PurgeData extends Command
{
    /** Shortest age accepted: a typo such as "1h" or "0d" must not empty a table. */
    public const MINIMUM_DAYS = 1;

    private const MAXIMUM_DAYS = 36500;

    /** Batches per run and per table: a long backlog is worked off over several runs. */
    private const MAX_BATCHES = 50;

    /** Days of a unit; a month is 30 days and a year 365. */
    private const UNITS = ['d' => 1, 'w' => 7, 'm' => 30, 'y' => 365];

    /** What an operator gives up with each target, for the summary. */
    private const LOSSES = [
        'events' => 'raw events: journey and flow reports over that period, the count of a goal defined afterwards, and the rebuild of segments on the retained history. Statistics already consolidated are kept.',
        'stats' => 'daily and hourly statistics, dimension tables and goal counters of those days: reports, comparisons and exports over that period.',
        'segments' => 'cross-filter counters of those days: filtered reports and comparisons over that period.',
        'sessions' => 'the session registries: a late event older than the cutoff can no longer correct session counts, and goals and segments cannot be recomputed for those sessions.',
        'share-links' => 'nothing visible: these links already answer with the page of an unknown link.',
    ];

    /** The reports a purge makes incomparable, for the continuity log. */
    private const SCOPES = ['events' => 'all', 'stats' => 'all', 'segments' => 'segments', 'sessions' => 'sessions'];

    protected $signature = 'perysee:purge
        {target : What to purge: events, stats, segments, sessions or share-links}
        {--older-than= : Age beyond which data is deleted: 90d, 12w, 6m or 2y (a month is 30 days, a year 365); at least 1d}
        {--dry-run : Only count what would be deleted (this is already the default)}
        {--force : Delete for real, after a confirmation}
        {--yes : Skip the confirmation (for scripts); needs --force}
        {--limit=1000 : Rows deleted per batch, 1 to 10000}';

    protected $description = 'Delete data older than a given age before the configured retention does (simulation by default)';

    public function handle(InstanceHealth $health, RetentionPurge $purge, ContinuityLog $continuity): int
    {
        $target = (string) $this->argument('target');
        if (! array_key_exists($target, RetentionPurge::TARGETS)) {
            $this->error('Unknown target "'.mb_substr($target, 0, 40).'". Use one of: '.implode(', ', array_keys(RetentionPurge::TARGETS)).'.');

            return self::FAILURE;
        }
        $days = $this->days((string) $this->option('older-than'));
        if ($days === null) {
            $this->error('Give an age of at least '.self::MINIMUM_DAYS.' day with --older-than, for example 90d, 12w, 6m or 2y.');

            return self::FAILURE;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if ($limit === false) {
            $this->error('Use a limit between 1 and 10000.');

            return self::FAILURE;
        }
        $execute = (bool) $this->option('force') && ! $this->option('dry-run');
        if ($this->option('yes') && ! $this->option('force')) {
            $this->error('--yes only confirms a deletion: add --force, or drop it for the simulation.');

            return self::FAILURE;
        }
        $cutoff = now('UTC')->subDays($days);
        $retention = $this->retention($target, $health);
        $counts = $purge->count($target, $cutoff);
        $blocked = $purge->blocked($target, $cutoff);
        $this->summary($target, $days, $cutoff, $counts, $blocked, $retention, $execute);
        if (! $execute) {
            $this->line('Simulation: nothing was deleted. Add --force to delete (a confirmation is asked unless --yes is given).');

            return self::SUCCESS;
        }
        if ($blocked) {
            $this->error('Nothing deleted: wait for the waiting events to be consolidated, then run the command again.');

            return self::FAILURE;
        }
        if (array_sum($counts) === 0) {
            $this->info('Nothing to delete.');

            return self::SUCCESS;
        }
        if (! $this->option('yes') && ! $this->input->isInteractive()) {
            $this->error('Confirmation needs a terminal: nothing was deleted. Add --yes to confirm in a script.');

            return self::FAILURE;
        }
        if (! $this->option('yes') && ! $this->confirm('Delete these rows permanently?', false)) {
            $this->line('Cancelled: nothing was deleted.');

            return self::FAILURE;
        }

        return $this->delete($target, $days, $cutoff, $limit, $retention, $purge, $continuity);
    }

    private function delete(string $target, int $days, Carbon $cutoff, int $limit, ?int $retention, RetentionPurge $purge, ContinuityLog $continuity): int
    {
        $lock = new AggregationLock;
        if (! $lock->acquire()) {
            $this->error('Aggregation is active; nothing was deleted. Run the command again in a few minutes.');

            return self::FAILURE;
        }
        try {
            $deleted = $purge->purge($target, $cutoff, $limit, self::MAX_BATCHES);
            if ($deleted === []) {
                $this->error('Nothing deleted: events older than the cutoff are still waiting to be consolidated.');

                return self::FAILURE;
            }
            $total = array_sum($deleted);
            foreach ($deleted as $table => $rows) {
                $this->line(sprintf('  %-24s %s deleted', $table, number_format($rows)));
            }
            if ($retention !== null && $days < $retention && $total > 0) {
                // The history is shorter than the retention says: figures before and after are no longer comparable.
                try {
                    $event = $continuity->record('retention_purge', null, ContinuityLog::BREAKS, self::SCOPES[$target], ['target' => $target, 'older_than_days' => $days, 'retention_days' => $retention, 'rows' => $total], $cutoff->toDateString());
                    if ($event) {
                        app(DomainEvents::class)->publish($event);
                    }
                } catch (\Throwable) {
                    $this->warn('The rows are deleted but the continuity log entry could not be written.');
                }
            }
            $this->info('Deleted '.number_format($total).' rows.');
            if ($purge->pending($target, $cutoff)) {
                $this->warn('More rows are older than the cutoff: run the command again to continue.');
            }

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /** @param  array<string, int>  $counts */
    private function summary(string $target, int $days, Carbon $cutoff, array $counts, bool $blocked, ?int $retention, bool $execute): void
    {
        $this->line(($execute ? 'Purge' : 'Simulation').' of "'.$target.'": data older than '.$days.' day(s), before '.$cutoff->toDateString().' (UTC).');
        foreach ($counts as $table => $rows) {
            $this->line(sprintf('  %-24s %s row(s)', $table, number_format($rows)));
        }
        $this->line('  Total: '.number_format(array_sum($counts)).' row(s).');
        if ($target === 'events') {
            $waiting = DB::table('events')->whereNull('processed_at')->where('received_at', '<', $cutoff)->count();
            if ($waiting > 0) {
                $this->warn('  '.number_format($waiting).' event(s) older than the cutoff are not consolidated yet: they are never deleted.');
            }
        }
        if ($blocked) {
            $this->warn('  An event waiting to be consolidated is older than the cutoff: this target is kept until it is, whatever the figures above.');
        }
        $this->line('  Lost for good: '.self::LOSSES[$target]);
        if ($retention !== null && $days < $retention) {
            $this->warn("  This is shorter than the retention in force ({$retention} days): a break will be recorded in the continuity log.");
        }
        $this->warn('  Take a backup of the database first: a purge cannot be undone.');
    }

    /** The age in days, or null when the text is not an age or is below the minimum. */
    private function days(string $age): ?int
    {
        if (preg_match('/^(\d{1,5})([dwmy])$/i', trim($age), $match) !== 1) {
            return null;
        }
        $days = (int) $match[1] * self::UNITS[strtolower($match[2])];

        return $days >= self::MINIMUM_DAYS && $days <= self::MAXIMUM_DAYS ? $days : null;
    }

    /** The retention in force for the data of a target, null where there is none (share links). */
    private function retention(string $target, InstanceHealth $health): ?int
    {
        return match ($target) {
            'events' => $health->effectiveRetentionDays('raw_retention_days'),
            'share-links' => null,
            default => $health->effectiveRetentionDays('stats_retention_days'),
        };
    }
}
