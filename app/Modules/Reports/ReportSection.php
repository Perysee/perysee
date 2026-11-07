<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Modules\ModuleApi;

/**
 * A titled list of label/value lines of plain text. The values are reduced on construction (control characters
 * removed, lengths and line count capped), so a module can report figures but never inject markup or flood a report.
 *
 * @api Part of module API 1, available from the Perysee release named by {@see ModuleApi::DOMAIN_EVENTS_SINCE}.
 */
final readonly class ReportSection
{
    public const TITLE_LIMIT = 80;

    public const LABEL_LIMIT = 60;

    public const VALUE_LIMIT = 200;

    public const ROW_LIMIT = 20;

    public string $title;

    /** @var list<array{label: string, value: string}> */
    public array $rows;

    /**
     * @param  array<array-key, array{label?: mixed, value?: mixed}>  $rows
     */
    public function __construct(string $title, array $rows)
    {
        $this->title = self::plain($title, self::TITLE_LIMIT);
        $clean = [];
        foreach ($rows as $row) {
            $label = is_array($row) ? ($row['label'] ?? null) : null;
            $value = is_array($row) ? ($row['value'] ?? null) : null;
            if (! is_string($label) || ! (is_string($value) || is_int($value) || is_float($value))) {
                continue;
            }
            $clean[] = ['label' => self::plain($label, self::LABEL_LIMIT), 'value' => self::plain((string) $value, self::VALUE_LIMIT)];
            if (count($clean) === self::ROW_LIMIT) {
                break;
            }
        }
        $this->rows = $clean;
    }

    private static function plain(string $text, int $limit): string
    {
        return trim(mb_substr(preg_replace('/\p{Cc}+/u', ' ', $text) ?? '', 0, $limit));
    }
}
