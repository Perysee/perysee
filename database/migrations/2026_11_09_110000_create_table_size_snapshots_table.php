<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('table_size_snapshots')) {
            Schema::create('table_size_snapshots', function (Blueprint $table): void {
                // One row per table and day, the instance's own tables: not attached to a site.
                $table->date('taken_on');
                $table->string('table_name', 64);
                $table->unsignedBigInteger('data_bytes');
                $table->unsignedBigInteger('index_bytes');
                // The engine's estimate (information_schema), never a COUNT(*); null where there is none.
                $table->unsignedBigInteger('row_estimate')->nullable();
                $table->primary(['taken_on', 'table_name']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('table_size_snapshots');
    }
};
