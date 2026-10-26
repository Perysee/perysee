@props(['period', 'timezone' => 'UTC'])
<div class="period-control">
    <div class="period-presets" aria-label="Quick date ranges">@foreach(['today' => 'Today', '7d' => '7 days', '30d' => '30 days'] as $key => $label)<a href="?{{ http_build_query(['period' => $key] + request()->only(['value', 'view', 'source_group', 'provider'])) }}" @if(request('period', request()->has('from') ? '' : '7d') === $key) aria-current="true" @endif>{{ $label }}</a>@endforeach</div>
    <details class="date-menu" data-date-menu>
        <summary><x-icon name="calendar" /><span>{{ \Illuminate\Support\Carbon::parse($period['from'])->format('M j') }} – {{ \Illuminate\Support\Carbon::parse($period['to'])->format('M j, Y') }}</span><x-icon name="chevron-down" /></summary>
        <form method="GET" class="date-popover"><strong>Custom date range</strong>@foreach(request()->only(['source_group', 'provider']) as $key => $selected)<input type="hidden" name="{{ $key }}" value="{{ $selected }}">@endforeach
@if(request()->filled('value'))<input type="hidden" name="value" value="{{ request('value') }}">@endif<label for="report-from">From<input id="report-from" type="date" name="from" value="{{ $period['from'] }}" max="{{ now($timezone)->toDateString() }}" required></label><label for="report-to">To<input id="report-to" type="date" name="to" value="{{ $period['to'] }}" max="{{ now($timezone)->toDateString() }}" required></label>@if(request('view') === 'pages')<input type="hidden" name="view" value="pages">@endif<button type="submit">Apply date range</button></form>
    </details>
</div>
