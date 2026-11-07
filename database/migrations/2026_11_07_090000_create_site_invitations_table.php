<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_invitations')) {
            Schema::create('site_invitations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->string('email', 255);
                $table->enum('role', ['owner', 'viewer']);
                // Only the SHA-256 of the token is kept: the link itself cannot be recovered from the database.
                $table->char('token_hash', 64)->unique();
                $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('expires_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamps();
                $table->index(['website_id', 'email']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_invitations');
    }
};
