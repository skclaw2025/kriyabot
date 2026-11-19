<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SavedPlace;
use App\Models\Task;
use App\Models\Meeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use App\Services\AiClient;
use App\Models\FinanceHead;
use App\Models\ExternalEvent;

class AssistantController extends Controller
{
    public function context(Request $request)
    {
        $user=$request->user();
        return response()->json([
            'projects'=>Project::where('workspace_id',$user->workspace_id)->where(fn($q)=>$q->where('created_by',$user->id)->orWhereHas('members',fn($m)=>$m->where('users.id',$user->id)))->orderBy('name')->get(['id','name']),
            'places'=>SavedPlace::where('workspace_id',$user->workspace_id)->where('user_id',$user->id)->orderBy('name')->get(['id','name','kind','address']),
            'timezone'=>$this->userTimezone($user), 'today_schedule'=>$this->todaySchedule($user), 'configured'=>app(AiClient::class)->configured(),
            'assistant_name'=>$user->preferences?->assistant_name ?: 'Kriyabot Assistant',
            'assistant_voice'=>$user->preferences?->assistant_voice ?: 'india_female_warm',
            'assistant_language'=>$user->preferences?->assistant_language ?: ($user->locale === 'hi' ? 'hi-IN' : 'en-IN'),
            'user_name'=>$user->name,
        ]);
    }

    public function parse(Request $request, AiClient $ai)
    {
        $data=$request->validate(['command'=>['required','string','max:6000'],'input_language'=>['sometimes','in:en-IN,hi-IN']]);
        $user=$request->user(); $timezone=$this->userTimezone($user);
        $projects=Project::where('workspace_id',$user->workspace_id)->where(fn($q)=>$q->where('created_by',$user->id)->orWhereHas('members',fn($m)=>$m->where('users.id',$user->id)))->pluck('name');
        $places=SavedPlace::where('workspace_id',$user->workspace_id)->where('user_id',$user->id)->get(['id','name','kind','address']);
        $heads=FinanceHead::where('user_id',$user->id)->get(['id','name','type']);
        if (! $ai->configured()) return response()->json(['message'=>$ai->notConfiguredMessage()],422);

        $languageInstruction = ($data['input_language'] ?? 'en-IN') === 'hi-IN'
            ? 'The user spoke Hindi. Understand and translate the command into English before extracting its intent. Return title, description, project_name, attendee_name and location_label in English so saved records are in English. Write reply and clarification in natural Hindi (Devanagari).'
            : 'The user spoke Indian English. Return all draft fields and replies in English.';
        $system = 'You interpret one Kriyabot command as a task, meeting or money (income/expense) draft. '.$languageInstruction
            .' Never claim you created or saved anything; the user confirms the draft afterwards.'
            .' Current local time is '.now($timezone)->toIso8601String().' and the timezone is '.$timezone.'.'
            .' If a date lacks a year choose the next future occurrence. A meeting without an end time lasts one hour.'
            .' If the user asks for a task reminder but gives no due date, use kind=clarification and ask for the due date.'
            .' Use kind=clarification whenever required details cannot be inferred.'
            .' Use kind=finance when the user reports money spent, paid, received or earned (e.g. "paid 1200 for electricity by UPI", "received 50000 fees from Rahul", "kharcha 300 petrol"). Set finance_type to expense or income, amount as a number in INR, entry_date (YYYY-MM-DD, default today), party, payment_method, a short title as the description, and head_name copied exactly from the matching head list (or null and suggested_head if none fits).'
            .' Expense heads: '.($heads->where('type','expense')->pluck('name')->implode(', ') ?: 'none').'. Income heads: '.($heads->where('type','income')->pluck('name')->implode(', ') ?: 'none').'.'
            .' Existing projects: '.($projects->implode(', ') ?: 'none').'.'
            .' Saved places (use the exact name as location_label when one is chosen): '.($places->map(fn($p)=>$p->name.' ('.$p->kind.')')->implode(', ') ?: 'none').'.';

        $nullableString=['type'=>['string','null']];
        $schema=['type'=>'object','properties'=>[
            'kind'=>['type'=>'string','enum'=>['task','meeting','finance','clarification']],
            'reply'=>['type'=>'string','description'=>'Short friendly confirmation of what the draft contains.'],
            'title'=>$nullableString,'description'=>$nullableString,'project_name'=>$nullableString,
            'priority'=>['type'=>['string','null'],'enum'=>['low','normal','high','urgent',null]],
            'due_at'=>['type'=>['string','null'],'description'=>'ISO 8601 with offset, or null'],
            'starts_at'=>['type'=>['string','null'],'description'=>'ISO 8601 with offset, or null'],
            'ends_at'=>['type'=>['string','null'],'description'=>'ISO 8601 with offset, or null'],
            'attendee_name'=>$nullableString,
            'reminder_minutes'=>['type'=>['integer','null']],
            'location_type'=>['type'=>['string','null'],'enum'=>['office','saved_place','online','custom',null]],
            'location_label'=>$nullableString,'online_url'=>$nullableString,'clarification'=>$nullableString,
            'finance_type'=>['type'=>['string','null'],'enum'=>['income','expense',null]],
            'amount'=>['type'=>['number','null']],
            'entry_date'=>['type'=>['string','null'],'description'=>'YYYY-MM-DD'],
            'party'=>$nullableString,
            'payment_method'=>['type'=>['string','null'],'enum'=>['cash','upi','card','bank','cheque','other',null]],
            'head_name'=>$nullableString,'suggested_head'=>$nullableString,
        ],'required'=>['kind','reply']];

        $result=$ai->structured($system,'User command: '.$data['command'],$schema);
        if (! $result['ok']) return response()->json(['message'=>$result['message']],$result['status'] ?? 502);
        $draft=$result['data'];
        if (! in_array($draft['kind'] ?? null,['task','meeting','finance','clarification'],true)) return response()->json(['message'=>'The assistant returned an unreadable draft. Please rephrase and try again.'],502);
        $draft['timezone']=$timezone;
        if (($draft['kind'] ?? '')==='meeting' && ($draft['location_type'] ?? null)==='saved_place') {
            $place=$places->first(fn($p)=>mb_strtolower($p->name)===mb_strtolower((string)($draft['location_label'] ?? '')));
            $draft['saved_place_id']=$place?->id;
        }
        if (($draft['kind'] ?? '')==='finance') {
            $type=($draft['finance_type'] ?? 'expense')==='income'?'income':'expense';
            $head=$heads->first(fn($h)=>$h->type===$type && mb_strtolower($h->name)===mb_strtolower(trim((string)($draft['head_name'] ?? ''))));
            $draft['finance_type']=$type; $draft['head_id']=$head?->id; $draft['head_name']=$head?->name;
            $draft['entry_date']=$draft['entry_date'] ?? now($timezone)->toDateString();
        }
        return response()->json(['draft'=>$draft]);
    }

    /** Morning check-in data for Home: today, tomorrow's meetings, clashes, today's money. */
    public function briefing(Request $request)
    {
        return response()->json(app(\App\Services\DailyBriefing::class)->build($request->user()));
    }

    public function ask(Request $request, AiClient $ai)
    {
        $data=$request->validate([
            'message'=>['required','string','max:2000'],
            'input_language'=>['sometimes','in:en-IN,hi-IN'],
            'history'=>['sometimes','array','max:10'],
            'history.*.role'=>['required','in:user,assistant'],
            'history.*.content'=>['required','string','max:8000'],
        ]);
        $user=$request->user();
        if (! $ai->configured()) return response()->json(['message'=>$ai->notConfiguredMessage()],422);
        $timezone=$this->userTimezone($user);
        $today=now($timezone)->toDateString();
        $agenda=$this->todaySchedule($user);
        $name=$user->preferences?->assistant_name ?: 'Kriyabot Assistant';
        $language=($data['input_language'] ?? 'en-IN')==='hi-IN' ? 'Hindi (Devanagari)' : 'Indian English';
        $message=$data['message'];
        $scheduleQuestion=(bool)preg_match('/\b(my|our)\s+(?:today\s+)?(?:schedule|calendar|tasks?|meetings?|agenda)\b|\b(?:due|pending)\s+(?:tasks?|work|items?)\b/i',$message);
        $needsWebSearch=!$scheduleQuestion&&(bool)preg_match('/\b(search|look up|research|latest|current|recent|news|today\'?s|right now|this week|this month|price|rate|weather|live|score|compare products)\b/i',$message);
        $searchGuidance=$needsWebSearch
            ? 'Web search is available for this request; use it for current facts and rely on the cited results.'
            : 'Web search is not available for this request. Do not claim that you searched; answer from the supplied schedule or general knowledge, and say when a current lookup would be needed.';
        $system="You are {$name}, a warm, respectful, practical personal assistant for {$user->name}. Be friendly without claiming to be human or to have feelings. Reply in {$language}; keep answers short and speech-friendly (no markdown tables). The user's timezone is {$timezone} and today's date is {$today}. Today's Kriyabot schedule (use only this data for their schedule): ".json_encode($agenda,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).". If it is empty, say there is nothing scheduled and they can add plans. {$searchGuidance} Be transparent about uncertainty and never invent sources. Do not say you saved or changed tasks or meetings; creating them happens in the assistant planner.";

        $result=$ai->chat($system,$data['history'] ?? [],$message,$needsWebSearch);
        if (! $result['ok']) return response()->json(['message'=>$result['message']],$result['status'] ?? 502);
        return response()->json(['answer'=>$result['answer'],'sources'=>$result['sources'],'searched'=>$result['searched']]);
    }

    private function todaySchedule(User $user): array
    {
        $timezone=$this->userTimezone($user);
        $dayStart=now($timezone)->startOfDay()->utc();
        $dayEnd=now($timezone)->endOfDay()->utc();
        $tasks=Task::where('workspace_id',$user->workspace_id)->where(function(Builder $query)use($dayStart,$dayEnd){
            $query->whereBetween('start_at',[$dayStart,$dayEnd])->orWhereBetween('due_at',[$dayStart,$dayEnd])
                ->orWhere(fn(Builder $overlap)=>$overlap->whereNotNull('start_at')->whereNotNull('due_at')->where('start_at','<=',$dayEnd)->where('due_at','>=',$dayStart))
                ->orWhere(fn(Builder $overdue)=>$overdue->whereNotNull('due_at')->where('due_at','<',$dayStart)->whereNotIn('status',['done','cancelled']));
        });
        if(!$user->hasRole('workspace_owner'))$tasks->where(fn(Builder $q)=>$q->where('created_by',$user->id)->orWhereHas('assignees',fn(Builder $a)=>$a->where('users.id',$user->id))->orWhereHas('project.members',fn(Builder $m)=>$m->where('users.id',$user->id)));
        $agenda=$tasks->with('project:id,name')->get()->map(fn(Task $task)=>[
            'id'=>'task-'.$task->id,'start_at'=>(($task->due_at && $task->due_at->between($dayStart,$dayEnd)) ? $task->due_at : ($task->start_at ?? $task->due_at))?->copy()->timezone($timezone)->toIso8601String(),
            'title'=>$task->title,'kind'=>'task','phase'=>$task->status==='done'?'completed':'plan','overdue'=>$task->due_at && $task->due_at->lt(now($timezone)->startOfDay()),'detail'=>$task->project?->name ?? 'Personal task','status'=>$task->status,
        ])->all();
        $meetings=Meeting::where('workspace_id',$user->workspace_id)->where('created_by',$user->id)->where('status','scheduled')->whereBetween('starts_at',[$dayStart,$dayEnd])->get();
        foreach($meetings as $meeting)$agenda[]=['id'=>'meeting-'.$meeting->id,'start_at'=>$meeting->starts_at->timezone($meeting->timezone)->toIso8601String(),'title'=>$meeting->title,'kind'=>'meeting','phase'=>'plan','detail'=>$meeting->attendee_name ? 'With '.$meeting->attendee_name.' · '.($meeting->location_label ?? ucfirst($meeting->location_type)) : ($meeting->location_label ?? ucfirst($meeting->location_type)),'status'=>'scheduled'];
        ExternalEvent::where('user_id',$user->id)->where('uid_hash','not like','kriyabot-%')->where('starts_at','<',$dayEnd)->where('ends_at','>',$dayStart)->with('source:id,name')->orderBy('starts_at')->get()
            ->each(function($e)use(&$agenda,$timezone){$agenda[]=['id'=>'external-'.$e->id,'start_at'=>$e->all_day?null:$e->starts_at->copy()->timezone($timezone)->toIso8601String(),'title'=>$e->title,'kind'=>'meeting','phase'=>'plan','detail'=>($e->source?->name ?? 'Calendar').($e->location?' · '.$e->location:''),'status'=>'scheduled'];});
        usort($agenda,fn($a,$b)=>strtotime($a['start_at'] ?? '00:00') <=> strtotime($b['start_at'] ?? '00:00'));
        return $agenda;
    }

    private function userTimezone(User $user): string
    {
        return \App\Support\UserTime::zone($user);
    }
}
