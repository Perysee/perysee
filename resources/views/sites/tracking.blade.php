<x-layout title="Tracking setup" :website="$website">
    <div class="page-heading"><div><p class="eyebrow">{{ $website->domain }}</p><h1>{{ __('Tracking setup') }}</h1><p class="muted">{{ __('Install your tracker and choose how this site is measured.') }}</p></div>@can('update', $website)<a class="button secondary" href="{{ route('sites.edit', $website) }}"><x-icon name="settings" />{{ __('Site settings') }}</a>@endcan</div>
    <section class="panel"><h2><x-icon name="code" />{{ __('Install Lueur') }}</h2><p class="muted">{{ __('Add this script to the pages you want to measure. Your browser negotiates gzip compression automatically.') }}</p>
        @php($snippet = '<script defer crossorigin="anonymous" referrerpolicy="no-referrer" src="'.route('tracker.script').'" data-site="'.$website->public_id.'" data-mode="'.$website->tracking_mode.'" data-consent-required="'.($website->consent_required ? 'true' : 'false').'"></script>')
        <pre class="snippet"><code>{{ $snippet }}</code></pre>
        @if($website->consent_required)
            <p class="hint">{{ __('Connect your consent manager: grant with lueur.consent(true), withdraw with lueur.consent(false). No events or session cookies are created before activation. For strict consent gating, load the script itself only after permission.') }}</p>
            <pre class="snippet"><code>window.lueur = window.lueur || [];
window.lueur.push(['consent', true]);</code></pre>
        @else
            <p class="hint">{{ __('Automatic measurement is enabled under your assessment that consent is not required. This setting is not a legal certification. Keep an objection mechanism available through lueur.consent(false).') }}</p>
        @endif
        @if($website->is_demo && app()->environment('local'))<a class="button secondary" href="{{ route('demo.show', $website) }}">{{ __('Open local test site') }}<x-icon name="arrow-up-right" /></a>@endif
        <p class="guide-links"><a href="{{ route('guides.show', 'tracking') }}"><x-icon name="book-2" />{{ __('Installation guide') }}</a><a href="{{ route('guides.show', 'privacy') }}"><x-icon name="shield-lock" />{{ __('Privacy choices') }}</a></p><p class="hint">{{ __('Aggregated reports and campaign attribution are not available yet.') }}</p>
    </section>
    <dl class="details"><div><dt>{{ __('Public site identifier') }}</dt><dd><code>{{ $website->public_id }}</code><p class="hint">{{ __('This identifier is public. It is not an authentication secret.') }}</p></dd></div><div><dt>{{ __('Measurement') }}</dt><dd>{{ $website->tracking_mode === 'sessions' ? __('Pageviews and sessions') : __('Pageviews without an identifier') }}</dd></div><div><dt>{{ __('Consent') }}</dt><dd>{{ $website->consent_required ? __('Required before measurement') : __('Not required according to publisher assessment') }}</dd></div><div><dt>{{ __('Reporting timezone') }}</dt><dd>{{ $website->timezone }}</dd></div><div><dt>{{ __('Allowed domains') }}</dt><dd>@foreach($website->allowed_domains as $domain)<span class="domain">{{ $domain }}</span>@endforeach</dd></div></dl>
</x-layout>
