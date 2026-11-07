<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_sessions')) {
            Schema::create('user_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                // sha256 of the session id (what the page compares) and the id itself encrypted (needed to destroy the session).
                $table->char('session_hash', 64)->unique();
                $table->text('session_id_enc');
                $table->string('device', 120);
                // Truncated like the access log: /24 for IPv4, /48 for IPv6.
                $table->string('ip', 45)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('last_seen_at')->nullable()->index();
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
