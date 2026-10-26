<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table): void {
            if (! Schema::hasColumn('websites', 'first_collected_at')) {
                $table->dateTime('first_collected_at', 3)->nullable();
            }
            if (! Schema::hasColumn('websites', 'deleting_at')) {
                $table->dateTime('deleting_at', 3)->nullable()->index();
            }
            if (! Schema::hasColumn('websites', 'excluded_paths')) {
                $table->json('excluded_paths')->nullable();
            }
        });
        if (! Schema::hasIndex('websites', 'websites_deleting_at_index')) {
            Schema::table('websites', fn (Blueprint $table) => $table->index('deleting_at'));
        }
        Schema::table('events', function (Blueprint $table): void {
            if (! Schema::hasColumn('events', 'tracking_mode')) {
                $table->string('tracking_mode', 16)->nullable();
            }
            if (! Schema::hasColumn('events', 'event_name')) {
                $table->string('event_name', 64)->nullable();
            }
            if (! Schema::hasColumn('events', 'referrer_host')) {
                $table->string('referrer_host', 253)->nullable();
            }
            if (! Schema::hasColumn('events', 'utm_source')) {
                $table->string('utm_source', 100)->nullable();
            }
            if (! Schema::hasColumn('events', 'utm_medium')) {
                $table->string('utm_medium', 100)->nullable();
            }
            if (! Schema::hasColumn('events', 'utm_campaign')) {
                $table->string('utm_campaign', 100)->nullable();
            }
            if (! Schema::hasColumn('events', 'device_type')) {
                $table->string('device_type', 12)->nullable();
            }
            if (! Schema::hasColumn('events', 'dimensions_available')) {
                $table->boolean('dimensions_available')->default(false);
            }
        });
        foreach (['stats_daily' => 'stat_date', 'stats_hourly' => 'bucket_start'] as $name => $bucket) {
            if (Schema::hasTable($name)) {
                continue;
            }
            Schema::create($name, function (Blueprint $table) use ($bucket): void {
                $table->unsignedBigInteger('website_id');
                if ($bucket === 'stat_date') {
                    $table->date($bucket)->index();
                } else {
                    $table->dateTime($bucket);
                    $table->date('stat_date')->index();
                }
                foreach (['pageviews', 'custom_events', 'sessions', 'session_pageviews', 'unknown_pageviews', 'dimension_pageviews'] as $metric) {
                    $table->unsignedBigInteger($metric)->default(0);
                }
                $table->primary(['website_id', $bucket]);
            });
        }
        if (! Schema::hasTable('stats_dimension_daily')) {
            Schema::create('stats_dimension_daily', function (Blueprint $table): void {
                $table->unsignedBigInteger('website_id');
                $table->date('stat_date')->index();
                $table->string('dimension_type', 16);
                $table->string('dimension_hash', 64);
                $table->string('dimension_value', 512);
                $table->unsignedBigInteger('metric_count')->default(0);
                $table->primary(['website_id', 'stat_date', 'dimension_type', 'dimension_hash'], 'stats_dimension_key');
            });
        }
        if (! Schema::hasTable('measured_sessions')) {
            Schema::create('measured_sessions', function (Blueprint $table): void {
                $table->unsignedBigInteger('website_id');
                $table->string('session_key', 64);
                $table->dateTime('first_seen_at', 3);
                $table->dateTime('last_seen_at', 3)->index();
                $table->primary(['website_id', 'session_key']);
            });
        }
        if (! Schema::hasTable('analytics_state')) {
            Schema::create('analytics_state', function (Blueprint $table): void {
                $table->string('name', 80)->primary();
                $table->text('value');
                $table->dateTime('updated_at', 3);
            });
        }
        DB::table('websites')->orderBy('id')->chunkById(100, function ($sites): void {
            foreach ($sites as $site) {
                $first = DB::table('events')->where('website_id', $site->id)->min('received_at');
                if ($first) {
                    DB::table('websites')->where('id', $site->id)->whereNull('first_collected_at')->update(['first_collected_at' => $first]);
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['analytics_state', 'measured_sessions', 'stats_dimension_daily', 'stats_hourly', 'stats_daily'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['tracking_mode', 'event_name', 'referrer_host', 'utm_source', 'utm_medium', 'utm_campaign', 'device_type', 'dimensions_available']));
        Schema::table('websites', fn (Blueprint $table) => $table->dropIndex(['deleting_at']));
        Schema::table('websites', fn (Blueprint $table) => $table->dropColumn(['first_collected_at', 'deleting_at', 'excluded_paths']));
    }
};
