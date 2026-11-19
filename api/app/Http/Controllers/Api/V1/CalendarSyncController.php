<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CalendarSource;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CalendarSync;
use App\Support\IcsCalendar;
use App\Support\UserTime;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Two-way calendar sync without OAuth:
 *  - out: a private ICS feed that phone calendars, Google Calendar and Outlook subscribe to;
 *  - in:  the person's own Outlook/Google/iCloud ICS links, re-imported every 30 minutes.
 */
class CalendarSyncController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        return response()->json([
            'feed_url' => $this->feedUrl($this->token($user)),
            'sources' => CalendarSource::where('user_id', $user->id)->orderBy('name')->get(['id', 'name', 'color', 'last_synced_at', 'last_error', 'events_count']),
            'refresh_minutes' => CalendarSync::REFRESH_MINUTES,
        ]);
    }

    public function rotate(Request $request, AuditLogger $audit)
    {
        $user = $request->user();
        $user->forceFill(['calendar_feed_token' => Str::random(48)])->save();
        $audit->record($request, 'calendar.feed_rotated', $user);
        return response()->json(['feed_url' => $this->feedUrl($user->calendar_feed_token)]);
    }

    public function storeSource(Request $request, CalendarSync $sync, AuditLogger $audit)
    {
        $user = $request->user();
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'url' => ['required', 'string', 'max:2000'], 'color' => ['nullable', 'string', 'max:16']]);
        $url = CalendarSync::normaliseUrl($data['url']);
        if (! $url) return response()->json(['message' => 'Paste the calendar’s ICS link (it starts with https:// or webcal:// and usually ends in .ics).'], 422);
        if (str_contains($url, '/calendar/feed/')) return response()->json(['message' => 'That is Kriyabot’s own feed. Add your Outlook or Google calendar link instead.'], 422);
        if (CalendarSource::where('user_id', $user->id)->count() >= 10) return response()->json(['message' => 'You can connect up to 10 calendars.'], 422);
        $source = CalendarSource::create(['workspace_id' => $user->workspace_id, 'user_id' => $user->id, 'name' => $data['name'], 'url' => $url, 'color' => $data['color'] ?? null]);
        $result = $sync->sync($source->setRelation('user', $user));
        $audit->record($request, 'calendar.source_added', $source);
        if (! $result['ok']) { $source->delete(); return response()->json(['message' => $result['message']], 422); }
        return response()->json($source->fresh()->only(['id', 'name', 'color', 'last_synced_at', 'last_error', 'events_count']), 201);
    }

    public function refreshSource(Request $request, CalendarSource $source, CalendarSync $sync)
    {
        abort_unless($source->user_id === $request->user()->id, 404);
        $result = $sync->sync($source->setRelation('user', $request->user()));
        return response()->json([...$source->fresh()->only(['id', 'name', 'color', 'last_synced_at', 'last_error', 'events_count']), 'ok' => $result['ok']], $result['ok'] ? 200 : 422);
    }

    public function destroySource(Request $request, CalendarSource $source, AuditLogger $audit)
    {
        abort_unless($source->user_id === $request->user()->id, 404);
        $source->delete();
        $audit->record($request, 'calendar.source_removed', $source);
        return response()->noContent();
    }

    /** Public, token-protected ICS feed. */
    public function feed(string $token)
    {
        $token = preg_replace('/\.ics$/', '', $token);
        abort_if(strlen($token) < 32, 404);
        $user = User::where('calendar_feed_token', $token)->where('status', 'active')->first();
        abort_unless($user, 404);
        $zone = UserTime::zone($user);
        $app = rtrim((string) config('app.frontend_url'), '/');
        $events = [];
        Meeting::where('workspace_id', $user->workspace_id)->where('created_by', $user->id)->where('status', 'scheduled')
            ->whereBetween('starts_at', [now()->subDays(60), now()->addDays(365)])->orderBy('starts_at')->get()
            ->each(function (Meeting $m) use (&$events, $app) {
                $events[] = ['uid' => 'kriyabot-meeting-'.$m->id.'@kriyabot', 'title' => $m->title, 'starts_at' => $m->starts_at, 'ends_at' => $m->ends_at,
                    'location' => $m->location_type === 'online' ? ($m->online_url ?: 'Online') : $m->location_label,
                    'description' => trim(($m->attendee_name ? 'With '.$m->attendee_name."\n" : '').($m->description ?? '')), 'url' => $app ? $app.'/app/calendar?date='.$m->starts_at->toDateString() : null, 'alarm_minutes' => 15];
            });
        Task::where('workspace_id', $user->workspace_id)->where(fn ($q) => $q->where('created_by', $user->id)->orWhereHas('assignees', fn ($a) => $a->where('users.id', $user->id)))
            ->whereNotIn('status', ['done', 'cancelled'])->whereNotNull('due_at')->whereBetween('due_at', [now()->subDays(30), now()->addDays(365)])->get()
            ->each(function (Task $t) use (&$events, $app) {
                $events[] = ['uid' => 'kriyabot-task-'.$t->id.'@kriyabot', 'title' => 'Due: '.$t->title, 'starts_at' => $t->due_at->copy()->subMinutes(30), 'ends_at' => $t->due_at,
                    'description' => $t->description, 'url' => $app ? $app.'/app/tasks?task='.$t->id : null, 'alarm_minutes' => 30];
            });
        return response(IcsCalendar::build('Kriyabot · '.$user->name, $events), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="kriyabot.ics"',
            'Cache-Control' => 'private, max-age=900',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    private function token(User $user): string
    {
        if (! $user->calendar_feed_token) $user->forceFill(['calendar_feed_token' => Str::random(48)])->save();
        return $user->calendar_feed_token;
    }

    private function feedUrl(string $token): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v1/calendar/feed/'.$token.'.ics';
    }
}
