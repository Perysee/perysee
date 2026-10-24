<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LocalDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('Demo data is restricted to local development.');
        }
        $admin = User::where('is_admin', true)->orderBy('id')->first();
        if (! $admin) {
            throw new RuntimeException('Install Perysee and create your administrator before seeding demo sites.');
        }
        DB::transaction(function () use ($admin): void {
            foreach (['pageviews' => 'c5d09ee1-e154-4625-b97c-6c8f8b27f102', 'sessions' => 'c5d09ee1-e154-4625-b97c-6c8f8b27f103'] as $mode => $id) {
                $website = Website::where('public_id', $id)->first();
                if (! $website) {
                    $website = new Website(['name' => $mode === 'sessions' ? 'Demo · Sessions' : 'Demo · No identifier', 'domain' => 'demo-'.$mode.'.test', 'allowed_domains' => ['demo-'.$mode.'.test', 'localhost', '127.0.0.1'], 'timezone' => 'Europe/Paris', 'tracking_mode' => $mode, 'consent_required' => true]);
                    $website->forceFill(['public_id' => $id, 'is_demo' => true])->save();
                }
                $website->members()->syncWithoutDetaching([$admin->id => ['role' => 'owner']]);
            }
        });
        $this->command?->info('Two demo sites are ready. Existing passwords and site settings were preserved.');
    }
}
