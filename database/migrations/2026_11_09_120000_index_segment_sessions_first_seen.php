<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The hourly session purge takes the oldest rows of segment_sessions by first_seen_at (ADR 010). Without this
     * index every batch would read the whole table in primary key order. Additive and safe to run again.
     */
    public function up(): void
    {
        if (Schema::hasTable('segment_sessions') && ! Schema::hasIndex('segment_sessions', 'segment_sessions_first_seen_at_index')) {
            Schema::table('segment_sessions', fn (Blueprint $table) => $table->index('first_seen_at'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('segment_sessions') && Schema::hasIndex('segment_sessions', 'segment_sessions_first_seen_at_index')) {
            Schema::table('segment_sessions', fn (Blueprint $table) => $table->dropIndex('segment_sessions_first_seen_at_index'));
        }
    }
};
