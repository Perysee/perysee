<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_two_factor')) {
            Schema::create('user_two_factor', function (Blueprint $table): void {
                $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
                // Encrypted with the application key; never stored in clear.
                $table->text('secret');
                // Null while the enrolment is not confirmed with a first valid code.
                $table->timestamp('confirmed_at')->nullable();
                // Last accepted time step: a step at or below it is refused (replay protection).
                $table->unsignedBigInteger('last_step')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_two_factor');
    }
};
