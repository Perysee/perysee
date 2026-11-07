<?php

namespace App\Support;

/**
 * Keeps a spreadsheet from running a text cell as a formula: text that starts, after any blanks, with = + - @ or
 * with a tab or a carriage return is prefixed with an apostrophe. Numbers and empty cells are left alone.
 */
final class CsvSafe
{
    public static function cell(mixed $value): mixed
    {
        if ($value === null || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }
        $text = (string) $value;

        return preg_match('/^[\x00-\x20]*[=+@\-]|^[\t\r\n]/', $text) === 1 ? "'".$text : $text;
    }

    /**
     * @param  array<int|string, mixed>  $row
     * @return list<mixed>
     */
    public static function row(array $row): array
    {
        return array_map(self::cell(...), array_values($row));
    }
}
