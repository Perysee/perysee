<?php

namespace App\Support;

use Inertia\Inertia;

/**
 * For a page whose props carry a secret shown once (an API token, a 2FA secret, backup codes). Inertia stores a
 * page's props in the browser's history.state; encrypting the history keeps them unreadable there (the key lives in
 * sessionStorage and goes with the tab), and clearing it makes every earlier entry undecryptable.
 * Call before Ui::render()/Inertia::render().
 */
class SecretPage
{
    public static function guard(): void
    {
        Inertia::encryptHistory();
        Inertia::clearHistory();
    }
}
