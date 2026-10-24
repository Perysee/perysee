<x-layout :title="$title">
    <a class="back-link" href="{{ route('sites.index') }}"><x-icon name="arrow-left" />{{ __('Your sites') }}</a>
    <nav class="guide-links" aria-label="Documentation"><a href="{{ route('guides.show', 'application') }}"><x-icon name="settings" />{{ __('Application setup') }}</a><a href="{{ route('guides.show', 'tracking') }}"><x-icon name="code" />{{ __('Install Lueur') }}</a><a href="{{ route('guides.show', 'privacy') }}"><x-icon name="shield-lock" />{{ __('Privacy choices') }}</a></nav>
    <article class="guide-content">{!! $content !!}</article>
</x-layout>
