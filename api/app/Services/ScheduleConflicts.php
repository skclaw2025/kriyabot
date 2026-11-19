<?php

namespace App\Services;

use App\Models\ExternalEvent;
use App\Models\Meeting;
use App\Models\User;
use App\Support\UserTime;
use Illuminate\Support\Carbon;

/** Finds meetings (Kriyabot + imported calendars) that overlap a time slot. */
class ScheduleConflicts
{
    /** @return array<int, array{kind: string, id: int, title: string, starts_at: string, ends_at: string, source: string}> */
    public function overlapping(User $user, Carbon $start, Carbon $end, ?int $ignoreMeetingId = null): array
    {
        $zone = UserTime::zone($user);
        $meetings = Meeting::where('workspace_id', $user->workspace_id)->where('created_by', $user->id)->where('status', 'scheduled')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)
            ->when($ignoreMeetingId, fn ($q) => $q->whereKeyNot($ignoreMeetingId))->orderBy('starts_at')->get();
        $external = ExternalEvent::where('user_id', $user->id)->where('all_day', false)->where('uid_hash', 'not like', 'kriyabot-%')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->with('source:id,name')->orderBy('starts_at')->get();
        return [
            ...$meetings->map(fn (Meeting $m) => ['kind' => 'meeting', 'id' => $m->id, 'title' => $m->title, 'starts_at' => $m->starts_at->copy()->timezone($zone)->toIso8601String(), 'ends_at' => $m->ends_at->copy()->timezone($zone)->toIso8601String(), 'source' => 'Kriyabot'])->all(),
            ...$external->map(fn (ExternalEvent $e) => ['kind' => 'external', 'id' => $e->id, 'title' => $e->title, 'starts_at' => $e->starts_at->copy()->timezone($zone)->toIso8601String(), 'ends_at' => $e->ends_at->copy()->timezone($zone)->toIso8601String(), 'source' => $e->source?->name ?? 'Calendar'])->all(),
        ];
    }

    /**
     * Clashing pairs within a day range (Kriyabot meetings + imported timed events).
     * @return array<int, array{a: array, b: array}>
     */
    public function clashesBetween(User $user, Carbon $from, Carbon $to): array
    {
        $items = $this->itemsBetween($user, $from, $to);
        $pairs = [];
        $count = count($items);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($items[$j]['start'] >= $items[$i]['end']) break; // sorted by start
                if (($items[$i]['uid'] ?? null) && $items[$i]['uid'] === ($items[$j]['uid'] ?? null)) continue;
                $pairs[] = ['a' => $items[$i], 'b' => $items[$j]];
            }
        }
        return $pairs;
    }

    /** Sorted timed items with Unix timestamps. */
    public function itemsBetween(User $user, Carbon $from, Carbon $to): array
    {
        $meetings = Meeting::where('workspace_id', $user->workspace_id)->where('created_by', $user->id)->where('status', 'scheduled')
            ->where('starts_at', '<', $to)->where('ends_at', '>', $from)->get()
            ->map(fn (Meeting $m) => ['kind' => 'meeting', 'id' => $m->id, 'title' => $m->title, 'start' => $m->starts_at->getTimestamp(), 'end' => $m->ends_at->getTimestamp(), 'source' => 'Kriyabot', 'uid' => 'kriyabot-meeting-'.$m->id]);
        $external = ExternalEvent::where('user_id', $user->id)->where('all_day', false)->where('starts_at', '<', $to)->where('ends_at', '>', $from)->with('source:id,name')->get()
            // An imported copy of a Kriyabot meeting (from our own feed) is not a clash with itself.
            ->reject(fn (ExternalEvent $e) => str_contains((string) $e->uid_hash, 'kriyabot'))
            ->map(fn (ExternalEvent $e) => ['kind' => 'external', 'id' => $e->id, 'title' => $e->title, 'start' => $e->starts_at->getTimestamp(), 'end' => $e->ends_at->getTimestamp(), 'source' => $e->source?->name ?? 'Calendar', 'uid' => null]);
        return $meetings->concat($external)->sortBy('start')->values()->all();
    }
}
