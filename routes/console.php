<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('perysee:aggregate --batch=500 --budget-seconds=15')->everyFiveMinutes();
Schedule::command('perysee:maintenance --limit=1000')->everyFiveMinutes();

Schedule::command('perysee:rebuild-segments --batch=500')->everyFiveMinutes();

Schedule::command('perysee:rebuild-goals --batch=500')->everyMinute()->withoutOverlapping();

Schedule::command('perysee:journey-reports --batch=500 --budget=10')->everyMinute()->withoutOverlapping();

Schedule::command('perysee:update --requested --yes')->everyMinute()->withoutOverlapping(120);
Schedule::command('perysee:update:check --quiet-unless-new')->dailyAt('04:17');
Schedule::command('perysee:modules catalog --refresh-only')->dailyAt('04:43');

// two-factor: retention of the access log (90 days) and of the open-sessions list
Schedule::command('perysee:prune-access-log --limit=1000')->hourly()->withoutOverlapping();

// read-api: expired or revoked API tokens, kept out of the idle maintenance pass on purpose
Schedule::command('perysee:prune-api-tokens')->hourly()->withoutOverlapping();

// Installation check: collector diagnostics expire after seven days.
Schedule::command('perysee:prune-diagnostics')->hourly()->withoutOverlapping();

// Storage: one size snapshot per table and day (400 days kept); once recorded, an hourly pass costs one read.
Schedule::command('perysee:storage-snapshot')->hourly()->withoutOverlapping();

// Event values and properties: retention of the order identifiers and of the property statistics (ADR 008).
Schedule::command('perysee:prune-event-values --limit=1000')->hourly()->withoutOverlapping();

// Continuity log: 1095 days and 500 entries per site (ADR 009), kept out of the idle maintenance pass on purpose.
Schedule::command('perysee:prune-continuity-log --limit=1000')->hourly()->withoutOverlapping();

// Retention beyond the statistics (ADR 010): session registries, ended share links and expired file-cache entries,
// each in its own hourly command so that the idle maintenance pass costs no query more.
Schedule::command('perysee:prune-sessions --limit=1000')->hourly()->withoutOverlapping();
Schedule::command('perysee:prune-share-links --limit=1000')->hourly()->withoutOverlapping();
Schedule::command('perysee:prune-cache --limit=1000')->hourly()->withoutOverlapping();

// Storage budget (ADR 011): one read when no budget is set; otherwise one measurement and, at most, a tier change.
Schedule::command('perysee:storage-budget')->hourly()->withoutOverlapping();
