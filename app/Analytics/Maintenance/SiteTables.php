<?php

namespace App\Analytics\Maintenance;

/**
 * The tables that hold a site's rows. A table added for a site belongs here, or the site is never fully deleted:
 * BatchedPurgeTest compares this list with the schema.
 */
final class SiteTables
{
    /**
     * Every table with a website_id column that the database does not clear with the site, children before parents,
     * with the columns that identify a row besides website_id.
     *
     * @var array<string, list<string>>
     */
    public const KEYS = [
        'events' => ['id'],
        'stats_daily' => ['stat_date'],
        'stats_hourly' => ['bucket_start'],
        'stats_dimension_daily' => ['stat_date', 'dimension_type', 'dimension_hash'],
        'measured_sessions' => ['session_key'],
        'site_measurement_periods' => ['id'],
        'stats_segment_daily' => ['bucket_start', 'segment_hash'],
        'stats_segment_hourly' => ['bucket_start', 'segment_hash'],
        'segment_events' => ['event_id'],
        'segment_sessions' => ['session_key'],
        'journey_report_sessions' => ['id'],
        'journey_reports' => ['id'],
        'funnel_versions' => ['id'],
        'funnels' => ['id'],
        'goal_sessions' => ['goal_id', 'version', 'session_key'],
        'stats_goal_daily' => ['goal_id', 'version', 'bucket_start', 'segment_hash'],
        'goal_versions' => ['id'],
        'goals' => ['id'],
        'stats_event_property_daily' => ['stat_date', 'event_name', 'prop_key', 'value_hash'],
        'event_order_ids' => ['order_hash'],
        'collection_diagnostics' => ['bucket_start', 'cause'],
        'continuity_log' => ['id'],
    ];

    /**
     * The tables the demo reset empties before it regenerates the traffic of a demo site.
     *
     * @var list<string>
     */
    public const DEMO = [
        'events', 'stats_daily', 'stats_hourly', 'stats_dimension_daily', 'measured_sessions', 'site_measurement_periods',
        'stats_segment_daily', 'stats_segment_hourly', 'segment_events', 'segment_sessions', 'journey_report_sessions', 'journey_reports',
    ];
}
