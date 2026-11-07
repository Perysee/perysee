<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thousandths of the site currency, summed like the hits they belong to; signed because a late event can move a hit.
        foreach (['value_sum', 'valued_hits'] as $column) {
            if (Schema::hasTable('stats_goal_daily') && ! Schema::hasColumn('stats_goal_daily', $column)) {
                Schema::table('stats_goal_daily', fn (Blueprint $table) => $table->bigInteger($column)->default(0));
            }
        }
    }

    public function down(): void
    {
        foreach (['valued_hits', 'value_sum'] as $column) {
            if (Schema::hasTable('stats_goal_daily') && Schema::hasColumn('stats_goal_daily', $column)) {
                Schema::table('stats_goal_daily', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
