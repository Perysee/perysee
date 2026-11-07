<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stats_event_property_daily')) {
            Schema::create('stats_event_property_daily', function (Blueprint $table): void {
                $table->unsignedBigInteger('website_id');
                $table->date('stat_date');
                $table->string('event_name', 64);
                $table->string('prop_key', 32);
                $table->char('value_hash', 64);
                $table->string('prop_value', 64);
                $table->bigInteger('metric_count')->default(0);
                $table->bigInteger('value_sum')->default(0);
                $table->primary(['website_id', 'stat_date', 'event_name', 'prop_key', 'value_hash'], 'stats_event_property_daily_pk');
                $table->index(['website_id', 'event_name', 'prop_key', 'stat_date'], 'stats_event_property_daily_report');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stats_event_property_daily');
    }
};
