<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\Meeting;
use App\Models\ExternalEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        abort_if(now()->parse($data['to'])->diffInDays(now()->parse($data['from'])) > 93, 422, 'Calendar range cannot exceed 94 days.');
        $user = $request->user();
        // Task timestamps are stored in UTC; calendar days must follow the person's own timezone.
        $tz = \App\Support\UserTime::zone($user);
        $fromUtc = Carbon::parse($data['from'], $tz)->startOfDay()->utc();
        $toUtc = Carbon::parse($data['to'], $tz)->endOfDay()->utc();
        $localDate = fn ($value) => $value?->copy()->timezone($tz)->toDateString();
        $tasks = Task::where('workspace_id', $user->workspace_id)->where(function (Builder $range) use ($fromUtc, $toUtc) {
            $range->whereBetween('start_at', [$fromUtc, $toUtc])
                ->orWhereBetween('due_at', [$fromUtc, $toUtc])
                ->orWhere(fn (Builder $overlap) => $overlap->whereNotNull('start_at')->whereNotNull('due_at')->where('start_at', '<=', $fromUtc)->where('due_at', '>=', $fromUtc))
                ->orWhereBetween('completed_at', [$fromUtc, $toUtc]);
        })->with('project:id,name');
        if (! $user->hasRole('workspace_owner')) $tasks->where(fn (Builder $q) => $q->where('created_by', $user->id)->orWhereHas('assignees', fn (Builder $a) => $a->where('users.id', $user->id))->orWhereHas('project.members', fn (Builder $m) => $m->where('users.id', $user->id)));
        $events = [];
        foreach ($tasks->get() as $task) {
            if ($task->start_at || $task->due_at) {
                $start = $localDate($task->start_at) ?? $localDate($task->due_at);
                $end = $localDate($task->due_at) ?? $start;
                if ($end < $start) $end = $start;
                $events[] = ['id' => 'task-plan-'.$task->id, 'kind' => 'task', 'phase' => 'plan', 'start' => $start, 'end' => $end, 'title' => $task->title, 'detail' => $task->project?->name ?? 'Personal task', 'status' => $task->status, 'priority' => $task->priority, 'url' => '/app/tasks?task='.$task->id];
            }
            if ($task->completed_at) $events[] = ['id' => 'task-completed-'.$task->id, 'kind' => 'task', 'phase' => 'completed', 'start' => $localDate($task->completed_at), 'end' => $localDate($task->completed_at), 'title' => $task->title, 'detail' => 'Completed', 'status' => $task->status, 'url' => '/app/tasks?task='.$task->id];
        }
        $meetings=Meeting::where('workspace_id',$user->workspace_id)->where('created_by',$user->id)->where('status','scheduled')->whereBetween('starts_at',[now()->parse($data['from'])->subDay(), now()->parse($data['to'])->addDay()])->get();
        foreach($meetings as $meeting){$localStart=$meeting->starts_at->copy()->timezone($meeting->timezone);$localEnd=$meeting->ends_at->copy()->timezone($meeting->timezone);if($localStart->toDateString()<$data['from']||$localStart->toDateString()>$data['to'])continue;$events[]=['id'=>'meeting-'.$meeting->id,'kind'=>'meeting','phase'=>'plan','start'=>$localStart->toDateString(),'end'=>$localEnd->toDateString(),'start_at'=>$localStart->toIso8601String(),'end_at'=>$localEnd->toIso8601String(),'timezone'=>$meeting->timezone,'title'=>$meeting->title,'detail'=>trim(($meeting->attendee_name ? 'With '.$meeting->attendee_name.' · ' : '').($meeting->location_label ?? ucfirst($meeting->location_type))),'location_type'=>$meeting->location_type,'location_label'=>$meeting->location_label,'online_url'=>$meeting->online_url,'status'=>'scheduled','url'=>'/app/calendar?date='.$localStart->toDateString()];}
        $projects = Project::where('workspace_id', $user->workspace_id)->where(fn (Builder $q) => $q
            ->whereBetween('start_date', [$data['from'], $data['to']])
            ->orWhereBetween('due_date', [$data['from'], $data['to']])
            ->orWhere(fn (Builder $overlap) => $overlap->whereNotNull('start_date')->whereNotNull('due_date')->whereDate('start_date', '<=', $data['from'])->whereDate('due_date', '>=', $data['from']))
        )->with('members:id')->get();
        if (! $user->hasRole('workspace_owner')) $projects = $projects->filter(fn (Project $p) => $p->created_by === $user->id || $p->members->contains('id', $user->id));
        foreach ($projects as $project) {
            $start = $project->start_date?->toDateString() ?? $project->due_date?->toDateString();
            $end = $project->due_date?->toDateString() ?? $start;
            if ($start && $end) $events[] = ['id' => 'project-'.$project->id, 'kind' => 'project', 'phase' => 'plan', 'start' => $start, 'end' => $end, 'title' => $project->name, 'detail' => 'Project milestone', 'status' => $project->status, 'url' => '/app/projects/'.$project->id];
        }
        $unscheduled = Task::where('workspace_id', $user->workspace_id)->whereNull('start_at')->whereNull('due_at')->whereNotIn('status', ['done', 'cancelled']);
        if (! $user->hasRole('workspace_owner')) $unscheduled->where(fn (Builder $q) => $q->where('created_by', $user->id)->orWhereHas('assignees', fn (Builder $a) => $a->where('users.id', $user->id))->orWhereHas('project.members', fn (Builder $m) => $m->where('users.id', $user->id)));
        $unscheduled = $unscheduled->with('project:id,name')->orderByDesc('priority')->orderBy('created_at')->limit(100)->get()->map(fn (Task $task) => ['id' => $task->id, 'title' => $task->title, 'status' => $task->status, 'priority' => $task->priority, 'project' => $task->project?->name ?? 'Personal task']);
        // Imported Outlook / Google / iCloud events (read-only).
        ExternalEvent::where('user_id', $user->id)->where('uid_hash', 'not like', 'kriyabot-%')->where('starts_at', '<', $toUtc)->where('ends_at', '>', $fromUtc)->with('source:id,name,color')->orderBy('starts_at')->get()
            ->each(function (ExternalEvent $e) use (&$events, $tz, $localDate) {
                $end = $e->all_day ? $e->ends_at->copy()->subSecond() : $e->ends_at;
                $events[] = ['id' => 'external-'.$e->id, 'kind' => 'external', 'phase' => 'plan', 'start' => $localDate($e->starts_at), 'end' => $localDate($end), 'start_at' => $e->all_day ? null : $e->starts_at->copy()->timezone($tz)->toIso8601String(), 'end_at' => $e->all_day ? null : $e->ends_at->copy()->timezone($tz)->toIso8601String(), 'title' => $e->title, 'detail' => trim(($e->source?->name ?? 'Calendar').($e->location ? ' · '.$e->location : '')), 'status' => 'scheduled', 'url' => '/app/calendar?date='.$localDate($e->starts_at)];
            });
        // Flag timed items that overlap each other.
        $timed = array_keys(array_filter($events, fn ($e) => in_array($e['kind'], ['meeting', 'external'], true) && ! empty($e['start_at'])));
        foreach ($timed as $i) foreach ($timed as $j) {
            if ($i >= $j) continue;
            if (strtotime($events[$i]['start_at']) < strtotime($events[$j]['end_at']) && strtotime($events[$j]['start_at']) < strtotime($events[$i]['end_at'])) {
                $events[$i]['clash'] = true; $events[$j]['clash'] = true;
            }
        }
        usort($events, fn ($a, $b) => [$a['start'], $a['kind'], $a['title']] <=> [$b['start'], $b['kind'], $b['title']]);
        return response()->json(['events' => $events, 'unscheduled' => $unscheduled, 'counts' => ['scheduled' => count(array_filter($events, fn ($event) => $event['phase'] === 'plan')), 'completed' => count(array_filter($events, fn ($event) => $event['phase'] === 'completed')), 'unscheduled' => $unscheduled->count()]]);
    }
}
