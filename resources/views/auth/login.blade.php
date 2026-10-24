<x-layout title="Sign in">
    <section class="auth-panel">
        <p class="eyebrow">{{ __('Welcome back') }}</p><h1>{{ __('Sign in to Perysee') }}</h1>
        <p class="muted">{{ __('Your sites, in one place.') }}</p>
        <form method="POST" action="{{ route('login.store') }}" class="stack">@csrf
            <label for="email">{{ __('Email') }}<input id="email" name="email" type="email" value="{{ old('email') }}" autocomplete="username" required autofocus></label>
            <label for="password">{{ __('Password') }}<input id="password" name="password" type="password" autocomplete="current-password" required></label>
            <button type="submit"><x-icon name="login" />{{ __('Sign in') }}</button>
        </form>
        <p class="muted small">{{ __('Accounts are managed by the instance administrator. Public registration is closed.') }}</p>
    </section>
</x-layout>
