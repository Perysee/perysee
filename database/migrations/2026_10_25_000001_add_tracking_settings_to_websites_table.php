<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['tracking_mode', 'consent_required', 'is_demo'] as $column) {
            if (! Schema::hasColumn('websites', $column)) {
                Schema::table('websites', function (Blueprint $table) use ($column): void {
                    match ($column) {
                        'tracking_mode' => $table->string('tracking_mode', 16)->default('pageviews'),
                        'consent_required' => $table->boolean('consent_required')->default(true),
                        'is_demo' => $table->boolean('is_demo')->default(false),
                    };
                });
            }
        }
    }

    public function down(): void
    {
        Schema::table('websites', fn (Blueprint $table) => $table->dropColumn(['tracking_mode', 'consent_required', 'is_demo']));
    }
};
