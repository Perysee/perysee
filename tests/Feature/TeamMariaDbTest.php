<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TeamMariaDbTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Requires the dedicated MariaDB test database.');
        }
    }

    public function test_two_owners_demoting_each_other_at_once_leave_exactly_one_owner(): void
    {
        $site = Website::factory()->create();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $site->members()->attach([$first->id => ['role' => 'owner'], $second->id => ['role' => 'owner']]);
        $children = [];
        DB::beginTransaction();
        DB::table('websites')->where('id', $site->id)->lockForUpdate()->first();
        try {
            foreach ([[$first, $second], [$second, $first]] as $index => [$actor, $target]) {
                $child = new Process([PHP_BINARY, base_path('tests/Support/demote-owner-child.php'), (string) $site->id, (string) $actor->id, (string) $target->id, $this->installationDirectory.'/child'.$index], base_path());
                $child->start();
                $children[] = $child;
            }
            $deadline = microtime(true) + 10;
            foreach ($children as $child) {
                while (! str_contains($child->getOutput(), 'READY') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('READY', $child->getOutput(), $child->getErrorOutput());
            }
            usleep(200000);
            DB::commit();
            $statuses = [];
            foreach ($children as $child) {
                $child->wait();
                $statuses[] = json_decode(explode("READY\n", $child->getOutput(), 2)[1], true, flags: JSON_THROW_ON_ERROR)['status'];
            }
            sort($statuses);
            $this->assertSame([302, 422], $statuses);
            $this->assertSame(1, $site->members()->wherePivot('role', 'owner')->count());
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($children as $child) {
                $child->stop(0, 9);
            }
        }
    }
}
