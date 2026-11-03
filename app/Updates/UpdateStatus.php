<?php

declare(strict_types=1);

namespace App\Updates;

use App\Analytics\InstanceHealth;

/**
 * What the update is doing, remembered in the database so the administration page and the command line agree.
 * A request made from the web page is only a note: the scheduler, which runs on the command line, acts on it.
 */
final class UpdateStatus
{
    public const STATE = 'update_status';

    public const REQUEST = 'update_request';

    public function __construct(private readonly InstanceHealth $health, private readonly ReleaseFeed $feed, private readonly Backup $backup) {}

    /**
     * @param  array<string, mixed>  $fields
     */
    public function put(string $state, array $fields = []): void
    {
        $this->health->put(self::STATE, array_merge((array) $this->health->get(self::STATE, []), $fields, ['state' => $state, 'at' => now('UTC')->toIso8601String()]));
    }

    /**
     * Starts a new run: nothing is kept from the previous one.
     *
     * @param  array<string, mixed>  $fields
     */
    public function begin(array $fields): void
    {
        $this->health->put(self::STATE, array_merge($fields, ['state' => 'running', 'at' => now('UTC')->toIso8601String()]));
    }

    /**
     * @return array<string, mixed>
     */
    public function current(): array
    {
        return (array) $this->health->get(self::STATE, []) + ['state' => 'idle'];
    }

    public function request(string $tag, string $dump, int $userId): void
    {
        $this->health->put(self::REQUEST, ['tag' => Version::tag($tag), 'dump' => $dump, 'user' => $userId, 'at' => now('UTC')->toIso8601String()]);
    }

    /**
     * @return ?array{tag: string, dump: string, user: int, at: string}
     */
    public function requested(): ?array
    {
        $request = $this->health->get(self::REQUEST);

        return is_array($request) ? $request : null;
    }

    public function clearRequest(): void
    {
        $this->health->put(self::REQUEST, null);
    }

    /**
     * Everything the administration page shows.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $check = $this->feed->stored();
        $available = $this->feed->available();

        return [
            'enabled' => $this->feed->enabled(),
            'current' => $this->feed->currentVersion(),
            'checked_at' => $check['checked_at'],
            'error' => $check['error'],
            'available' => $available?->toArray(),
            'requested' => $this->requested(),
            'status' => $this->current(),
            'methods' => array_map(fn ($method): array => ['name' => $method->name(), 'label' => $method->label(), 'available' => $method->available()], $this->backup->methods()),
            'rollback' => $this->backup->latestWithPreviousVersion() !== null,
        ];
    }
}
