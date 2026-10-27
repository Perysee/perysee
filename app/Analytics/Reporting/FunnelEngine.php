<?php

namespace App\Analytics\Reporting;

use Illuminate\Support\Carbon;
use RuntimeException;

class FunnelEngine
{
    public function consume(array &$state, array $events, array $payload): void
    {
        if ($events === []) {
            return;
        }
        usort($events, fn ($a, $b) => [$a['received_at'], $a['id']] <=> [$b['received_at'], $b['id']]);
        $start = $payload['kind'] === 'funnel' ? $payload['steps'][0] : $payload['start'];
        $entry = null;
        foreach ($events as $i => $event) {
            if ($this->matches($event, $start)) {
                $entry = $i;
                break;
            }
        }
        if ($entry === null) {
            return;
        }
        $first = $events[$entry];
        if ($first['received_at'] < $payload['from'] || $first['received_at'] >= $payload['to']) {
            return;
        }
        if (($payload['filters'] ?? []) !== [] && ! app(Filters::class)->matches((object) $first, $payload['filters'])) {
            return;
        }
        $state['entries'] = ($state['entries'] ?? 0) + 1;
        $at = Carbon::parse($payload['at'], 'UTC');
        $deadline = Carbon::parse($events[0]['received_at'], 'UTC')->addDay();
        $closed = $at->gte($deadline) || $at->gte(Carbon::parse(end($events)['received_at'], 'UTC')->addMinutes(30));
        if ($payload['kind'] === 'funnel') {
            $index = 1;
            $state['counts'][0] = ($state['counts'][0] ?? 0) + 1;
            foreach (array_slice($events, $entry + 1) as $event) {
                if (Carbon::parse($event['received_at'], 'UTC')->gt($deadline)) {
                    break;
                }
                if (isset($payload['steps'][$index]) && $this->matches($event, $payload['steps'][$index])) {
                    $state['counts'][$index] = ($state['counts'][$index] ?? 0) + 1;
                    $index++;
                }
            }
            if ($index < count($payload['steps'])) {
                $key = $closed ? 'abandoned' : 'pending';
                $state[$key][$index - 1] = ($state[$key][$index - 1] ?? 0) + 1;
            }
        } else {
            $actions = array_slice(array_values(array_filter(array_slice($events, $entry), fn ($event) => Carbon::parse($event['received_at'], 'UTC')->lte($deadline))), 0, 9);
            $path = array_map(fn ($event) => $event['event_type'] === 1 ? $event['path'] : 'Event: '.$event['event_name'], array_slice($actions, 0, 8));
            $path[] = count($actions) > 8 ? ':limit' : ($closed ? ':exit' : ':pending');
            $key = json_encode($path, JSON_THROW_ON_ERROR);
            $state['paths'][$key] = ($state['paths'][$key] ?? 0) + 1;
            if (count($state['paths']) > 25000) {
                throw new RuntimeException('Too many distinct paths. Choose a shorter period or narrower segment.');
            }
        }
    }

    private function matches(array $event, array $step): bool
    {
        return $step['type'] === 'path' ? $event['event_type'] === 1 && $event['path'] === $step['value'] : $event['event_type'] === 2 && $event['event_name'] === $step['value'];
    }

    public function finish(array $state, array $payload): array
    {
        $entries = $state['entries'] ?? 0;
        $nodes = $links = $steps = [];
        if ($payload['kind'] === 'funnel') {
            foreach ($payload['steps'] as $i => $step) {
                $count = $state['counts'][$i] ?? 0;
                $previous = $i === 0 ? $entries : ($state['counts'][$i - 1] ?? 0);
                $label = $step['label'] ?? ($step['type'] === 'event' ? 'Event: ' : '').$step['value'];
                $steps[] = ['label' => $label, 'count' => $count, 'rate' => $previous ? round(100 * $count / $previous, 2) : null, 'abandoned' => $state['abandoned'][$i] ?? 0, 'pending' => $state['pending'][$i] ?? 0];
                $nodes[] = ['name' => 'step:'.$i, 'label' => $label, 'depth' => $i, 'value' => $count, 'kind' => 'step'];
                if ($i > 0 && $count > 0) {
                    $links[] = ['source' => 'step:'.($i - 1), 'target' => 'step:'.$i, 'value' => $count];
                }
                foreach (['abandoned' => 'Abandoned', 'pending' => 'In progress'] as $key => $text) {
                    $value = $state[$key][$i] ?? 0;
                    if ($value) {
                        $nodes[] = ['name' => $key.':'.$i, 'label' => $text, 'depth' => $i + 1, 'value' => $value, 'kind' => $key];
                        $links[] = ['source' => 'step:'.$i, 'target' => $key.':'.$i, 'value' => $value];
                    }
                }
            }
            $conversions = $state['counts'][count($payload['steps']) - 1] ?? 0;
            $pending = array_sum($state['pending'] ?? []);

            return ['entries' => $entries, 'conversions' => $conversions, 'pending' => $pending, 'conversion_rate' => $entries > $pending ? round(100 * $conversions / ($entries - $pending), 2) : null, 'steps' => $steps, 'nodes' => $nodes, 'links' => $links];
        }
        $paths = [];
        foreach ($state['paths'] ?? [] as $path => $count) {
            $paths[] = ['path' => json_decode($path, true, flags: JSON_THROW_ON_ERROR), 'count' => $count];
        }
        $label = $payload['start']['value'];
        $nodes[] = ['name' => 'root', 'label' => $label, 'depth' => 0, 'value' => $entries, 'kind' => 'step'];
        $visit = function (array $paths, string $parent, int $depth) use (&$visit, &$nodes, &$links): void {
            $groups = [];
            foreach ($paths as $row) {
                $key = $row['path'][$depth] ?? ':exit';
                $groups[$key][] = $row;
            }
            uasort($groups, fn ($a, $b) => array_sum(array_column($b, 'count')) <=> array_sum(array_column($a, 'count')));
            $kept = 0;
            $other = 0;
            foreach ($groups as $action => $rows) {
                $count = array_sum(array_column($rows, 'count'));
                $terminal = str_starts_with($action, ':');
                if (! $terminal && ($kept++ >= 5 || count($nodes) >= 240)) {
                    $other += $count;

                    continue;
                }
                $name = $parent.':'.substr(hash('sha256', $action), 0, 12);
                $text = match ($action) {
                    ':exit' => 'Exited', ':pending' => 'In progress', ':limit' => 'Depth limit', default => $action
                };
                $nodes[] = ['name' => $name, 'label' => $text, 'depth' => $depth, 'value' => $count, 'kind' => $terminal ? substr($action, 1) : 'step'];
                $links[] = ['source' => $parent, 'target' => $name, 'value' => $count];
                if (! $terminal && $depth < 8) {
                    $visit($rows, $name, $depth + 1);
                }
            }
            if ($other > 0) {
                $name = $parent.':other';
                $nodes[] = ['name' => $name, 'label' => 'Other', 'depth' => $depth, 'value' => $other, 'kind' => 'other'];
                $links[] = ['source' => $parent, 'target' => $name, 'value' => $other];
            }
        };
        $visit($paths, 'root', 1);

        return ['entries' => $entries, 'steps' => [], 'nodes' => $nodes, 'links' => $links];
    }
}
