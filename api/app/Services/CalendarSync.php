<?php

namespace App\Services;

use App\Models\CalendarSource;
use App\Models\ExternalEvent;
use App\Support\IcsCalendar;
use App\Support\UserTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Imports Outlook / Google / iCloud calendars from their private ICS links. */
class CalendarSync
{
    public const REFRESH_MINUTES = 30;

    /** Normalise webcal:// links and reject anything that isn't a public https URL. */
    public static function normaliseUrl(string $url): ?string
    {
        $url = trim($url);
        if (str_starts_with(strtolower($url), 'webcal://')) $url = 'https://'.substr($url, 9);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with(strtolower($url), 'https://')) return null;
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host || in_array(strtolower($host), ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) return null;
        // Block private / link-local networks so the server can't be used to probe internal hosts.
        foreach ((array) @gethostbynamel($host) as $ip) {
            if ($ip && ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return null;
        }
        return $url;
    }

    public function sync(CalendarSource $source): array
    {
        $user = $source->user;
        $zone = UserTime::zone($user);
        try {
            $url = self::normaliseUrl((string) $source->url);
            if (! $url) throw new \RuntimeException('The calendar link must be a public https:// or webcal:// address.');
            $response = Http::withHeaders(['Accept' => 'text/calendar, */*'])->timeout(20)->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])->get($url);
            if (! $response->successful()) throw new \RuntimeException('The calendar link returned HTTP '.$response->status().'. Check that it is still published.');
            $body = $response->body();
            if (strlen($body) > 8_000_000) throw new \RuntimeException('This calendar is too large to import.');
            if (! str_contains($body, 'BEGIN:VCALENDAR')) throw new \RuntimeException('That link is not a calendar (.ics) file. Use the ICS / iCal link from Outlook or Google Calendar.');

            $events = IcsCalendar::parse($body, CarbonImmutable::now()->subDays(31), CarbonImmutable::now()->addDays(180), $zone);
            DB::transaction(function () use ($source, $events) {
                ExternalEvent::where('calendar_source_id', $source->id)->delete();
                foreach (array_chunk($events, 300) as $chunk) {
                    ExternalEvent::insert(array_map(fn ($e) => [
                        'calendar_source_id' => $source->id, 'user_id' => $source->user_id,
                        // Keep a readable marker for our own feed so it never "clashes" with itself.
                        'uid_hash' => str_contains($e['uid'], '@kriyabot') ? substr('kriyabot-'.hash('sha256', $e['uid']), 0, 64) : hash('sha256', $e['uid']),
                        'title' => $e['title'], 'location' => $e['location'],
                        'starts_at' => $e['starts_at']->utc()->format('Y-m-d H:i:s'), 'ends_at' => $e['ends_at']->utc()->format('Y-m-d H:i:s'),
                        'all_day' => $e['all_day'], 'created_at' => now(), 'updated_at' => now(),
                    ], $chunk));
                }
            });
            $source->update(['last_synced_at' => now(), 'last_error' => null, 'events_count' => count($events)]);
            return ['ok' => true, 'events' => count($events)];
        } catch (\Throwable $e) {
            Log::warning('Kriyabot calendar import failed', ['source' => $source->id, 'error' => $e->getMessage()]);
            $source->update(['last_synced_at' => now(), 'last_error' => mb_substr($e->getMessage(), 0, 500)]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Called by the scheduler: refresh sources older than REFRESH_MINUTES. */
    public function syncDue(int $limit = 20): void
    {
        CalendarSource::with('user')->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', now()->subMinutes(self::REFRESH_MINUTES)))
            ->orderBy('last_synced_at')->limit($limit)->get()->each(fn (CalendarSource $s) => $s->user ? $this->sync($s) : null);
    }
}
