<?php

namespace App\Console\Commands;

use App\Installation\InstallationState;
use App\Models\User;
use App\Rules\BcryptPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class RecoverAdministrator extends Command
{
    protected $signature = 'perysee:recover-admin {email : Existing administrator email}';

    protected $description = 'Reset an existing administrator password using a hidden prompt and restore the installation lock';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->where('is_admin', true)->first();
        if (! $user) {
            $this->error('Administrator not found. This command does not create accounts or grant permissions.');

            return self::FAILURE;
        }
        $password = $this->secret('New password (at least 12 characters)');
        $confirmation = $this->secret('Confirm new password');
        $validator = Validator::make(['password' => $password, 'password_confirmation' => $confirmation], ['password' => ['required', 'confirmed', Password::min(12), new BcryptPassword]]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }
        app(InstallationState::class)->exclusive(function () use ($user, $password): void {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            app(InstallationState::class)->complete();
        });
        $this->info('Administrator password updated. Installation is locked. Existing sessions must sign in again.');

        return self::SUCCESS;
    }
}
