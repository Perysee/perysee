<x-layout title="Dashboard">
<div class="page-heading"><div><p class="eyebrow">Your workspace</p><h1>Dashboard</h1><p class="muted">Consolidated activity for your sites. Each report follows the site's timezone.</p></div><a class="button" href="{{ route('sites.create') }}"><x-icon name="plus" />Add site</a></div>
<div class="workspace-toolbar"><div><strong>{{ $sites->total() }}</strong><span class="subtle">{{ $sites->total() === 1 ? 'website' : 'websites' }} in your workspace</span></div><nav class="period-presets" aria-label="Dashboard period">@foreach(['today' => 'Today', '7d' => '7 days', '30d' => '30 days'] as $key => $label)<a href="?period={{ $key }}" @if(request('period', '7d') === $key) aria-current="true" @endif>{{ $label }}</a>@endforeach</nav></div>
<div class="site-card-grid">
@forelse($sites as $site)
    @php($summary = $summaries[$site->id])
    <article class="report-panel site-report-card">@if($site->is_demo)<span class="demo-badge">Demonstration data</span>@endif<header><a class="site-card-name" href="{{ route('sites.show', $site).'?'.http_build_query(['from' => $summary['period']['from'], 'to' => $summary['period']['to']]) }}"><x-site-icon :website="$site" /><span><h2>{{ $site->name }}</h2><small>{{ $site->domain }}</small></span></a><span @class(['status-dot', 'is-pending' => $summary['freshness']['pending']]) title="{{ $summary['freshness']['pending'] ? 'Pending events' : 'No pending events' }}"></span></header><div class="site-card-metrics"><div><span>Pageviews</span><strong>{{ number_format($summary['totals']['pageviews']) }}</strong></div><div><span>Sessions started</span><strong>{{ $summary['totals']['sessions'] === null ? '—' : number_format($summary['totals']['sessions']) }}</strong></div><div><span>Custom events</span><strong>{{ number_format($summary['totals']['custom_events']) }}</strong></div></div>
    <x-traffic-chart :series="$charts[$site->id]" :compact="true" />
    <footer class="panel-footer"><span>{{ $summary['freshness']['pending'] ? 'Consolidation pending' : 'Consolidated activity' }}@if($summary['availability']['sessions'] !== 'available') · Sessions {{ $summary['availability']['sessions'] }}@endif</span><a href="{{ route('sites.show', $site).'?'.http_build_query(['from' => $summary['period']['from'], 'to' => $summary['period']['to']]) }}">Open report <x-icon name="arrow-up-right" /></a></footer></article>
@empty
    <section class="report-panel dashboard-empty"><span class="placeholder-icon"><x-icon name="chart-line" /></span><h2>Your first insight starts with a site</h2><p>Add your website, connect Lueur and see your real traffic here.</p><a class="button" href="{{ route('sites.create') }}"><x-icon name="plus" />Add your first site</a></section>
@endforelse
</div>{{ $sites->links() }}
</x-layout>
