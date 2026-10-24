<x-layout :title="$title" :website="$website">
    <div class="page-heading"><div><p class="eyebrow">{{ $website ? $website->domain : __('Perysee workspace') }}</p><h1>{{ __($title) }}</h1><p class="muted">{{ __($description) }}</p></div><span class="availability-badge">{{ __('Coming soon') }}</span></div>
    <section class="feature-placeholder"><span class="placeholder-icon"><x-icon :name="$icon" /></span><p class="eyebrow">{{ __('Coming soon') }}</p><h2>{{ __('This space is taking shape') }}</h2><p class="muted">{{ __($message) }}</p><a class="button secondary" href="{{ $website ? route('sites.show', $website) : route('sites.index') }}"><x-icon name="arrow-left" />{{ $website ? __('Back to overview') : __('View your sites') }}</a></section>
</x-layout>
