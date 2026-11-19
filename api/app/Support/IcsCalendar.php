<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Minimal, dependency-free iCalendar (RFC 5545) reader and writer.
 *
 * Reads what Outlook, Google Calendar and iCloud publish: timed and all-day events, TZID
 * (including Windows zone names such as "India Standard Time"), DURATION, cancelled events,
 * common RRULEs (DAILY/WEEKLY/MONTHLY/YEARLY with INTERVAL, COUNT, UNTIL, BYDAY),
 * EXDATE and RECURRENCE-ID overrides.
 */
class IcsCalendar
{
    /** Windows timezone names used by Outlook/Exchange → IANA. */
    private const WINDOWS_ZONES = [
        'india standard time' => 'Asia/Kolkata', 'sri lanka standard time' => 'Asia/Colombo', 'nepal standard time' => 'Asia/Kathmandu',
        'pakistan standard time' => 'Asia/Karachi', 'bangladesh standard time' => 'Asia/Dhaka', 'arabian standard time' => 'Asia/Dubai',
        'arab standard time' => 'Asia/Riyadh', 'singapore standard time' => 'Asia/Singapore', 'china standard time' => 'Asia/Shanghai',
        'tokyo standard time' => 'Asia/Tokyo', 'aus eastern standard time' => 'Australia/Sydney', 'gmt standard time' => 'Europe/London',
        'greenwich standard time' => 'Atlantic/Reykjavik', 'w. europe standard time' => 'Europe/Berlin', 'romance standard time' => 'Europe/Paris',
        'central europe standard time' => 'Europe/Budapest', 'e. europe standard time' => 'Europe/Chisinau', 'russian standard time' => 'Europe/Moscow',
        'eastern standard time' => 'America/New_York', 'central standard time' => 'America/Chicago', 'mountain standard time' => 'America/Denver',
        'pacific standard time' => 'America/Los_Angeles', 'utc' => 'UTC', 'coordinated universal time' => 'UTC',
    ];
    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    /**
     * @return array<int, array{uid: string, title: string, location: ?string, starts_at: CarbonImmutable, ends_at: CarbonImmutable, all_day: bool}>
     */
    public static function parse(string $ics, CarbonImmutable $windowStart, CarbonImmutable $windowEnd, string $defaultZone = 'Asia/Kolkata'): array
    {
        $lines = preg_split("/\r\n|\n|\r/", preg_replace("/\r?\n[ \t]/", '', $ics));
        $events = []; $current = null;
        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') { $current = []; continue; }
            if ($line === 'END:VEVENT') { if ($current !== null) $events[] = $current; $current = null; continue; }
            if ($current === null || ! str_contains($line, ':')) continue;
            [$head, $value] = explode(':', $line, 2);
            $parts = explode(';', $head);
            $name = strtoupper(array_shift($parts));
            $params = [];
            foreach ($parts as $p) { if (str_contains($p, '=')) { [$k, $v] = explode('=', $p, 2); $params[strtoupper($k)] = trim($v, '"'); } }
            $current[$name][] = ['value' => $value, 'params' => $params];
        }

        $out = []; $overrides = [];
        // First pass: RECURRENCE-ID overrides replace single occurrences.
        foreach ($events as $e) {
            if (! isset($e['RECURRENCE-ID'])) continue;
            $uid = self::text($e, 'UID') ?? md5(json_encode($e));
            $rid = self::time($e['RECURRENCE-ID'][0], $defaultZone);
            if ($rid) $overrides[$uid][$rid['at']->getTimestamp()] = true;
            $item = self::single($e, $defaultZone);
            if ($item && $item['ends_at'] > $windowStart && $item['starts_at'] < $windowEnd) $out[] = $item;
        }
        foreach ($events as $e) {
            if (isset($e['RECURRENCE-ID'])) continue;
            $item = self::single($e, $defaultZone);
            if (! $item) continue;
            $rule = self::text($e, 'RRULE');
            if (! $rule) {
                if ($item['ends_at'] > $windowStart && $item['starts_at'] < $windowEnd) $out[] = $item;
                continue;
            }
            $excluded = $overrides[$item['uid']] ?? [];
            foreach ($e['EXDATE'] ?? [] as $ex) {
                foreach (explode(',', $ex['value']) as $v) {
                    $t = self::time(['value' => $v, 'params' => $ex['params']], $defaultZone);
                    if ($t) $excluded[$t['at']->getTimestamp()] = true;
                }
            }
            foreach (self::expand($item, $rule, $windowStart, $windowEnd) as $start) {
                if (isset($excluded[$start->getTimestamp()])) continue;
                $duration = $item['ends_at']->getTimestamp() - $item['starts_at']->getTimestamp();
                $out[] = [...$item, 'starts_at' => $start, 'ends_at' => $start->addSeconds($duration)];
            }
        }
        usort($out, fn ($a, $b) => $a['starts_at'] <=> $b['starts_at']);
        return $out;
    }

    private static function single(array $e, string $zone): ?array
    {
        if (strtoupper((string) self::text($e, 'STATUS')) === 'CANCELLED' || ! isset($e['DTSTART'])) return null;
        $start = self::time($e['DTSTART'][0], $zone);
        if (! $start) return null;
        $end = isset($e['DTEND']) ? self::time($e['DTEND'][0], $zone) : null;
        $endAt = $end['at'] ?? null;
        if (! $endAt && ($duration = self::text($e, 'DURATION'))) {
            try { $endAt = $start['at']->add(new \DateInterval(ltrim(strtoupper($duration), '+'))); } catch (\Throwable) {}
        }
        $endAt ??= $start['all_day'] ? $start['at']->addDay() : $start['at']->addHour();
        if ($endAt <= $start['at']) $endAt = $start['at']->addMinutes(30);
        return [
            'uid' => self::text($e, 'UID') ?? md5((self::text($e, 'SUMMARY') ?? '').$start['at']->toIso8601String()),
            'title' => mb_substr(self::unescape(self::text($e, 'SUMMARY') ?? 'Busy'), 0, 240) ?: 'Busy',
            'location' => ($loc = self::text($e, 'LOCATION')) ? mb_substr(self::unescape($loc), 0, 240) : null,
            'starts_at' => $start['at'], 'ends_at' => $endAt, 'all_day' => $start['all_day'],
        ];
    }

    /** @return CarbonImmutable[] occurrence start times inside the window */
    private static function expand(array $item, string $rule, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $r = [];
        foreach (explode(';', strtoupper($rule)) as $pair) { if (str_contains($pair, '=')) { [$k, $v] = explode('=', $pair, 2); $r[$k] = $v; } }
        $freq = $r['FREQ'] ?? null;
        if (! in_array($freq, ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) return $item['starts_at'] < $to && $item['ends_at'] > $from ? [$item['starts_at']] : [];
        $interval = max(1, (int) ($r['INTERVAL'] ?? 1));
        $count = isset($r['COUNT']) ? (int) $r['COUNT'] : null;
        $until = null;
        if (isset($r['UNTIL'])) { $u = self::time(['value' => $r['UNTIL'], 'params' => []], 'UTC'); $until = $u ? ($u['all_day'] ? $u['at']->endOfDay() : $u['at']) : null; }
        $start = $item['starts_at'];
        $byDay = $freq === 'WEEKLY' && isset($r['BYDAY']) ? array_values(array_filter(array_map(fn ($d) => self::DAYS[substr($d, -2)] ?? null, explode(',', $r['BYDAY'])))) : [];
        $result = []; $made = 0; $guard = 0;
        $cursor = $start;
        while ($guard++ < 2000) {
            $candidates = [$cursor];
            if ($byDay) {
                $weekStart = $cursor->startOfWeek(\Carbon\CarbonInterface::MONDAY); $candidates = [];
                foreach ($byDay as $dow) $candidates[] = $weekStart->addDays($dow - 1)->setTime((int) $start->format('H'), (int) $start->format('i'), (int) $start->format('s'));
                sort($candidates);
            }
            foreach ($candidates as $occ) {
                if ($occ < $start) continue;
                if ($until && $occ > $until) return $result;
                if ($count !== null && $made >= $count) return $result;
                $made++;
                if ($occ > $to) return $result;
                if ($occ->addSeconds($item['ends_at']->getTimestamp() - $start->getTimestamp()) > $from) $result[] = $occ;
            }
            $cursor = match ($freq) {
                'DAILY' => $cursor->addDays($interval),
                'WEEKLY' => $cursor->addWeeks($interval),
                'MONTHLY' => $cursor->addMonthsNoOverflow($interval),
                default => $cursor->addYears($interval),
            };
        }
        return $result;
    }

    /** @return array{at: CarbonImmutable, all_day: bool}|null */
    private static function time(array $prop, string $defaultZone): ?array
    {
        $v = trim($prop['value']);
        try {
            if (($prop['params']['VALUE'] ?? null) === 'DATE' || preg_match('/^\d{8}$/', $v)) {
                return ['at' => CarbonImmutable::createFromFormat('!Ymd', $v, self::zone($prop['params']['TZID'] ?? null, $defaultZone))->startOfDay(), 'all_day' => true];
            }
            if (str_ends_with($v, 'Z')) return ['at' => CarbonImmutable::createFromFormat('!Ymd\THis\Z', $v, 'UTC'), 'all_day' => false];
            $format = strlen($v) === 13 ? '!Ymd\THi' : '!Ymd\THis';
            return ['at' => CarbonImmutable::createFromFormat($format, $v, self::zone($prop['params']['TZID'] ?? null, $defaultZone)), 'all_day' => false];
        } catch (\Throwable) {
            return null;
        }
    }

    private static function zone(?string $tzid, string $default): string
    {
        if (! $tzid) return $default;
        $tzid = trim($tzid, '"/ ');
        if (in_array($tzid, timezone_identifiers_list(), true)) return $tzid;
        if ($tzid === 'Asia/Calcutta') return 'Asia/Kolkata';
        return self::WINDOWS_ZONES[strtolower($tzid)] ?? $default;
    }

    private static function text(array $e, string $name): ?string { return isset($e[$name][0]['value']) ? trim($e[$name][0]['value']) : null; }
    private static function unescape(string $v): string { return trim(str_replace(['\\n', '\\N', '\\,', '\\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $v)); }

    // ------------------------------------------------------------------ writer

    /** @param array<int, array{uid: string, title: string, starts_at: \DateTimeInterface, ends_at: \DateTimeInterface, description?: ?string, location?: ?string, url?: ?string, alarm_minutes?: ?int}> $events */
    public static function build(string $calendarName, array $events): string
    {
        $out = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Kriyabot//Calendar Feed//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::escape($calendarName), 'X-WR-TIMEZONE:Asia/Kolkata', 'REFRESH-INTERVAL;VALUE=DURATION:PT30M', 'X-PUBLISHED-TTL:PT30M'];
        $stamp = gmdate('Ymd\THis\Z');
        foreach ($events as $e) {
            $out[] = 'BEGIN:VEVENT';
            $out[] = 'UID:'.$e['uid'];
            $out[] = 'DTSTAMP:'.$stamp;
            $out[] = 'DTSTART:'.CarbonImmutable::instance($e['starts_at'])->utc()->format('Ymd\THis\Z');
            $out[] = 'DTEND:'.CarbonImmutable::instance($e['ends_at'])->utc()->format('Ymd\THis\Z');
            $out[] = 'SUMMARY:'.self::escape($e['title']);
            if (! empty($e['description'])) $out[] = 'DESCRIPTION:'.self::escape($e['description']);
            if (! empty($e['location'])) $out[] = 'LOCATION:'.self::escape($e['location']);
            if (! empty($e['url'])) $out[] = 'URL:'.$e['url'];
            if (isset($e['alarm_minutes']) && $e['alarm_minutes'] !== null) {
                array_push($out, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:'.self::escape($e['title']), 'TRIGGER:-PT'.max(0, (int) $e['alarm_minutes']).'M', 'END:VALARM');
            }
            $out[] = 'END:VEVENT';
        }
        $out[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $out))."\r\n";
    }

    private static function escape(string $v): string { return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $v); }

    /** Fold lines longer than 75 octets without splitting multibyte characters. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) return $line;
        $chunks = []; $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current.$char) > ($chunks ? 74 : 75)) { $chunks[] = $current; $current = ''; }
            $current .= $char;
        }
        $chunks[] = $current;
        return implode("\r\n ", $chunks);
    }
}
