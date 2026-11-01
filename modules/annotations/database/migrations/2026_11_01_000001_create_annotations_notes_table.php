<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('annotations_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('noted_on');
            $table->string('body', 280);
            $table->timestamps();
            $table->index(['website_id', 'noted_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('annotations_notes');
    }
};
