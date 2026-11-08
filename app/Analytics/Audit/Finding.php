<?php

declare(strict_types=1);

namespace App\Analytics\Audit;

/**
 * What one check read and what it makes of it: the value as found, the threshold of the profile it was compared to,
 * and a plain explanation. The text is built with `__()` when the check runs, so it follows the locale of the request.
 * The `slug` is the anchor of the check in the public documentation. A finding states facts about a configuration;
 * it never says that an instance is, or is not, compliant with anything.
 */
final class Finding
{
    public const OK = 'ok';

    public const ATTENTION = 'attention';

    public const PROBLEM = 'problem';

    /** The check could not read what it needed: nothing is said about the setting. */
    public const UNAVAILABLE = 'unavailable';

    public const STATUSES = [self::OK, self::ATTENTION, self::PROBLEM, self::UNAVAILABLE];

    public readonly string $slug;

    /**
     * @param  string|null  $threshold  What the active profile compares the value to, null when the profile sets none.
     * @param  string|null  $settingUrl  Where the setting is changed (a path); the audit itself changes nothing.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $status,
        public readonly string $value,
        public readonly string $explanation,
        public readonly ?string $threshold = null,
        public readonly ?string $settingUrl = null,
        ?string $slug = null,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Unknown finding status \"{$status}\".");
        }
        $this->slug = $slug ?? str_replace('.', '-', $id);
    }

    public static function unavailable(string $id): self
    {
        return new self($id, $id, self::UNAVAILABLE, __('unavailable'), __('This check could not run on this instance. The page and the other checks are not affected.'));
    }

    /** The category of the check: the part of its identifier before the dot. */
    public function category(): string
    {
        return explode('.', $this->id, 2)[0];
    }

    /**
     * @return array{id: string, category: string, title: string, status: string, value: string, threshold: ?string, explanation: string, slug: string, setting_url: ?string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'category' => $this->category(), 'title' => $this->title, 'status' => $this->status, 'value' => $this->value, 'threshold' => $this->threshold, 'explanation' => $this->explanation, 'slug' => $this->slug, 'setting_url' => $this->settingUrl];
    }
}
