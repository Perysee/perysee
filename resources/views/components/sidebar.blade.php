@props(['website' => null])
<details class="sidebar-shell" open data-sidebar>
    <summary class="mobile-menu-toggle"><x-icon name="menu-2" /><span>{{ __('Navigation') }}</span><span class="mobile-wordmark">perysee<span>.</span></span></summary>
    <aside class="sidebar-panel">
        <div class="sidebar-brand"><x-brand /></div>
        <nav class="sidebar-navigation" aria-label="{{ $website ? __('Site navigation') : __('Perysee navigation') }}" data-navigation="{{ $website ? 'site' : 'global' }}">
            @if($website)
                <a class="nav-back" href="{{ route('sites.index') }}"><x-icon name="arrow-left" />{{ __('All sites') }}</a>
                @php($reportQuery = http_build_query(request()->only(['from', 'to', 'period'])))
                <p class="nav-group-label">{{ __('Traffic') }}</p>
                <x-nav-item :href="route('sites.show', $website).($reportQuery ? '?'.$reportQuery : '')" icon="chart-line" label="Overview" :active="request()->routeIs('sites.show')" />
                @foreach(['pages' => ['file-text', 'Pages'], 'events' => ['bolt', 'Events'], 'sessions' => ['users', 'Sessions'], 'realtime' => ['clock', 'Recent activity'], 'sources' => ['arrow-up-right', 'Sources'], 'campaigns' => ['chart-line', 'Campaigns'], 'devices' => ['device-desktop', 'Devices'], 'countries' => ['world', 'Countries']] as $section => [$icon, $label])
                    <x-nav-item :href="route('sites.section', ['website' => $website, 'section' => $section]).($reportQuery && $section !== 'realtime' ? '?'.$reportQuery : '')" :icon="$icon" :label="$label" :active="request()->routeIs('sites.section') && request()->route('section') === $section" />
                @endforeach
                <p class="nav-group-label">{{ __('Configuration') }}</p>
                <x-nav-item :href="route('sites.tracking', $website)" icon="code" label="Tracking setup" :active="request()->routeIs('sites.tracking')" />
                @can('update', $website)<x-nav-item :href="route('sites.edit', $website)" icon="settings" label="Site settings" :active="request()->routeIs('sites.edit')" />@endcan
            @else
                <p class="nav-group-label">{{ __('Workspace') }}</p>
                <x-nav-item :href="route('sites.index')" icon="world" label="Sites" :active="request()->routeIs('sites.*')" />
                <x-nav-item :href="route('dashboard')" icon="layout-dashboard" label="Dashboard" :active="request()->routeIs('dashboard')" />
                <p class="nav-group-label">{{ __('Manage') }}</p>
                @if(auth()->user()->is_admin)<x-nav-item :href="route('settings')" icon="settings" label="Instance settings" :active="request()->routeIs('settings')" />@endif
                <x-nav-item :href="route('password.edit')" icon="key" label="Password" :active="request()->routeIs('password.*')" />
                <x-nav-item :href="rtrim(config('perysee.docs_url'), '/')" icon="book-2" label="Documentation"  />
            @endif
        @if(auth()->user()->is_admin && !$website)<x-nav-item :href="route('health')" icon="activity" label="Instance health" :active="request()->routeIs('health')" />@endif</nav>
        <div class="sidebar-footer"><div class="sidebar-note"><x-icon name="shield-check" /><span>{{ __('Your data.') }}<br>{{ __('Your infrastructure.') }}</span></div></div>
    </aside>
</details>
