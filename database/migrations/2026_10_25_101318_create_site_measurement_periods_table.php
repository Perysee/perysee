<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_measurement_periods')) {
            Schema::create('site_measurement_periods', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('website_id');
                $table->dateTime('started_at', 3);
                $table->dateTime('ended_at', 3)->nullable();
                $table->string('mode', 16);
                $table->index(['website_id', 'started_at']);
            });
        }
        DB::table('websites')->orderBy('id')->chunkById(100, function ($sites): void {
            foreach ($sites as $site) {
                if (! DB::table('site_measurement_periods')->where('website_id', $site->id)->exists()) {
                    DB::table('site_measurement_periods')->insert(['website_id' => $site->id, 'started_at' => now('UTC'), 'mode' => $site->tracking_mode]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_measurement_periods');
    }
};
