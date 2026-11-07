<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('goals')) {
            Schema::create('goals', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('website_id')->index();
                $table->string('name', 120);
                $table->unsignedInteger('current_version')->default(1);
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('goal_versions')) {
            Schema::create('goal_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('website_id')->index();
                $table->unsignedBigInteger('goal_id');
                $table->unsignedInteger('version');
                $table->string('type', 8);
                $table->string('value', 512);
                $table->unsignedSmallInteger('threshold')->default(1);
                // Events up to this id are counted by the backfill, later ones by the aggregator: the two never overlap.
                $table->unsignedBigInteger('snapshot_id')->default(0);
                $table->timestamp('created_at');
                $table->unique(['goal_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        foreach (['goal_versions', 'goals'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
