<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nullable columns at the end of the table: old events keep their shape and MariaDB adds them instantly.
        if (Schema::hasTable('events') && ! Schema::hasColumn('events', 'event_value')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->unsignedBigInteger('event_value')->nullable();
            });
        }
        if (Schema::hasTable('events') && ! Schema::hasColumn('events', 'event_props')) {
            Schema::table('events', function (Blueprint $table): void {
                $table->text('event_props')->nullable();
            });
        }
        // Optional order identifiers, kept apart from `events` so that only the events that carry one pay for the
        // unique key. The hash is keyed with the application key; the identifier itself is never stored.
        if (! Schema::hasTable('event_order_ids')) {
            Schema::create('event_order_ids', function (Blueprint $table): void {
                $table->unsignedBigInteger('website_id');
                $table->char('order_hash', 32);
                $table->dateTime('created_at');
                $table->primary(['website_id', 'order_hash'], 'event_order_ids_pk');
                $table->index('created_at', 'event_order_ids_created');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_order_ids');
        foreach (['event_props', 'event_value'] as $column) {
            if (Schema::hasTable('events') && Schema::hasColumn('events', $column)) {
                Schema::table('events', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
