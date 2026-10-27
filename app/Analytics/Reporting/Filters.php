<?php

namespace App\Analytics\Reporting;

use App\Analytics\Ingestion\Sources;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

class Filters
{
    public const DIMENSIONS = ['path', 'country', 'source', 'provider', 'campaign', 'device', 'device_brand', 'browser', 'event'];

    public function parse(Request $request): array
    {
        $rules = ['filters' => ['sometimes', 'array:'.implode(',', self::DIMENSIONS)]];
        foreach (self::DIMENSIONS as $dimension) {
            $rules['filters.'.$dimension] = ['sometimes', 'array', 'max:20'];
            $rules['filters.'.$dimension.'.*'] = ['required', 'string', 'max:512'];
        }
        $data = $request->validate($rules)['filters'] ?? [];
        $filters = [];
        foreach ($data as $key => $values) {
            if ($values !== []) {
                $filters[$key] = array_values(array_unique($values));
                sort($filters[$key]);
            }
        }
        ksort($filters);

        return $filters;
    }

    public function apply(Builder $query, array $filters): Builder
    {
        foreach ($filters as $key => $values) {
            if (in_array($key, self::DIMENSIONS, true) && $values !== []) {
                $query->whereIn($key, $values);
            }
        }

        return $query;
    }

    public function values(object $event): array
    {
        $source = $event->dimensions_available ? ($event->source_value ?: ($event->referrer_host ?: 'Direct')) : null;
        if ($source !== null) {
            $source = app(Sources::class)->describe($source)['value'];
        }
        $provider = $source !== null ? app(Sources::class)->describe($source)['provider'] : null;

        return ['path' => $event->path, 'country' => $event->geo_available ? ($event->country_code ?: 'Unknown') : null,
            'source' => $source, 'provider' => $provider,
            'campaign' => $event->dimensions_available && ($event->utm_campaign || $event->utm_source || $event->utm_medium) ? json_encode(['source' => $event->utm_source, 'medium' => $event->utm_medium, 'campaign' => $event->utm_campaign], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) : null,
            'device' => $event->dimensions_available ? ($event->device_type ?: 'unknown') : null,
            'device_brand' => $event->device_brand, 'browser' => $event->browser_name,
            'event' => $event->event_type === 2 ? ($event->event_name ?: 'unknown') : null];
    }

    public function matches(object $event, array $filters): bool
    {
        $values = $this->values($event);
        foreach ($filters as $key => $allowed) {
            if (! in_array($values[$key] ?? null, $allowed, true)) {
                return false;
            }
        }

        return true;
    }
}
