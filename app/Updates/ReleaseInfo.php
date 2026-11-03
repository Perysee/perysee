<?php

declare(strict_types=1);

namespace App\Updates;

/**
 * A published release as the update check sees it: where its archive and its checksums are.
 */
final readonly class ReleaseInfo
{
    public function __construct(
        public string $tag,
        public string $archiveUrl,
        public string $checksumsUrl,
        public ?string $publishedAt = null,
        public ?string $notesUrl = null,
    ) {}

    public function version(): string
    {
        return Version::normalize($this->tag);
    }

    /**
     * @return array{tag: string, archive_url: string, checksums_url: string, published_at: ?string, notes_url: ?string}
     */
    public function toArray(): array
    {
        return ['tag' => $this->tag, 'archive_url' => $this->archiveUrl, 'checksums_url' => $this->checksumsUrl, 'published_at' => $this->publishedAt, 'notes_url' => $this->notesUrl];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['tag'], (string) $data['archive_url'], (string) $data['checksums_url'], $data['published_at'] ?? null, $data['notes_url'] ?? null);
    }
}
