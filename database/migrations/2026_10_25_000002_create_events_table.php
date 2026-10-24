<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            Schema::create('events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('website_id');
                $table->dateTime('received_at', 3);
                $table->unsignedTinyInteger('event_type')->default(1);
                $table->string('path', 512);
                $table->char('session_key', 64)->nullable();
                $table->boolean('consent_given');
                $table->dateTime('processed_at', 3)->nullable();
                $table->index(['processed_at', 'id'], 'idx_pending');
                $table->index(['received_at', 'id'], 'idx_retention');
                $table->index(['website_id', 'received_at'], 'idx_site_recent');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
