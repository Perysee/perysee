<?php

declare(strict_types=1);

namespace Perysee\Modules\Annotations;

use App\Models\User;
use App\Models\Website;
use App\Modules\ModuleSettings;
use Illuminate\Support\Facades\DB;

class Notes
{
    public const MODULE = 'annotations';

    public const DEFAULT_RETENTION_DAYS = 730;

    public function __construct(private ModuleSettings $settings) {}

    public function retentionDays(): int
    {
        return (int) $this->settings->get(self::MODULE, 'retention_days', self::DEFAULT_RETENTION_DAYS);
    }

    /**
     * @return list<array{id: int, noted_on: string, body: string, author: string|null}>
     */
    public function forSite(Website $site, int $limit): array
    {
        return DB::table('annotations_notes')
            ->leftJoin('users', 'users.id', '=', 'annotations_notes.user_id')
            ->where('annotations_notes.website_id', $site->id)
            ->orderByDesc('noted_on')->orderByDesc('annotations_notes.id')
            ->limit($limit)
            ->get(['annotations_notes.id', 'annotations_notes.noted_on', 'annotations_notes.body', 'users.name as author'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'noted_on' => substr((string) $row->noted_on, 0, 10), 'body' => $row->body, 'author' => $row->author])
            ->all();
    }

    /**
     * What the interface receives on every page: the latest notes while a site is open, and the settings for administrators.
     *
     * @return array<string, mixed>
     */
    public function pageData(?User $user, mixed $site): array
    {
        $data = [];
        if ($site instanceof Website && $user && $site->members()->whereKey($user->id)->exists()) {
            $data['recent'] = $this->forSite($site, 3);
            $data['page'] = route('annotations.site', $site);
        }
        $data['settings'] = $user?->is_admin ? [
            'retention_days' => $this->retentionDays(),
            'links' => ['save' => route('annotations.settings')],
        ] : null;

        return $data;
    }
}
