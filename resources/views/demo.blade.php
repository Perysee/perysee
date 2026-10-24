<x-layout :title="'Demo · '.ucfirst($page)">
    <a class="back-link" href="{{ route('sites.show', $website) }}"><x-icon name="arrow-left" />{{ __('Back to tracking check') }}</a>
    <p class="eyebrow">{{ __('Local test site') }} · {{ $website->name }}</p><h1>{{ ucfirst($page) }}</h1>
    <p class="muted">{{ __('This local page sends real test pageviews to your instance. It does not invent statistics. Sessions are shared across these pages only when measurement is permitted.') }}</p>
    <nav class="demo-nav" aria-label="Test pages">@foreach(['home', 'about', 'pricing'] as $item)<a href="{{ route('demo.show', ['website' => $website, 'page' => $item]) }}">{{ ucfirst($item) }}</a>@endforeach</nav>
    <div class="panel"><h2>{{ __('Test your consent flow') }}</h2><p>{{ $website->consent_required ? __('Measurement waits for your choice on each demo page. A real consent manager should restore your choice on subsequent pages.') : __('This site is configured for automatic measurement under the publisher assessment.') }}</p>
    <div class="demo-actions"><button type="button" data-measurement="allow"><x-icon name="player-play" />{{ __('Allow measurement') }}</button><button type="button" class="button secondary" data-measurement="deny"><x-icon name="player-stop" />{{ __('Withdraw / object') }}</button></div><p id="demo-status" class="hint" role="status">{{ __('Check the tracking page for confirmed events. Browser privacy signals or blockers may prevent collection.') }}</p></div>
    <script defer crossorigin="anonymous" referrerpolicy="no-referrer" src="{{ route('tracker.script') }}" data-site="{{ $website->public_id }}" data-mode="{{ $website->tracking_mode }}" data-consent-required="{{ $website->consent_required ? 'true' : 'false' }}" data-path="/demo/{{ $page }}"></script>
    <script>
        document.querySelectorAll('[data-measurement]').forEach(button => button.addEventListener('click', () => {
            const allowed = button.dataset.measurement === 'allow';
            window.lueur = window.lueur || [];
            window.lueur.push(['consent', allowed]);
            document.getElementById('demo-status').textContent = allowed ? 'Activation requested. Check confirmed events in Perysee.' : 'Measurement disabled. Any Lueur session cookie has been removed.';
        }));
    </script>
</x-layout>
