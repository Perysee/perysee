<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('websites')) {
            Schema::create('websites', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->string('name', 150);
                $table->string('domain', 253);
                $table->json('allowed_domains');
                $table->string('timezone', 64)->default('UTC');
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('site_members')) {
            Schema::create('site_members', function (Blueprint $table): void {
                $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->enum('role', ['owner', 'viewer']);
                $table->timestamps();
                $table->primary(['website_id', 'user_id']);
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_members');
        Schema::dropIfExists('websites');
    }
};
