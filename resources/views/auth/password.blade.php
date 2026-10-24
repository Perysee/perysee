<x-layout title="Change password">
    <section class="auth-panel"><p class="eyebrow">{{ __('Account') }}</p><h1>{{ __('Change password') }}</h1>
    <form method="POST" action="{{ route('password.update') }}" class="stack">@csrf @method('PUT')
        <label for="current_password">{{ __('Current password') }}<input id="current_password" type="password" name="current_password" autocomplete="current-password" required></label>
        <label for="password">{{ __('New password') }}<input id="password" type="password" name="password" minlength="12" autocomplete="new-password" required><span class="hint">{{ __('Use at least 12 characters.') }}</span></label>
        <label for="password_confirmation">{{ __('Confirm new password') }}<input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required></label>
        <button type="submit"><x-icon name="key" />{{ __('Update password') }}</button>
    </form></section>
</x-layout>
