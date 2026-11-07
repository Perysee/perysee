<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('access_log')) {
            Schema::create('access_log', function (Blueprint $table): void {
                $table->id();
                // Entries go with the account (cascade); failures for an unknown address have no user.
                $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
                $table->string('event', 40);
                // Only ever an address that belongs to an existing account.
                $table->string('email_tried')->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('device', 120)->nullable();
                $table->json('context')->nullable();
                $table->timestamp('created_at')->nullable()->index();
                $table->index(['user_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('access_log');
    }
};
