<?php

use App\Analytics\Aggregation\Aggregator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require dirname(__DIR__).'/bootstrap-mariadb.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$stage = $argv[1] ?? '';
$pause = static function (): void {
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    while (true) {
        usleep(100000);
    }
};
DB::listen(static function ($event) use ($stage, $pause): void {
    $sql = strtolower($event->sql);
    $matches = match ($stage) {
        'selected' => str_contains($sql, 'select') && str_contains($sql, 'events') && str_contains($sql, 'for update'),
        'sessions' => str_contains($sql, 'insert') && str_contains($sql, 'measured_sessions'),
        'counters' => str_contains($sql, 'insert') && str_contains($sql, 'stats_daily'),
        'hourly' => str_contains($sql, 'insert') && str_contains($sql, 'stats_hourly'),
        'dimensions' => str_contains($sql, 'insert') && str_contains($sql, 'stats_dimension_daily'),
        'marked' => str_contains($sql, 'update') && str_contains($sql, 'events') && str_contains($sql, 'processed_at'),
        default => false,
    };
    if ($matches) {
        $pause();
    }
});
if ($stage === 'committed') {
    Event::listen(TransactionCommitted::class, $pause);
}
app(Aggregator::class)->run();
