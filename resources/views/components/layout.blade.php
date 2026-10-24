@props(['title' => 'Perysee', 'website' => null])
@php
    $authenticated = auth()->check();
    $siteContext = $website ?? (request()->route('website') instanceof \App\Models\Website ? request()->route('website') : null);
    $switcherSites = $authenticated && $siteContext ? auth()->user()->websites()->orderBy('name')->limit(50)->get() : collect();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }} · Perysee</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('logo.svg') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body @class(['has-workspace' => $authenticated, 'guest-layout' => !$authenticated])>
    <a class="skip-link" href="#main">{{ __('Skip to content') }}</a>
    @if($authenticated)
        <div class="app-shell">
        <x-sidebar :website="$siteContext" />
        <div class="workspace">
        <header class="workspace-topbar">
            <div class="workspace-breadcrumb"><a href="{{ route('sites.index') }}">Perysee</a><span class="breadcrumb-divider">/</span>
                @if($siteContext)
                    <details class="context-switcher"><summary><x-icon name="world" /><span>{{ $siteContext->domain }}</span><x-icon name="chevron-down" /></summary><nav class="dropdown-panel" aria-label="Switch site">@foreach($switcherSites as $switcherSite)<a href="{{ route('sites.show', $switcherSite) }}" @if($switcherSite->id === $siteContext->id) aria-current="page" @endif><x-icon name="world" /><span>{{ $switcherSite->name }}</span></a>@endforeach<a href="{{ route('sites.index') }}"><x-icon name="arrow-left" />{{ __('All sites') }}</a></nav></details>
                @else<span>{{ __($title) }}</span>@endif
            </div>
            <details class="account-menu"><summary><x-icon name="users" /><span>{{ auth()->user()->name }}</span><x-icon name="chevron-down" /></summary><div class="dropdown-panel"><p class="account-email">{{ auth()->user()->email }}</p><a href="{{ route('sites.index') }}"><x-icon name="world" />{{ __('All sites') }}</a>@if(auth()->user()->is_admin)<a href="{{ route('settings') }}"><x-icon name="settings" />{{ __('Instance settings') }}</a>@endif<a href="{{ route('password.edit') }}"><x-icon name="key" />{{ __('Password') }}</a><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit"><x-icon name="logout" />{{ __('Sign out') }}</button></form></div></details>
        </header>
    @else
        <header class="app-header"><a class="brand" href="{{ route('home') }}"><img src="{{ asset('logo.svg') }}" alt="" width="30" height="30">perysee<span>.</span></a></header>
    @endif
    <main id="main" class="{{ $authenticated ? 'workspace-main' : 'app-main' }}">
        @if(session('status'))<div class="notice" role="status"><x-icon name="circle-check" />{{ session('status') }}</div>@endif
        @if($errors->any())<div class="error-summary" role="alert" tabindex="-1"><x-icon name="alert-triangle" /><strong>{{ __('Please check the following:') }}</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        {{ $slot }}
    </main>
    <footer class="{{ $authenticated ? 'workspace-footer' : 'app-footer' }}">See what matters. · {{ __('Your data. Your infrastructure.') }}</footer>
    @if($authenticated)</div></div>@endif
</body>
</html>
