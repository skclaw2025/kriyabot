<?php

namespace App\Services;

use App\Models\ExternalEvent;
use App\Models\FinanceEntry;
use App\Models\Meeting;
use App\Models\Task;
use App\Models\TaskflowNotification;
use App\Models\User;
use App\Support\UserTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * The morning check-in: today's plan, tomorrow's meetings (with clashes) and a nudge to log
 * today's income & expenses. Shown on Home and sent once each morning as a notification.
 */
class DailyBriefing
{
    public const DEFAULT_HOUR = 8;

    public function __construct(private ScheduleConflicts $conflicts) {}

    public function build(User $user): array
    {
        $zone = UserTime::zone($user);
        $now = now($zone);
        $todayStart = $now->copy()->startOfDay(); $todayEnd = $now->copy()->endOfDay();
        $tomorrowStart = $now->copy()->addDay()->startOfDay(); $tomorrowEnd = $now->copy()->addDay()->endOfDay();
        $fmt = fn (Carbon $t) => $t->copy()->timezone($zone)->format('g:i a');

        $meetingsFor = function (Carbon $from, Carbon $to) use ($user, $zone, $fmt) {
            $own = Meeting::where('workspace_id', $user->workspace_id)->where('created_by', $user->id)->where('status', 'scheduled')
                ->whereBetween('starts_at', [$from->copy()->utc(), $to->copy()->utc()])->orderBy('starts_at')->get()
                ->map(fn (Meeting $m) => ['id' => $m->id, 'kind' => 'meeting', 'title' => $m->title, 'starts_at' => $m->starts_at->copy()->timezone($zone)->toIso8601String(), 'ends_at' => $m->ends_at->copy()->timezone($zone)->toIso8601String(), 'time' => $fmt($m->starts_at), 'where' => $m->location_label ?: ($m->location_type === 'online' ? 'Online' : null), 'source' => 'Kriyabot']);
            $ext = ExternalEvent::where('user_id', $user->id)->whereBetween('starts_at', [$from->copy()->utc(), $to->copy()->utc()])
                ->where('uid_hash', 'not like', 'kriyabot-%')->with('source:id,name')->orderBy('starts_at')->get()
                ->map(fn (ExternalEvent $e) => ['id' => $e->id, 'kind' => 'external', 'title' => $e->title, 'starts_at' => $e->starts_at->copy()->timezone($zone)->toIso8601String(), 'ends_at' => $e->ends_at->copy()->timezone($zone)->toIso8601String(), 'time' => $e->all_day ? 'All day' : $fmt($e->starts_at), 'where' => $e->location, 'source' => $e->source?->name ?? 'Calendar']);
            return $own->concat($ext)->sortBy('starts_at')->values()->all();
        };

        $clashes = collect($this->conflicts->clashesBetween($user, $tomorrowStart->copy()->utc(), $tomorrowEnd->copy()->utc()))
            ->map(fn ($p) => ['a' => $p['a']['title'], 'b' => $p['b']['title'], 'at' => Carbon::createFromTimestamp(max($p['a']['start'], $p['b']['start']))->timezone($zone)->format('g:i a'), 'a_source' => $p['a']['source'], 'b_source' => $p['b']['source']])->all();
        $todayClashes = collect($this->conflicts->clashesBetween($user, $now->copy()->utc(), $todayEnd->copy()->utc()))
            ->map(fn ($p) => ['a' => $p['a']['title'], 'b' => $p['b']['title'], 'at' => Carbon::createFromTimestamp(max($p['a']['start'], $p['b']['start']))->timezone($zone)->format('g:i a')])->all();

        $taskScope = fn ($q) => $q->where('created_by', $user->id)->orWhereHas('assignees', fn ($a) => $a->where('users.id', $user->id));
        $dueToday = Task::where('workspace_id', $user->workspace_id)->where($taskScope)->whereNotIn('status', ['done', 'cancelled'])
            ->whereBetween('due_at', [$todayStart->copy()->utc(), $todayEnd->copy()->utc()])->count();
        $overdue = Task::where('workspace_id', $user->workspace_id)->where($taskScope)->whereNotIn('status', ['done', 'cancelled'])
            ->where('due_at', '<', $todayStart->copy()->utc())->count();
        $finance = FinanceEntry::where('user_id', $user->id)->whereDate('entry_date', $now->toDateString());

        return [
            'date' => $now->toDateString(),
            'greeting' => $now->hour < 12 ? 'Good morning' : ($now->hour < 17 ? 'Good afternoon' : 'Good evening'),
            'today' => ['meetings' => $meetingsFor($now->copy()->startOfDay(), $todayEnd), 'tasks_due' => $dueToday, 'overdue' => $overdue, 'clashes' => $todayClashes],
            'tomorrow' => ['date' => $tomorrowStart->toDateString(), 'meetings' => $meetingsFor($tomorrowStart, $tomorrowEnd), 'clashes' => $clashes],
            'finance_today' => ['entries' => (clone $finance)->count(), 'income' => round((float) (clone $finance)->where('type', 'income')->sum('amount'), 2), 'expense' => round((float) (clone $finance)->where('type', 'expense')->sum('amount'), 2)],
        ];
    }

    /** Send the morning check-in once per day, at the person's chosen hour (default 8 am local). */
    public function dispatchDue(): void
    {
        User::where('status', 'active')->where('approval_status', 'approved')->with('preferences')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $rules = $user->preferences?->notification_rules ?? [];
                if (($rules['morning_briefing'] ?? true) === false) continue;
                $zone = UserTime::zone($user);
                $now = now($zone);
                $hour = (int) ($rules['briefing_hour'] ?? self::DEFAULT_HOUR);
                if ($now->hour < $hour || $now->hour >= $hour + 4) continue; // only in the morning window
                $sent = TaskflowNotification::where('user_id', $user->id)->where('type', 'daily_briefing')->where('created_at', '>=', $now->copy()->startOfDay()->utc())->exists();
                if ($sent) continue;
                $this->send($user, $this->build($user));
            }
        });
    }

    private function send(User $user, array $b): void
    {
        $parts = [];
        $tm = count($b['today']['meetings']);
        $parts[] = 'Today: '.($tm ? $tm.' meeting'.($tm > 1 ? 's' : '') : 'no meetings').', '.$b['today']['tasks_due'].' task'.($b['today']['tasks_due'] === 1 ? '' : 's').' due'.($b['today']['overdue'] ? ', '.$b['today']['overdue'].' overdue' : '').'.';
        $tomorrow = $b['tomorrow']['meetings'];
        $parts[] = $tomorrow
            ? 'Tomorrow: '.collect($tomorrow)->take(3)->map(fn ($m) => $m['time'].' '.$m['title'])->implode('; ').(count($tomorrow) > 3 ? ' +'.(count($tomorrow) - 3).' more' : '').'.'
            : 'Tomorrow: no meetings yet — add any you have planned.';
        foreach (array_merge($b['today']['clashes'], $b['tomorrow']['clashes']) as $c) $parts[] = '⚠ Clash at '.$c['at'].': “'.$c['a'].'” and “'.$c['b'].'”.';
        $parts[] = 'Remember to log today’s income & expenses — scan bills as you get them.';

        $notification = TaskflowNotification::create([
            'workspace_id' => $user->workspace_id, 'user_id' => $user->id, 'type' => 'daily_briefing',
            'title' => $b['greeting'].'! Your day at a glance', 'body' => implode(' ', $parts), 'data' => ['url' => '/app?checkin=1'],
        ]);
        $prefs = $user->preferences;
        if (! $prefs || $prefs->notify_browser) app(WebPushNotifier::class)->send($user, $notification);
        if ($prefs && $prefs->notify_email) { try { Mail::raw(implode("\n\n", $parts), fn ($m) => $m->to($user->email)->subject($notification->title)); } catch (\Throwable $e) { report($e); } }
    }

    /** Alert immediately when a new or imported meeting overlaps another one. */
    public function alertClash(User $user, string $title, array $conflicts): void
    {
        if (! $conflicts) return;
        $zone = UserTime::zone($user);
        $first = $conflicts[0];
        $at = Carbon::parse($first['starts_at'])->timezone($zone)->format('j M, g:i a');
        $notification = TaskflowNotification::create([
            'workspace_id' => $user->workspace_id, 'user_id' => $user->id, 'type' => 'schedule_clash',
            'title' => 'Schedule clash', 'body' => '“'.$title.'” overlaps “'.$first['title'].'” ('.$first['source'].') on '.$at.(count($conflicts) > 1 ? ' and '.(count($conflicts) - 1).' more' : '').'.',
            'data' => ['url' => '/app/calendar?date='.Carbon::parse($first['starts_at'])->timezone($zone)->toDateString()],
        ]);
        if (! $user->preferences || $user->preferences->notify_browser) app(WebPushNotifier::class)->send($user, $notification);
    }
}
