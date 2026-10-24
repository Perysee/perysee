@props(['website' => null])
<details class="sidebar-shell" open data-sidebar>
    <summary class="mobile-menu-toggle"><x-icon name="menu-2" /><span>{{ __('Navigation') }}</span><span class="mobile-wordmark">perysee<span>.</span></span></summary>
    <aside class="sidebar-panel">
        <div class="sidebar-brand"><x-brand /></div>
        <nav class="sidebar-navigation" aria-label="{{ $website ? __('Site navigation') : __('Perysee navigation') }}" data-navigation="{{ $website ? 'site' : 'global' }}">
            @if($website)
                <a class="nav-back" href="{{ route('sites.index') }}"><x-icon name="arrow-left" />{{ __('All sites') }}</a>
                <div class="sidebar-context"><span class="site-monogram"><x-icon name="world" /></span><div><strong>{{ $website->name }}</strong><span>{{ $website->domain }}</span></div></div>
                <p class="nav-group-label">{{ __('Traffic') }}</p>
                <x-nav-item :href="route('sites.show', $website)" icon="eye" label="Overview" :active="request()->routeIs('sites.show')" />
                @foreach(['events' => ['bolt', 'Events'], 'sessions' => ['users', 'Sessions'], 'realtime' => ['clock', 'Realtime']] as $section => [$icon, $label])
                    <x-nav-item :href="route('sites.section', ['website' => $website, 'section' => $section])" :icon="$icon" :label="$label" :active="request()->routeIs('sites.section') && request()->route('section') === $section" :future="true" />
                @endforeach
                <p class="nav-group-label">{{ __('Configuration') }}</p>
                <x-nav-item :href="route('sites.tracking', $website)" icon="code" label="Tracking setup" :active="request()->routeIs('sites.tracking')" />
                <x-nav-item :href="route('sites.section', ['website' => $website, 'section' => 'pixels'])" icon="grid-dots" label="Pixels" :active="request()->routeIs('sites.section') && request()->route('section') === 'pixels'" :future="true" />
                @can('update', $website)<x-nav-item :href="route('sites.edit', $website)" icon="settings" label="Site settings" :active="request()->routeIs('sites.edit')" />@endcan
            @else
                <p class="nav-group-label">{{ __('Workspace') }}</p>
                <x-nav-item :href="route('sites.index')" icon="world" label="Sites" :active="request()->routeIs('sites.*')" />
                <x-nav-item :href="route('dashboard')" icon="layout-dashboard" label="Dashboard" :active="request()->routeIs('dashboard')" :future="true" />
                <x-nav-item :href="route('pixels')" icon="grid-dots" label="Pixels" :active="request()->routeIs('pixels')" :future="true" />
                <p class="nav-group-label">{{ __('Manage') }}</p>
                @if(auth()->user()->is_admin)<x-nav-item :href="route('settings')" icon="settings" label="Instance settings" :active="request()->routeIs('settings')" />@endif
                <x-nav-item :href="route('password.edit')" icon="key" label="Password" :active="request()->routeIs('password.*')" />
                <x-nav-item :href="route('guides.show', 'application')" icon="book-2" label="Documentation" :active="request()->routeIs('guides.*')" />
            @endif
        </nav>
        <div class="sidebar-footer"><div class="sidebar-profile"><span class="profile-icon"><x-icon name="users" /></span><div><strong>{{ auth()->user()->name }}</strong><span>{{ auth()->user()->is_admin ? __('Administrator') : __('Member') }}</span></div></div><form method="POST" action="{{ route('logout') }}">@csrf<button class="sidebar-signout" type="submit"><x-icon name="logout" />{{ __('Sign out') }}</button></form></div>
    </aside>
</details>
