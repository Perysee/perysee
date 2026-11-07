<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ledger and deduplication: one row per goal version and measured session.
        if (! Schema::hasTable('goal_sessions')) {
            Schema::create('goal_sessions', function (Blueprint $table): void {
                $table->unsignedBigInteger('website_id');
                $table->unsignedBigInteger('goal_id');
                $table->unsignedInteger('version');
                $table->string('session_key', 64);
                $table->unsignedInteger('hits')->default(0);
                $table->dateTime('converted_at', 3)->nullable();
                $table->text('event_data')->nullable();
                $table->primary(['website_id', 'goal_id', 'version', 'session_key'], 'goal_sessions_pk');
            });
        }
        // Same shape as stats_segment_daily so the shared filters apply; the counters are signed because a late
        // event can move a conversion from one bucket to another.
        if (! Schema::hasTable('stats_goal_daily')) {
            Schema::create('stats_goal_daily', function (Blueprint $table): void {
                $table->unsignedBigInteger('website_id');
                $table->unsignedBigInteger('goal_id');
                $table->unsignedInteger('version');
                $table->date('stat_date');
                $table->dateTime('bucket_start');
                $table->char('segment_hash', 64);
                $table->text('dimensions');
                foreach (['path' => 512, 'source' => 253, 'campaign' => 512, 'device' => 32, 'device_brand' => 32, 'browser' => 32, 'event' => 64, 'country' => 7, 'provider' => 32] as $dimension => $size) {
                    $table->string($dimension, $size)->nullable();
                }
                $table->bigInteger('conversions')->default(0);
                $table->bigInteger('hits')->default(0);
                $table->primary(['website_id', 'goal_id', 'version', 'bucket_start', 'segment_hash'], 'stats_goal_daily_pk');
                $table->index(['website_id', 'goal_id', 'stat_date'], 'stats_goal_daily_date');
            });
        }
    }

    public function down(): void
    {
        foreach (['stats_goal_daily', 'goal_sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
