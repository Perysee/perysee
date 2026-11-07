<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserSession;
use App\Support\UserSessions;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\InteractsWithTwoFactor;
use Tests\TestCase;

class TwoFactorMariaDbTest extends TestCase
{
    use DatabaseMigrations, InteractsWithTwoFactor;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mariadb') {
            $this->markTestSkipped('Requires the dedicated MariaDB test database.');
        }
    }

    /**
     * Starts two sign-ins with the same second factor while this test holds the row lock, then lets them go together.
     *
     * @return list<string|null> What each of them was accepted as.
     */
    private function racing(User $user, string $input): array
    {
        $children = [];
        DB::beginTransaction();
        DB::table('user_two_factor')->where('user_id', $user->id)->lockForUpdate()->first();
        try {
            foreach ([0, 1] as $index) {
                $child = new Process([PHP_BINARY, base_path('tests/Support/two-factor-child.php'), (string) $user->id, $input], base_path());
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
            usleep(300000);
            DB::commit();
            $methods = [];
            foreach ($children as $child) {
                $child->wait();
                $methods[] = json_decode(explode("READY\n", $child->getOutput(), 2)[1], true, flags: JSON_THROW_ON_ERROR)['method'];
            }

            return $methods;
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($children as $child) {
                $child->stop(0, 9);
            }
        }
    }

    public function test_two_simultaneous_attempts_with_the_same_code_succeed_only_once(): void
    {
        $user = User::factory()->create();
        [$secret] = $this->enableTwoFactor($user);

        $methods = $this->racing($user, $this->totp($secret));

        $this->assertSame(1, count(array_filter($methods)), json_encode($methods));
    }

    public function test_two_simultaneous_attempts_with_the_same_recovery_code_succeed_only_once(): void
    {
        $user = User::factory()->create();
        [, $codes] = $this->enableTwoFactor($user);

        $methods = $this->racing($user, $codes[0]);

        $this->assertSame(['recovery_code'], array_values(array_filter($methods)));
        $this->assertSame(7, DB::table('user_recovery_codes')->where('user_id', $user->id)->whereNull('used_at')->count());
    }

    public function test_the_session_list_and_the_log_work_on_mariadb_with_long_identifiers(): void
    {
        $user = User::factory()->create();
        $id = str_repeat('s', 40);
        UserSession::create(['user_id' => $user->id, 'session_hash' => UserSessions::hash($id), 'session_id_enc' => Crypt::encryptString($id), 'device' => str_repeat('d', 120), 'ip' => '2001:db8:abcd::', 'created_at' => now(), 'last_seen_at' => now()]);
        $this->artisan('perysee:prune-access-log')->assertSuccessful();

        $this->assertSame(1, UserSession::count());
        $user->delete();
        $this->assertSame(0, UserSession::count());
        $this->assertSame(0, DB::table('user_two_factor')->count());
    }
}
