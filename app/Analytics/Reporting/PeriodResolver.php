<?php

namespace App\Analytics\Reporting;

use App\Models\Website;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class PeriodResolver
{
    public function period(Request $request, Website $site): array
    {
        $input = $request->validate([
            'period' => ['sometimes', 'in:today,7d,30d'],
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'dimension' => ['sometimes', 'in:path,source,campaign,device,event,country,browser,device_brand'],
            'source_group' => ['sometimes', 'in:all,ai'], 'provider' => ['nullable', 'in:chatgpt,claude,perplexity,gemini,copilot,grok'],
            'value' => ['nullable', 'string', 'max:512'], 'granularity' => ['sometimes', 'in:day,hour'],
            'compare' => ['sometimes', 'in:previous'],
        ]);
        $today = now($site->timezone)->startOfDay();
        $days = match ($input['period'] ?? '7d') {
            'today' => 0, '30d' => 29, default => 6
        };
        $from = $input['from'] ?? $today->copy()->subDays($days)->toDateString();
        $to = $input['to'] ?? $today->toDateString();
        $start = Carbon::createFromFormat('!Y-m-d', $from, $site->timezone);
        $end = Carbon::createFromFormat('!Y-m-d', $to, $site->timezone);
        if ($start->gt($end) || $end->gt($today) || $start->diffInDays($end) > 1095) {
            throw ValidationException::withMessages(['from' => 'Choose an ordered date range of at most three years, ending today or earlier.']);
        }
        if (($input['granularity'] ?? 'day') === 'hour' && $from !== $to) {
            throw ValidationException::withMessages(['granularity' => 'Hourly reports require one local calendar day.']);
        }

        return ['from' => $from, 'to' => $to, 'start' => $start->copy()->utc(), 'end' => $end->copy()->addDay()->utc(), 'granularity' => $input['granularity'] ?? ($from === $to ? 'hour' : 'day'), 'filters' => app(Filters::class)->parse($request), 'source_group' => $input['source_group'] ?? 'all', 'provider' => $input['provider'] ?? null, 'compare' => $input['compare'] ?? null];
    }

    /**
     * The period of the same length that ends the day before $period starts, with the same filters.
     *
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>
     */
    public function previousPeriod(array $period, Website $site): array
    {
        $start = Carbon::createFromFormat('!Y-m-d', $period['from'], $site->timezone);
        $end = Carbon::createFromFormat('!Y-m-d', $period['to'], $site->timezone);
        $days = (int) $start->diffInDays($end) + 1;
        $previousEnd = $start->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1);

        return [
            'from' => $previousStart->toDateString(), 'to' => $previousEnd->toDateString(),
            'start' => $previousStart->copy()->utc(), 'end' => $previousEnd->copy()->addDay()->utc(),
            'granularity' => $days === 1 ? 'hour' : 'day',
        ] + $period;
    }
}
