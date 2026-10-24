<x-layout title="Install Perysee">
    <section class="installer"><p class="eyebrow">{{ __('Welcome to your instance') }}</p><h1>{{ __('Install Perysee') }}</h1><p class="muted">{{ __('A few steps to make this space yours.') }}</p>
    <ol class="steps" aria-label="Installation progress"><li @class(['active' => !$authorized])>1. {{ __('Compatibility') }}</li><li @class(['active' => $authorized && !$migrated])>2. {{ __('Database') }}</li><li @class(['active' => $authorized && $migrated])>3. {{ __('Administrator') }}</li></ol>
    <details class="panel" @if(!$authorized) open @endif><summary><x-icon name="settings" />{{ __('Instance checks') }}</summary><ul class="checks">@foreach($checks as $check)<li><span @class(['check-status', 'failed' => !$check['ok']])><x-icon :name="$check['ok'] ? 'check' : 'alert-triangle'" />{{ $check['ok'] ? __('Ready') : __('Action needed') }}</span>{{ $check['label'] }}</li>@endforeach</ul><p class="hint">{{ __('In cPanel, confirm the domain document root ends in /public. Keep .env, vendor, storage and backups outside the public directory. The installer cannot audit every web server alias or configuration.') }}</p></details>
    @if(!$authorized)
        <div class="panel"><h2><x-icon name="shield-check" />{{ __('Verify instance ownership') }}</h2><p class="muted">{{ __('Open your hosting file manager and copy the secret from this private file:') }}</p><code class="file-path">storage/app/private/installation/secret</code><p class="hint">{{ __('This file is outside public/. Never share it or put it in a URL.') }}</p>
        <form method="POST" action="{{ route('install.unlock') }}" class="stack">@csrf<label for="installation_secret">{{ __('Installation secret') }}<input id="installation_secret" name="installation_secret" type="password" autocomplete="off" required></label><button type="submit"><x-icon name="shield-check" />{{ __('Continue securely') }}</button></form></div>
    @elseif(!$migrated)
        <div class="panel"><h2><x-icon name="database" />{{ __('Connect your database') }}</h2><p class="muted">{{ __('Create a dedicated MariaDB 11.4 database and user in your hosting panel first. The user needs permission to create and alter tables.') }}</p>
        <form method="POST" action="{{ route('install.database') }}" class="stack">@csrf
            <label for="db_host">{{ __('Database host') }}<input id="db_host" name="db_host" value="{{ old('db_host', config('database.connections.mariadb.host', 'localhost')) }}" required></label>
            <label for="db_port">{{ __('Port') }}<input id="db_port" name="db_port" type="number" min="1" max="65535" value="{{ old('db_port', config('database.connections.mariadb.port', 3306)) }}" required></label>
            <label for="db_database">{{ __('Database name') }}<input id="db_database" name="db_database" value="{{ old('db_database', config('database.connections.mariadb.database')) }}" required></label>
            <label for="db_username">{{ __('Database user') }}<input id="db_username" name="db_username" value="{{ old('db_username', config('database.connections.mariadb.username')) }}" autocomplete="off" required></label>
            <label for="db_password">{{ __('Database password') }}<input id="db_password" name="db_password" type="password" autocomplete="off"><span class="hint">{{ __('Leave blank to keep a saved password for the same database. Passwords are never displayed or stored in your browser session.') }}</span></label>
            <button type="submit"><x-icon name="database" />{{ __('Connect and prepare database') }}</button>
        </form></div>
    @else
        <div class="panel"><h2><x-icon name="user-plus" />{{ __('Create your administrator') }}</h2><p class="muted">{{ __('The database is ready. This account will manage your instance. Public registration is closed.') }}</p>
        <form method="POST" action="{{ route('install.administrator') }}" class="stack">@csrf
            <label for="name">{{ __('Name') }}<input id="name" name="name" value="{{ old('name') }}" autocomplete="name" required></label>
            <label for="email">{{ __('Email') }}<input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required></label>
            <label for="password">{{ __('Password') }}<input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required><span class="hint">{{ __('Use at least 12 characters.') }}</span></label>
            <label for="password_confirmation">{{ __('Confirm password') }}<input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required></label>
            <button type="submit"><x-icon name="check" />{{ __('Finish installation') }}</button>
        </form></div>
    @endif
    <p class="hint">{{ __('Lueur tracking is available after setup. Aggregated reports are coming in a future release.') }}</p></section>
</x-layout>
