<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('continuity_log')) {
            Schema::create('continuity_log', function (Blueprint $table): void {
                $table->id();
                // Null for a change that concerns the whole instance (a retention setting). No foreign key: like the
                // other site tables, the rows leave with the site in the bounded deletion of MaintainAnalytics.
                $table->unsignedBigInteger('website_id')->nullable();
                $table->dateTime('occurred_at', 3);
                $table->string('kind', 32);
                $table->string('severity', 8);
                $table->string('scope', 16);
                // First report day the change applies to, when it applies to a day at all.
                $table->date('data_from')->nullable();
                // Old and new values, bounded to 1 KiB by the writer.
                $table->json('payload');
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->index(['website_id', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('continuity_log');
    }
};
