<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['stats_segment_daily', 'stats_segment_hourly'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->unsignedBigInteger('website_id');
                $table->date('stat_date');
                $table->dateTime('bucket_start');
                $table->char('segment_hash', 64);
                $table->text('dimensions');
                foreach (['path' => 512, 'source' => 253, 'campaign' => 512, 'device' => 32, 'device_brand' => 32, 'browser' => 32, 'event' => 64, 'country' => 7, 'provider' => 32] as $dimension => $size) {
                    $table->string($dimension, $size)->nullable();
                }
                foreach (['pageviews', 'custom_events', 'sessions'] as $metric) {
                    $table->unsignedBigInteger($metric)->default(0);
                }
                $table->primary(['website_id', 'bucket_start', 'segment_hash'], $name.'_pk');
                $table->index(['website_id', 'stat_date'], $name.'_date');
            });
        }
        Schema::create('segment_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('event_id')->primary();
            $table->unsignedBigInteger('website_id')->index();
            $table->dateTime('received_at')->index();
        });
        Schema::create('segment_sessions', function (Blueprint $table): void {
            $table->unsignedBigInteger('website_id');
            $table->string('session_key', 64);
            $table->unsignedBigInteger('first_event_id');
            $table->dateTime('first_seen_at', 3);
            $table->text('event_data');
            $table->primary(['website_id', 'session_key']);
        });
    }

    public function down(): void
    {
        foreach (['segment_sessions', 'segment_events', 'stats_segment_hourly', 'stats_segment_daily'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
