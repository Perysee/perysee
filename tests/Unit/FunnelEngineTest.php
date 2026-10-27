<?php

namespace Tests\Unit;

use App\Analytics\Reporting\FunnelEngine;
use Tests\TestCase;

class FunnelEngineTest extends TestCase
{
    private function events(array $actions): array
    {
        return array_map(fn ($action, $i) => ['id' => $i + 1, 'event_type' => str_starts_with($action, '/') ? 1 : 2, 'path' => str_starts_with($action, '/') ? $action : '/', 'event_name' => str_starts_with($action, '/') ? null : $action, 'received_at' => sprintf('2026-10-24 10:%02d:00', $i)], $actions, array_keys($actions));
    }

    public function test_funnel_allows_intermediate_actions_and_requires_separate_repeated_steps(): void
    {
        $engine = app(FunnelEngine::class);
        $payload = ['kind' => 'funnel', 'steps' => [['type' => 'path', 'value' => '/'], ['type' => 'event', 'value' => 'signup'], ['type' => 'event', 'value' => 'signup']], 'from' => '2026-10-24 00:00:00', 'to' => '2026-10-25 00:00:00', 'filters' => [], 'at' => '2026-10-26 12:00:00'];
        $state = [];
        $engine->consume($state, $this->events(['/', '/pricing', 'signup']), $payload);
        $engine->consume($state, $this->events(['/', 'signup', 'signup']), $payload);
        $report = $engine->finish($state, $payload);
        $this->assertSame([2, 2, 1], array_column($report['steps'], 'count'));
        $this->assertSame(1, $report['conversions']);
        $this->assertSame(1, $report['steps'][1]['abandoned']);
    }

    public function test_open_sessions_are_pending_instead_of_abandoned(): void
    {
        $engine = app(FunnelEngine::class);
        $payload = ['kind' => 'funnel', 'steps' => [['type' => 'path', 'value' => '/'], ['type' => 'event', 'value' => 'signup']], 'from' => '2026-10-24 00:00:00', 'to' => '2026-10-25 00:00:00', 'filters' => [], 'at' => '2026-10-24 10:10:00'];
        $state = [];
        $engine->consume($state, $this->events(['/']), $payload);
        $report = $engine->finish($state, $payload);
        $this->assertSame(0, $report['steps'][0]['abandoned']);
        $this->assertSame(1, $report['steps'][0]['pending']);
    }

    public function test_journeys_group_branches_without_losing_flow_or_creating_cycles(): void
    {
        $engine = app(FunnelEngine::class);
        $payload = ['kind' => 'journey', 'start' => ['type' => 'path', 'value' => '/'], 'from' => '2026-10-24 00:00:00', 'to' => '2026-10-25 00:00:00', 'filters' => [], 'at' => '2026-10-26 12:00:00'];
        $state = [];
        foreach (range(1, 7) as $i) {
            $engine->consume($state, $this->events(['/', '/p'.$i, '/']), $payload);
        }
        $report = $engine->finish($state, $payload);
        $this->assertSame(7, $report['entries']);
        $this->assertSame(7, array_sum(array_column(array_filter($report['links'], fn ($link) => $link['source'] === 'root'), 'value')));
        $this->assertContains('Other', array_column($report['nodes'], 'label'));
        foreach ($report['links'] as $link) {
            $this->assertNotSame($link['source'], $link['target']);
        }
    }

    public function test_wrong_order_does_not_convert_and_a_later_day_can_finish_the_same_session(): void
    {
        $engine = app(FunnelEngine::class);
        $payload = ['kind' => 'funnel', 'steps' => [['type' => 'path', 'value' => '/'], ['type' => 'event', 'value' => 'signup']], 'from' => '2026-10-24 00:00:00', 'to' => '2026-10-25 00:00:00', 'filters' => [], 'at' => '2026-10-26 12:00:00'];
        $state = [];
        $engine->consume($state, $this->events(['signup', '/']), $payload);
        $crossing = $this->events(['/', 'signup']);
        $crossing[0]['received_at'] = '2026-10-24 23:59:00';
        $crossing[1]['received_at'] = '2026-10-25 00:02:00';
        $engine->consume($state, $crossing, $payload);
        $result = $engine->finish($state, $payload);
        $this->assertSame(2, $result['entries']);
        $this->assertSame(1, $result['conversions']);
        $this->assertSame(1, $result['steps'][0]['abandoned']);
    }
}
