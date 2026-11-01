<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_scopes', function (Blueprint $table): void {
            $table->string('module_id', 40)->primary();
            $table->boolean('all_sites')->default(true);
            $table->timestamps();
        });
        Schema::create('module_sites', function (Blueprint $table): void {
            $table->string('module_id', 40);
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->primary(['module_id', 'website_id']);
            $table->index('website_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_sites');
        Schema::dropIfExists('module_scopes');
    }
};
