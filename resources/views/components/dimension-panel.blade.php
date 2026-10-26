@props(['website', 'period', 'type', 'rows', 'total' => 0, 'compact' => false])
@php
    $heading = ['path' => 'Popular pages', 'source' => 'Sources', 'campaign' => 'Campaigns', 'device' => 'Devices', 'event' => 'Custom events', 'country' => 'Countries'][$type];
    $icon = ['path' => 'file-text', 'source' => 'arrow-up-right', 'campaign' => 'chart-line', 'device' => 'device-desktop', 'event' => 'bolt', 'country' => 'world'][$type];
    $query = ['from' => $period['from'], 'to' => $period['to']] + request()->only(['source_group', 'provider']);
    $section = ['source' => 'sources', 'campaign' => 'campaigns', 'device' => 'devices', 'event' => 'events', 'path' => 'pages', 'country' => 'countries'][$type] ?? 'pages';
    $reportUrl = $section ? route('sites.section', [$website, $section]).'?'.http_build_query($query) : route('sites.show', $website).'?'.http_build_query($query + ['view' => 'pages']);
    $visible = $compact ? array_slice($rows, 0, 7) : $rows;
@endphp
<section class="report-panel dimension-panel" id="{{ $type }}-panel">
    <header class="panel-heading"><h2><x-icon :name="$icon" />{{ $heading }}</h2></header>
    @if($type === 'source' && !$compact)<form method="GET" class="source-filters"><input type="hidden" name="from" value="{{ $period['from'] }}"><input type="hidden" name="to" value="{{ $period['to'] }}"><label>Sources<select name="source_group" aria-label="Source group"><option value="all">All sources</option><option value="ai" @selected(request('source_group') === 'ai')>AI sources</option></select></label><label>Provider<select name="provider" aria-label="Provider"><option value="">All providers</option>@foreach(\App\Analytics\Ingestion\Sources::PROVIDERS as $key => $entry)<option value="{{ $key }}" @selected(request('provider') === $key)>{{ $entry['label'] }}</option>@endforeach</select></label><button class="button secondary">Apply</button></form>@endif
    @unless($compact)<form method="GET" class="dimension-filter" role="search">@foreach(request()->only(['source_group', 'provider']) as $key => $selected)<input type="hidden" name="{{ $key }}" value="{{ $selected }}">@endforeach<input type="hidden" name="from" value="{{ $period['from'] }}"><input type="hidden" name="to" value="{{ $period['to'] }}">@if($type === 'path')<input type="hidden" name="view" value="pages">@endif<label class="search-field"><span class="sr-only">Exact value</span><x-icon name="search" /><input name="value" maxlength="512" value="{{ request('value') }}" placeholder="Filter by exact value"></label><button class="button secondary" type="submit">Filter</button>@if(request('value'))<a class="text-link" href="{{ $reportUrl }}">Clear</a>@endif</form>@endunless
    <div class="table-wrap"><table class="breakdown-table"><thead><tr><th>{{ $heading === 'Popular pages' ? 'Page' : 'Value' }}</th><th>{{ $type === 'event' ? 'Events' : 'Pageviews' }}</th><th>Share</th></tr></thead><tbody>
    @forelse($visible as $row)
        @php
            $name = $row['label'] ?? $row['value']; $detail = null;
            if ($type === 'campaign') {
                $campaign = json_decode($name, true);
                if (is_array($campaign)) { $name = ($campaign['campaign'] ?? null) ?: (($campaign['source'] ?? null) ?: (($campaign['medium'] ?? null) ?: 'Untitled campaign')); $detail = implode(' / ', array_filter([$campaign['source'] ?? null, $campaign['medium'] ?? null])); }
            }
            $share = $total > 0 ? min(100, 100 * $row['count'] / $total) : 0;
        @endphp
        <tr><td><div class="breakdown-value"><span class="breakdown-bar" style="width: {{ round($share, 2) }}%" aria-hidden="true"></span><a class="breakdown-name" href="{{ $reportUrl.'&'.http_build_query(['value' => $row['value']]) }}" title="Filter: {{ $name }}">@if($type === 'source' && !empty($row['icon']))<img class="source-logo" src="{{ asset('brands/'.$row['icon'].'.svg') }}" width="20" height="20" alt="">@elseif($type === 'source')<x-icon name="world" />@endif{{ $name }}@if($detail)<small>{{ $detail }}</small>@endif</a></div></td><td>{{ number_format($row['count']) }}</td><td>{{ number_format($share, 1) }}%</td></tr>
    @empty<tr><td colspan="3"><div class="panel-empty"><x-icon :name="$icon" /><strong>No data for this selection</strong><p>{{ $type === 'path' ? 'Pages will appear after the next consolidation.' : 'This breakdown starts when the corresponding data is collected.' }}</p></div></td></tr>@endforelse
    </tbody></table></div>
    <footer class="panel-footer">@if($compact)<span>{{ count($visible) }} of {{ count($rows) }} values</span><a href="{{ $reportUrl }}">View report <x-icon name="arrow-up-right" /></a>@else<span>Up to 100 values · share of {{ request('value') ? 'filtered' : 'attributed' }} {{ $type === 'event' ? 'events' : 'pageviews' }}</span>@endif</footer>
</section>
