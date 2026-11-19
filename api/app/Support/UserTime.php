<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Kriyabot stores every timestamp in UTC but people think in their own local time
 * (India Standard Time by default). These helpers keep the two straight.
 */
class UserTime
{
    public const DEFAULT = 'Asia/Kolkata';

    public static function zone(?User $user): string
    {
        $zone = $user?->timezone;
        if (! $zone || $zone === 'UTC' || in_array($zone, ['Asia/Calcutta', 'Asia/Kolkatta'], true)) return self::DEFAULT;
        return in_array($zone, timezone_identifiers_list(), true) ? $zone : self::DEFAULT;
    }

    /**
     * Parse a date-time from a form. Values that carry their own offset ("…+05:30" or "…Z") are
     * respected; bare values such as "2026-11-19T10:00" are read as the user's local time.
     */
    public static function parse(mixed $value, ?User $user): ?Carbon
    {
        if ($value === null || $value === '') return null;
        $value = (string) $value;
        $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', $value);
        return ($hasOffset ? Carbon::parse($value) : Carbon::parse($value, self::zone($user)))->utc();
    }

    /** Apply parse() to the given keys of a validated payload. */
    public static function normalise(array $data, array $keys, ?User $user): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) $data[$key] = self::parse($data[$key], $user);
        }
        return $data;
    }
}
