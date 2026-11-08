<?php

namespace Tests\Unit;

use App\Modules\Contracts\DomainEvent;
use App\Modules\Events\DomainEvents;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The public contract of the events. Removing or retyping a field of a schema breaks modules: this snapshot must
 * only ever grow, and a change of an existing field needs a new schema version and a note in the release.
 */
class DomainEventCatalogTest extends TestCase
{
    private const SNAPSHOT = [
        'goal.converted' => ['schema' => 1, 'payload' => ['site_id' => 'int', 'goal_id' => 'int', 'version' => 'int', 'conversions' => 'int', 'from' => 'string', 'to' => 'string']],
        'aggregation.completed' => ['schema' => 1, 'payload' => ['processed' => 'int', 'duration_ms' => 'int', 'at' => 'string']],
        'scheduler.heartbeat_missed' => ['schema' => 1, 'payload' => ['silent_minutes' => 'int', 'last_heartbeat_at' => 'string', 'recovered' => 'bool']],
        'ingest.backlog_exceeded' => ['schema' => 1, 'payload' => ['pending' => 'int', 'oldest_minutes' => 'int', 'threshold_minutes' => 'int']],
        'team.invitation_accepted' => ['schema' => 1, 'payload' => ['site_id' => 'int', 'role' => 'string', 'new_user' => 'bool', 'member_id' => 'int']],
        'share.created' => ['schema' => 1, 'payload' => ['site_id' => 'int', 'link_id' => 'int', 'sections' => 'list', 'protected' => 'bool', 'expires_at' => '?string']],
        'share.revoked' => ['schema' => 1, 'payload' => ['site_id' => 'int', 'link_id' => 'int']],
        'module.quarantined' => ['schema' => 1, 'payload' => ['module_id' => 'string', 'stability' => 'string', 'dependents' => 'list', 'at' => 'string']],
        'ingest.storage_budget_exceeded' => ['schema' => 1, 'payload' => ['tier' => 'int', 'ratio_percent' => 'int', 'days_left' => '?int']],
        'continuity.break_recorded' => ['schema' => 1, 'payload' => ['site_id' => '?int', 'kind' => 'string', 'scope' => 'string', 'data_from' => '?string']],
    ];

    private const FORBIDDEN_WORDS = ['email', 'mail', 'ip', 'token', 'hint', 'secret', 'password', 'label', 'reason', 'message', 'path', 'name', 'session', 'key', 'url'];

    /** @return array<string, array{class-string<DomainEvent>}> */
    public static function events(): array
    {
        $events = [];
        foreach (DomainEvents::CATALOG as $class) {
            $events[$class] = [$class];
        }

        return $events;
    }

    public function test_the_catalog_matches_the_published_snapshot(): void
    {
        $names = array_map(fn (string $class): string => $class::NAME, DomainEvents::CATALOG);

        $this->assertEqualsCanonicalizing(array_keys(self::SNAPSHOT), $names, 'Adding or removing an event is a change of the public contract: update the snapshot, the docs and the release notes.');
    }

    /** @param class-string<DomainEvent> $class */
    #[DataProvider('events')]
    public function test_each_event_keeps_its_fields_and_types(string $class): void
    {
        $expected = self::SNAPSHOT[$class::NAME];
        $properties = [];
        foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
            $type = $parameter->getType();
            $this->assertInstanceOf(ReflectionNamedType::class, $type, "{$class}::\${$parameter->getName()} needs a plain type.");
            $name = $type->getName();
            $properties[$parameter->getName()] = ($type->allowsNull() ? '?' : '').($name === 'array' ? 'list' : $name);
        }

        $this->assertSame($expected['schema'], $class::SCHEMA);
        // Every constructor argument is a payload field, in the same order (snake_case); fields may be added at the end.
        $fields = array_combine(array_map(fn (string $name): string => strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name)), array_keys($properties)), array_values($properties));
        $this->assertSame($expected['payload'], array_slice($fields, 0, count($expected['payload'])), "{$class}: a field was removed, renamed or retyped.");
    }

    /** @param class-string<DomainEvent> $class */
    #[DataProvider('events')]
    public function test_the_payload_is_scalars_only_and_matches_the_declared_fields(string $class): void
    {
        $event = $this->sample($class);
        $payload = $event->payload();

        $this->assertSame($class::NAME, $event->name());
        $this->assertSame($class::SCHEMA, $event->schema());
        $this->assertSame(array_keys(self::SNAPSHOT[$class::NAME]['payload']), array_keys($payload));
        foreach ($payload as $field => $value) {
            $isList = is_array($value) && array_is_list($value) && array_filter($value, fn ($item): bool => ! is_scalar($item)) === [];
            $this->assertTrue($value === null || is_scalar($value) || $isList, "{$class}: \"{$field}\" is neither a scalar nor a list of scalars.");
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertNotFalse($json);
    }

    /** @param class-string<DomainEvent> $class */
    #[DataProvider('events')]
    public function test_no_field_can_hold_personal_data_or_a_secret(string $class): void
    {
        foreach (array_keys(self::SNAPSHOT[$class::NAME]['payload']) as $field) {
            $words = explode('_', $field);
            $this->assertSame([], array_intersect($words, self::FORBIDDEN_WORDS), "{$class}: \"{$field}\" looks like personal data, a secret or free text.");
        }
        foreach ((new ReflectionClass($class))->getProperties() as $property) {
            $type = $property->getType();
            $this->assertTrue($type instanceof ReflectionNamedType && in_array($type->getName(), ['int', 'string', 'bool', 'array'], true), "{$class}::\${$property->getName()} must be a scalar or a list.");
            $this->assertTrue($property->isReadOnly(), "{$class}::\${$property->getName()} must be readonly.");
        }
    }

    /**
     * @param  class-string<DomainEvent>  $class
     */
    private function sample(string $class): DomainEvent
    {
        $values = ['int' => 7, 'string' => '2026-11-07T10:00:00+00:00', 'bool' => true, 'array' => ['a', 'b']];
        $arguments = [];
        foreach ((new ReflectionClass($class))->getConstructor()->getParameters() as $parameter) {
            $arguments[] = $values[$parameter->getType()->getName()];
        }

        return new $class(...$arguments);
    }
}
