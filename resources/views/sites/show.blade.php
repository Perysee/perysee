<x-layout title="Reports" :website="$website">
@php
    $currentSection = $section ?? 'overview';
    $isDimension = in_array($currentSection, ['pages', 'sources', 'campaigns', 'devices', 'events', 'countries']);
    $isSessions = $currentSection === 'sessions';

    if ($isDimension) { $series = $dimensionReport['series']; $dimensions = $dimensionReport['data']; }
    $overview = $currentSection === 'overview';
    $isRecent = $currentSection === 'realtime';
    $query = ['from' => $period['from'], 'to' => $period['to']] + request()->only(['source_group', 'provider']);
    $initialMetric = $currentSection === 'sessions' ? 'sessions' : ($currentSection === 'events' ? 'custom_events' : 'pageviews');
    $sessionsAvailable = $report['totals']['sessions'] !== null;
@endphp
@if($website->is_demo)<span class="demo-badge">Demonstration data · synthetic events</span>@endif
<div class="report-heading page-heading">
    <div class="report-identity"><x-site-icon :website="$website" /><div><p class="eyebrow">{{ ['overview' => 'Site overview', 'realtime' => 'Recent activity'][$currentSection] ?? ucfirst($currentSection) }}</p><h1>{{ $website->name }}</h1><p class="site-address">{{ $website->domain }}<span>·</span>{{ $website->timezone }}</p></div></div>
    <div class="heading-actions">@if($isRecent)<a class="button secondary" href="{{ route('sites.section', [$website, 'realtime']) }}"><x-icon name="refresh" />Refresh</a>@else<a class="button secondary" href="{{ route('sites.export', $website).'?'.http_build_query($isDimension ? $query + ['dimension' => $dimension, 'value' => request('value')] : $query) }}"><x-icon name="download" />{{ $isDimension ? 'Export table CSV' : 'Export daily CSV' }}</a>@endif @can('update', $website)<a class="icon-button heading-settings" href="{{ route('sites.edit', $website) }}" aria-label="Edit site"><x-icon name="settings" /></a>@endcan</div>
</div>
<div class="report-toolbar">@unless($isRecent)<x-report-period :period="$period" :timezone="$website->timezone" />@else<span class="window-chip"><x-icon name="clock" />Last 15 minutes</span>@endunless</div>
@if($isRecent)
    <x-reception-feed :recent="$recent" :standalone="true" :totals="$receptionTotals" />
@else
    <div class="report-freshness"><span class="freshness-status"><span @class(['status-dot', 'is-pending' => $report['freshness']['pending']])></span>{{ $report['freshness']['pending'] ? 'Consolidation pending' : ($report['freshness']['aggregated_at'] ? 'Consolidated data' : 'Waiting for first consolidation') }}</span><span>Last consolidation: {{ $report['freshness']['aggregated_at'] ? \Illuminate\Support\Carbon::parse($report['freshness']['aggregated_at'])->timezone($website->timezone)->format('M j, H:i') : 'Not yet consolidated' }}</span></div>
    @if($report['freshness']['pending'])<div class="inline-notice"><x-icon name="clock" /><span>New events are waiting to be consolidated. Reports are incomplete until the next consolidation.</span></div>@endif
    @if($isDimension && in_array($dimension, ['source', 'campaign', 'device']) && $report['availability']['dimensions'] !== 'available')<div class="inline-notice"><x-icon name="info-circle" /><span>Historical events without this dimension cannot be attributed. Coverage begins when the updated tracker collects it.</span></div>@endif
    @if($currentSection === 'countries')
    <div class="inline-notice"><x-icon name="info-circle" /><span>Country coverage: {{ $report['availability']['countries'] }} · {{ number_format($report['coverage']['country_pageviews']) }} of {{ number_format($report['totals']['pageviews']) }} pageviews measured. Unknown countries are not located on the map. <a href="https://db-ip.com" rel="noreferrer">IP Geolocation by DB-IP</a> · approximate country data.</span></div>
    <section class="report-panel country-map-panel"><header class="panel-heading"><h2>Pageviews by country</h2></header><div data-country-map data-map-url="{{ asset('maps/world.json') }}" data-report-url="{{ route('sites.section', [$website, 'countries']).'?'.http_build_query($query) }}" data-countries="{{ json_encode($dimensionReport['map']) }}" role="img" aria-label="Country pageviews. The accessible country table is below."><p class="chart-fallback">Country counts are available in the table below.</p></div></section>
    @endif
    <section class="report-panel traffic-panel" data-report-chart>
        @if($isDimension)
        <div class="report-specific-summary"><div><span class="metric-label">{{ $dimension === 'event' ? 'Tracked events' : 'Attributed pageviews' }}</span><strong>{{ number_format($dimensionReport['total']) }}</strong></div><div><span class="metric-label">{{ $dimension === 'event' ? 'Event names' : ($dimension === 'country' ? 'Country groups' : ucfirst($currentSection)) }}</span><strong>{{ number_format($dimensionReport['distinct_values']) }}</strong></div><p>{{ request('value') ? 'Filtered by exact value. The chart and export use this same selection.' : 'Only events with this dimension collected are included.' }}</p></div>
        @elseif($isSessions)
        <div class="report-specific-summary"><div><span class="metric-label">Sessions started</span><strong>{{ $report['totals']['sessions'] === null ? '—' : number_format($report['totals']['sessions']) }}</strong></div><div><span class="metric-label">Pageviews with session measurement</span><strong>{{ number_format($report['coverage']['measured_pageviews']) }}</strong></div><div><span class="metric-label">Session coverage</span><strong class="coverage-value">{{ ucfirst($report['availability']['sessions']) }}</strong></div></div>
        @else
        <div class="report-metrics" aria-label="Consolidated metrics">
        @foreach(['pageviews' => ['Pageviews', 'eye'], 'sessions' => ['Sessions started', 'users'], 'custom_events' => ['Custom events', 'bolt']] as $key => [$label, $icon])
            <button type="button" class="report-metric" data-chart-metric="{{ $key }}" aria-pressed="{{ $initialMetric === $key ? 'true' : 'false' }}"><span class="metric-label"><x-icon :name="$icon" />{{ $label }}@if($key === 'sessions' && $report['availability']['sessions'] === 'partial')<span class="metric-note">Partial</span>@endif</span><strong>{{ $report['totals'][$key] === null ? '—' : number_format($report['totals'][$key]) }}</strong><span class="metric-caption">{{ $key === 'pageviews' ? 'Pages viewed in this period' : ($key === 'sessions' ? ($sessionsAvailable ? 'Counted at their first pageview' : 'Session measurement unavailable') : 'Actions tracked on your website') }}</span></button>
        @endforeach
        </div>
        @endif
        <div class="chart-heading"><h2>Activity over time</h2><span class="chart-legend"><i></i><span data-chart-legend>{{ ['pageviews' => 'Pageviews', 'sessions' => 'Sessions started', 'custom_events' => 'Custom events'][$initialMetric] }}</span><span class="chart-granularity">{{ !$isDimension && $period['granularity'] === 'hour' ? 'Hourly' : 'Daily' }}</span></span></div>
        <x-traffic-chart :series="$series" :metric="$initialMetric" :only="$isDimension || $isSessions ? [$initialMetric] : null" />
        <details class="chart-data"><summary>View chart data</summary><div class="table-wrap"><table><thead><tr><th>Period</th>@foreach(($isDimension || $isSessions ? [$initialMetric] : ['pageviews', 'sessions', 'custom_events']) as $key)<th>{{ ['pageviews' => 'Pageviews', 'sessions' => 'Sessions started', 'custom_events' => 'Custom events'][$key] }}</th>@endforeach</tr></thead><tbody>@foreach($series as $point)<tr><td>{{ $point['label'] }}</td>@foreach(($isDimension || $isSessions ? [$initialMetric] : ['pageviews', 'sessions', 'custom_events']) as $key)<td>{{ $point[$key] ?? 'Unavailable' }}</td>@endforeach</tr>@endforeach</tbody></table></div></details>
    </section>
    @unless($isSessions)
    <div @class(['breakdown-grid', 'breakdown-grid-single' => !$overview])>
        <x-dimension-panel :website="$website" :period="$period" :type="$dimension" :rows="$dimensions" :total="$isDimension ? $dimensionReport['total'] : $report['totals']['pageviews']" :compact="$overview" />
        @if($overview)@foreach(array_intersect_key($breakdowns, ['source' => true]) as $type => $rows)<x-dimension-panel :website="$website" :period="$period" :type="$type" :rows="$rows" :total="$type === 'event' ? $report['totals']['custom_events'] : $report['totals']['pageviews']" :compact="true" />@endforeach @endif
    </div>
    @endunless
    <details class="data-notes"><summary><x-icon name="info-circle" />About these numbers @if($report['availability']['sessions'] !== 'available')<span class="neutral-badge">{{ ucfirst($report['availability']['sessions']) }} session measurement</span>@endif<x-icon name="chevron-down" /></summary><div><p>Sessions are not people. Sessions are unavailable or only partially measured in some periods. Historical periods are not reconstructed.</p><p>UTM-first attribution applies from {{ $report['attribution_v2_since'] ?? 'this version' }}. Earlier Direct traffic cannot be reconstructed.</p><p>Source, campaign and device coverage: {{ $report['availability']['dimensions'] }}. Country coverage: {{ $report['availability']['countries'] }}.</p>@if($report['measurement_periods'])<h3>Measurement periods (UTC)</h3><ul>@foreach($report['measurement_periods'] as $measurement)<li>{{ $measurement['started_at'] }} → {{ $measurement['ended_at'] ?? 'Present' }} · {{ $measurement['mode'] }}</li>@endforeach</ul>@endif<p>Earlier settings are unknown unless captured by collected events.</p></div></details>

@endif

</x-layout>
