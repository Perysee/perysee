<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('share_links')) {
            Schema::create('share_links', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->char('token_hash', 64)->unique();
                $table->string('token_hint', 6);
                $table->string('label', 120)->default('');
                $table->json('sections');
                $table->string('password_hash')->nullable();
                $table->unsignedInteger('secret_version')->default(1);
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->unsignedInteger('use_count')->default(0);
                $table->timestamps();
                $table->index(['website_id', 'revoked_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('share_links');
    }
};
