<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/** What every completed sign-in leaves behind: a row in the open-sessions list and one in the access log. */
final class SignInRecorder
{
    public function __construct(private readonly UserSessions $sessions) {}

    public function record(User $user, Request $request, string $event = AccessLog::LOGIN_SUCCESS, string $method = 'password'): void
    {
        try {
            $this->sessions->register($user, $request);
        } catch (\Throwable $exception) {
            // The sign-in itself has succeeded; a list that cannot be written must not undo it.
            report($exception);
        }
        AccessLog::record($event, $user, $request, $event === AccessLog::LOGIN_SUCCESS ? ['method' => $method] : []);
    }
}
