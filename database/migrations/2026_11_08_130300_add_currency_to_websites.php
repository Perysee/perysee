<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('websites') && ! Schema::hasColumn('websites', 'currency')) {
            Schema::table('websites', fn (Blueprint $table) => $table->char('currency', 3)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('websites') && Schema::hasColumn('websites', 'currency')) {
            Schema::table('websites', fn (Blueprint $table) => $table->dropColumn('currency'));
        }
    }
};
