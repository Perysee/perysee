<?php

namespace App\Analytics\Ingestion;

use App\Analytics\InstanceHealth;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The optional `value`, `props` and `id` of a named event, kept apart from the collector so that its own rules stay
 * in one place. An event without any of them costs nothing here.
 */
class EventValues
{
    public const KEYS = ['value', 'props', 'id'];

    public const MAX_VALUE = 1000000000;

    /**
     * Removes the optional fields from the payload and returns them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function take(array &$data): array
    {
        $extra = array_intersect_key($data, array_flip(self::KEYS));
        $data = array_diff_key($data, $extra);

        return $extra;
    }

    /**
     * Validates the fields and turns them into event columns. A structurally invalid field refuses the whole
     * request; a property that breaks a soft limit is dropped or masked and reported in `notes`.
     *
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>  $data  The validated payload.
     * @return array{columns: array<string, int|string|null>, order: ?string, notes: list<string>, dropped: int}
     */
    public function prepare(array $extra, array $data): array
    {
        $prepared = ['columns' => [], 'order' => null, 'notes' => [], 'dropped' => 0];
        $extra = array_filter($extra, fn (mixed $value): bool => $value !== null);
        if ($extra === []) {
            return $prepared;
        }
        $errors = [];
        if ($data['type'] !== 'event') {
            $errors = array_fill_keys(array_keys($extra), 'Only a named event can carry a value, properties or an identifier.');
        }
        if (array_key_exists('value', $extra) && ! isset($errors['value'])) {
            $value = $extra['value'];
            if ((! is_int($value) && ! is_float($value)) || ! is_finite($value) || $value < 0 || $value > self::MAX_VALUE) {
                $errors['value'] = 'The value must be a number between 0 and '.self::MAX_VALUE.'.';
            } else {
                $prepared['columns']['event_value'] = (int) round($value * 1000);
            }
        }
        if (array_key_exists('props', $extra) && ! isset($errors['props'])) {
            if (! is_array($extra['props']) || ($extra['props'] !== [] && array_is_list($extra['props']))) {
                $errors['props'] = 'The properties must be an object of short text values.';
            } else {
                $result = app(PropertyPolicy::class)->normalize($extra['props']);
                $prepared['columns']['event_props'] = $result['json'];
                $prepared['dropped'] = $result['dropped'];
                $prepared['notes'] = $result['reasons'];
            }
        }
        if (array_key_exists('id', $extra) && ! isset($errors['id'])) {
            if (! is_string($extra['id']) || preg_match('/^[\x21-\x7e]{1,64}$/D', $extra['id']) !== 1) {
                $errors['id'] = 'The identifier must be 1 to 64 printable characters without spaces.';
            } else {
                $prepared['order'] = $extra['id'];
            }
        }
        if ($errors !== []) {
            app(InstanceHealth::class)->failure('collection_rejections');

            throw ValidationException::withMessages($errors);
        }

        return $prepared;
    }

    /**
     * Records the order identifier of an event. It returns false when the site already has an event with the same
     * name and identifier, which then must not be stored again. Events without identifier cost no query.
     *
     * @param  array{order: ?string}  $prepared
     */
    public function claim(array $prepared, Website $site, string $eventName, string $at): bool
    {
        if ($prepared['order'] === null) {
            return true;
        }
        $hash = substr(hash_hmac('sha256', $site->public_id.':order:'.$eventName.':'.$prepared['order'], config('app.key')), 0, 32);

        return DB::table('event_order_ids')->insertOrIgnore(['website_id' => $site->id, 'order_hash' => $hash, 'created_at' => $at]) > 0;
    }

    /**
     * The `X-Perysee-Truncated` header, or null when nothing was dropped or masked.
     *
     * @param  array{notes: list<string>, dropped: int}  $prepared
     */
    public function header(array $prepared): ?string
    {
        return $prepared['dropped'] > 0 ? 'props='.$prepared['dropped'].';reason='.implode(',', $prepared['notes']) : null;
    }
}
