<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_recovery_codes')) {
            Schema::create('user_recovery_codes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                // Keyed hash of the code; the code itself is shown once and never stored.
                $table->char('code_hash', 64);
                $table->timestamp('used_at')->nullable();
                $table->index(['user_id', 'code_hash']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_recovery_codes');
    }
};
