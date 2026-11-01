<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_settings', function (Blueprint $table): void {
            $table->string('module_id', 40);
            $table->string('key', 100);
            $table->text('value');
            $table->timestamps();
            $table->primary(['module_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_settings');
    }
};
