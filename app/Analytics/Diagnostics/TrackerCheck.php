<?php

namespace App\Analytics\Diagnostics;

use App\Analytics\Ingestion\RejectionCause;
use App\Models\Website;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Answers "why does nothing show up for this site?" from what the collector already keeps: the newest event,
 * the volume of the last day and the aggregated rejections. Reading costs three indexed queries and nothing
 * is written on the way in.
 */
class TrackerCheck
{
    /** Counting stops here: an exact figure is not worth scanning a busy site. */
    public const COUNT_CAP = 10000;

    /** Order of importance when several causes are present. */
    private const PRIORITY = ['domain_not_allowed', 'unknown_site', 'consent_missing', 'mode_mismatch', 'invalid_payload', 'quota', 'path_excluded'];

    /** @return array<string, mixed> */
    public function for(Website $site): array
    {
        $now = now('UTC');
        $silentDays = max(1, (int) config('perysee.silent_site_days', 3));
        $last = DB::table('events')->where('website_id', $site->id)->orderByDesc('received_at')->first(['received_at', 'path', 'event_type']);
        $lastAt = $last ? Carbon::parse($last->received_at, 'UTC') : null;
        $count = (int) DB::query()->fromSub(DB::table('events')->selectRaw('1')->where('website_id', $site->id)->where('received_at', '>=', $now->copy()->subDay()->format('Y-m-d H:i:s.v'))->limit(self::COUNT_CAP + 1), 'recent')->count();
        $state = match (true) {
            $lastAt === null && ! $site->first_collected_at => 'waiting',
            $lastAt === null || $lastAt->lt($now->copy()->subDays($silentDays)) => 'silent',
            default => 'receiving',
        };
        $rejections = $this->rejections($site, $now);
        $probe = $this->probe($site, $now);
        $check = [
            'state' => $state, 'silent_days' => $silentDays,
            'last_event' => $last ? ['at' => $lastAt->toIso8601String(), 'path' => $last->path, 'type' => (int) $last->event_type === 2 ? 'event' : 'pageview'] : null,
            'first_collected_at' => $site->first_collected_at?->copy()->utc()->toIso8601String(),
            'events_24h' => ['count' => min($count, self::COUNT_CAP), 'capped' => $count > self::COUNT_CAP],
            'rejections' => $rejections, 'probe' => $probe, 'checked_at' => $now->toIso8601String(),
        ];
        $check['diagnoses'] = $this->diagnose($site, $check, $lastAt, $probe);

        return $check;
    }

    /** @return list<array{cause: string, hits: ?int, host: ?string, last_at: string, approximate: bool}> */
    private function rejections(Website $site, Carbon $now): array
    {
        $since = $now->copy()->subDay()->startOfHour()->format('Y-m-d H:i:s');
        $rows = DB::table('collection_diagnostics')->where('bucket_start', '>=', $since)->whereIn('website_id', [$site->id, 0])->whereIn('cause', array_map(fn (RejectionCause $cause): string => $cause->value, RejectionCause::rejections()))
            ->orderBy('bucket_start')->get(['website_id', 'cause', 'hits', 'sample_host', 'last_at']);
        $grouped = [];
        foreach ($rows as $row) {
            // An instance-wide row is a hint, not a fact about this site: it is shown only when its (forgeable) host
            // is one of this site's own domains, it is marked approximate and its count (instance-wide) is withheld.
            $instanceWide = (int) $row->website_id === 0;
            if ($instanceWide && ! ($row->cause === 'unknown_site' && $row->sample_host !== null && in_array($row->sample_host, $site->allowed_domains ?? [], true))) {
                continue;
            }
            $entry = $grouped[$row->cause] ?? ['cause' => $row->cause, 'hits' => null, 'host' => null, 'last_at' => null, 'approximate' => true];
            if (! $instanceWide) {
                $entry['hits'] = (int) ($entry['hits'] ?? 0) + (int) $row->hits;
                $entry['approximate'] = false;
            }
            $entry['host'] = $row->sample_host ?? $entry['host'];
            $entry['last_at'] = max((string) $entry['last_at'], Carbon::parse($row->last_at, 'UTC')->toIso8601String());
            $grouped[$row->cause] = $entry;
        }
        usort($grouped, fn (array $a, array $b): int => array_search($a['cause'], self::PRIORITY, true) <=> array_search($b['cause'], self::PRIORITY, true));

        return array_values($grouped);
    }

    /** @return array{at: string}|null */
    private function probe(Website $site, Carbon $now): ?array
    {
        $at = DB::table('collection_diagnostics')->where('website_id', $site->id)->where('cause', RejectionCause::ProbeOk->value)->where('bucket_start', '>=', $now->copy()->subDay()->startOfHour()->format('Y-m-d H:i:s'))->max('last_at');

        return $at ? ['at' => Carbon::parse($at, 'UTC')->toIso8601String()] : null;
    }

    /**
     * @param  array<string, mixed>  $check
     * @param  array{at: string}|null  $probe
     * @return list<array{code: string, level: string, title: string, message: string, action: ?string, hits: ?int, host: ?string}>
     */
    private function diagnose(Website $site, array $check, ?Carbon $lastAt, ?array $probe): array
    {
        $texts = self::texts();
        $out = [];
        $receiving = $check['state'] === 'receiving';
        foreach ($check['rejections'] as $rejection) {
            $text = $texts[$rejection['cause']];
            // Refusals next to accepted events are a nuisance to look at; with none accepted they are the answer.
            $level = $rejection['cause'] === 'path_excluded' || $rejection['approximate'] ? 'info' : ($receiving ? 'warning' : 'error');
            $host = $rejection['host'];
            $out[] = ['code' => $rejection['cause'], 'level' => $level, 'title' => $text[0], 'message' => $this->fill($text[1], $host), 'action' => $this->fill($text[2], $host), 'hits' => $rejection['hits'], 'host' => $host];
        }
        $probeIsNewer = $probe !== null && ($lastAt === null || Carbon::parse($probe['at'])->gt($lastAt));
        if ($check['state'] === 'waiting' && ! $out) {
            $key = $probeIsNewer ? 'probe_only' : ($site->tracking_mode === 'sessions' ? 'waiting_sessions' : 'waiting');
            $out[] = $this->plain($key, $texts[$key], $probeIsNewer ? 'warning' : 'error');
        } elseif ($check['state'] === 'silent' && ! $out) {
            $text = $texts[$probeIsNewer ? 'probe_only' : 'silent'];
            $out[] = $this->plain($probeIsNewer ? 'probe_only' : 'silent', array_map(fn (string $part): string => str_replace('{days}', (string) $check['silent_days'], $part), $text), 'error');
        } elseif ($receiving && ! $out) {
            $out[] = $this->plain('receiving', $texts['receiving'], 'success');
        }

        return $out;
    }

    /** @param array{0: string, 1: string, 2: ?string} $text */
    private function plain(string $code, array $text, string $level): array
    {
        return ['code' => $code, 'level' => $level, 'title' => $text[0], 'message' => $text[1], 'action' => $text[2], 'hits' => null, 'host' => null];
    }

    private function fill(?string $text, ?string $host): ?string
    {
        return $text === null ? null : str_replace('{host}', $host ?? 'an unknown domain', $text);
    }

    /** @return array<string, array{0: string, 1: string, 2: ?string}> title, explanation, action */
    private static function texts(): array
    {
        return [
            'domain_not_allowed' => ['Events come from a domain that is not allowed', 'Events arrived from "{host}", which is not in the allowed domains of this site.', 'Add exactly "{host}" to the allowed domains in the site settings. "www" and the bare domain are two different domains.'],
            'unknown_site' => ['Events carry an unknown site key', 'Events arrived with a site key that does not exist here, from "{host}".', 'Copy the exact data-site value of the snippet shown on the tracking setup page, and check that the page does not still carry the key of a deleted site.'],
            'consent_missing' => ['The script sends events without consent', 'This site requires consent, but the script sent events before consent was given.', 'Make data-consent-required in the snippet match the site setting, and call window.lueur.push([\'consent\', true]) once the visitor accepts.'],
            'mode_mismatch' => ['The script announces another measurement mode', 'The script announced a measurement mode different from the one set for this site.', 'Update data-mode in the snippet on your page to match the site setting, then clear the page cache: the page still serves the old snippet.'],
            'invalid_payload' => ['Some sends are not valid', 'Some sends were refused because of their size, format or fields.', 'Reinstall the exact snippet shown on the tracking setup page. Avoid editing it by hand, and clear any cached copy of an older script.'],
            'quota' => ['The request limit was reached', 'The limit of 120 requests per minute for one address was reached, and the events beyond it were not stored.', 'If this is not a legitimate burst, check that no script loops. Otherwise nothing needs to change.'],
            'path_excluded' => ['Some pages are excluded by your rules', 'Pages matching the excluded paths of this site were received and ignored on purpose.', 'Information only. Review the excluded paths in the site settings if this is not intended.'],
            'waiting' => ['Nothing has reached the collector yet', 'No event and no refusal has been received for this site. The script is probably not loaded, or something blocks it before it can send.', 'Check that the snippet is in the head of the published page, clear the page and CDN caches, then use "Test now". An ad blocker, or a Do Not Track or Global Privacy Control setting, also stops the script.'],
            'waiting_sessions' => ['Nothing has reached the collector yet', 'This site measures sessions, which needs a cookie: when the browser refuses it, nothing is sent. No event and no refusal has been received yet.', 'Accept cookies for this site while testing, or switch the site to pageviews without a cookie in the site settings. Also check that the snippet is on the published page and caches are cleared.'],
            'probe_only' => ['The collector answers, your site does not', 'The test reached the collector, but no real event has arrived since.', 'The collector and the database work. Check the domain, the page cache, the consent banner and ad blockers on the real page, then browse it and reload this page.'],
            'silent' => ['No event for {days} days', 'This site used to send events, but nothing has arrived for {days} days and nothing was refused.', 'Check that the snippet is still on the published pages (a theme or template update can remove it), and that the site is still online.'],
            'receiving' => ['Events are arriving', 'Events from this site are being received and stored.', null],
        ];
    }
}
