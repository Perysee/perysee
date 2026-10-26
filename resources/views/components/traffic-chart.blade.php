@props(['series', 'metric' => 'pageviews', 'compact' => false, 'only' => null])
@php
    $metricValues = array_column($series, $metric);
    $knownValues = array_filter($metricValues, fn ($value) => $value !== null);
@endphp
<figure @class(['traffic-chart', 'traffic-chart-compact' => $compact]) aria-label="{{ $compact ? 'Pageviews over the selected period' : 'Activity over time' }}">
    <div class="echart-line" data-echart data-series="{{ json_encode($series) }}" data-metric="{{ $metric }}" data-compact="{{ $compact ? 'true' : 'false' }}" role="img" aria-label="{{ ['pageviews' => 'Pageviews', 'sessions' => 'Sessions started', 'custom_events' => 'Custom events'][$metric] }} over the selected period"><p class="chart-fallback">{{ $metric === 'sessions' && $knownValues === [] ? 'Session measurement unavailable' : 'Chart values are available in the data table.' }}</p></div>
    @unless($compact)<p class="chart-empty-label" data-chart-empty @if(array_sum($knownValues) !== 0) hidden @endif>{{ $metric === 'sessions' && $knownValues === [] ? 'Session measurement unavailable' : 'No measured activity in this selection' }}</p>@endunless
</figure>
