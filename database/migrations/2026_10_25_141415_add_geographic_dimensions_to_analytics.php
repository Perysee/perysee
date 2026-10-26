<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->string('source_value', 253)->nullable();
            $table->string('referrer_status', 12)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->boolean('geo_available')->default(false);
        });
        foreach (['stats_daily', 'stats_hourly'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->unsignedBigInteger('country_pageviews')->default(0));
        }
        DB::table('analytics_state')->insertOrIgnore(['name' => 'attribution_v2_since', 'value' => json_encode(now('UTC')->toIso8601String()), 'updated_at' => now('UTC')]);
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn(['source_value', 'referrer_status', 'country_code', 'geo_available']));
        foreach (['stats_daily', 'stats_hourly'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('country_pageviews'));
        }
    }
};
