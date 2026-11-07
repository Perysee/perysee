<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Collector rejections and installation tests, aggregated per site, hour and cause. There is one row per
     * combination, never one per request: no address, user agent, path or payload is kept.
     */
    public function up(): void
    {
        if (! Schema::hasTable('collection_diagnostics')) {
            Schema::create('collection_diagnostics', function (Blueprint $table): void {
                // 0 stands for the instance: a request that could not be tied to a site.
                $table->unsignedBigInteger('website_id');
                $table->dateTime('bucket_start');
                $table->string('cause', 24);
                $table->unsignedInteger('hits')->default(0);
                $table->string('sample_host', 253)->nullable();
                $table->dateTime('first_at');
                $table->dateTime('last_at');
                $table->primary(['website_id', 'bucket_start', 'cause'], 'pk_collection_diagnostics');
                $table->index('bucket_start', 'idx_diagnostics_bucket');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_diagnostics');
    }
};
