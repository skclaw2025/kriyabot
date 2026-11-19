<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\SavedPlace;
use App\Models\TaskflowReminder;
use App\Services\AuditLogger;
use App\Services\DailyBriefing;
use App\Services\ScheduleConflicts;
use App\Support\UserTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;

class MeetingController extends Controller
{
    public function index(Request $request){return response()->json(Meeting::where('workspace_id',$request->user()->workspace_id)->where('created_by',$request->user()->id)->whereBetween('starts_at',[$request->query('from',now()->subDays(30)), $request->query('to',now()->addMonths(6))])->orderBy('starts_at')->get());}
    public function store(Request $request, AuditLogger $audit)
    {
        $user=$request->user(); $d=$request->validate(['title'=>['required','string','max:240'],'description'=>['nullable','string','max:10000'],'attendee_name'=>['nullable','string','max:180'],'starts_at'=>['required','date'],'ends_at'=>['required','date','after:starts_at'],'timezone'=>['required','timezone'],'location_type'=>['required',Rule::in(['office','saved_place','online','custom'])],'location_label'=>['nullable','string','max:180'],'location_details'=>['nullable','string','max:2000'],'online_url'=>['nullable','url','max:2000'],'saved_place_id'=>['nullable','integer',Rule::exists('saved_places','id')->where(fn($q)=>$q->where('workspace_id',$user->workspace_id)->where('user_id',$user->id))],'reminder_minutes'=>['nullable','integer','min:0','max:10080']]);
        $d['starts_at']=UserTime::parse($d['starts_at'],$user);$d['ends_at']=UserTime::parse($d['ends_at'],$user);
        if($d['location_type']==='saved_place'){$place=SavedPlace::where('workspace_id',$user->workspace_id)->where('user_id',$user->id)->findOrFail($d['saved_place_id'] ?? 0);$d['location_label']=$place->name;$d['location_details']=$place->address;}else{$d['saved_place_id']=null;}
        if($d['location_type']==='online' && empty($d['online_url'])) return response()->json(['message'=>'Add the meeting link.'],422);
        if($d['location_type']==='office' && empty($d['location_label'])) $d['location_label']='Office';
        // Warn before double-booking (Kriyabot meetings and imported Outlook/Google events).
        $conflicts=app(ScheduleConflicts::class)->overlapping($user,$d['starts_at'],$d['ends_at']);
        if($conflicts && !$request->boolean('confirm_conflict')){
            $first=$conflicts[0];$zone=UserTime::zone($user);
            return response()->json(['message'=>'This overlaps “'.$first['title'].'” ('.$first['source'].', '.Carbon::parse($first['starts_at'])->timezone($zone)->format('g:i a').'–'.Carbon::parse($first['ends_at'])->timezone($zone)->format('g:i a').')'.(count($conflicts)>1?' and '.(count($conflicts)-1).' more':'').'. Save it anyway?','conflicts'=>$conflicts],409);
        }
        // Every meeting gets an alert: the person's choice, their default, or 15 minutes.
        if(!array_key_exists('reminder_minutes',$d) || $d['reminder_minutes']===null) $d['reminder_minutes']=(int)data_get($user->preferences?->notification_rules,'meeting_reminder_minutes',15);
        $meeting=DB::transaction(function()use($d,$user){$meeting=Meeting::create([...collect($d)->except(['reminder_minutes'])->all(),'workspace_id'=>$user->workspace_id,'created_by'=>$user->id]);if(($d['reminder_minutes']??null)!==null){$reminder=TaskflowReminder::create(['workspace_id'=>$user->workspace_id,'user_id'=>$user->id,'remind_at'=>$meeting->starts_at->copy()->subMinutes($d['reminder_minutes']),'status'=>'pending']);$reminder->remindable()->associate($meeting);$reminder->save();}return $meeting;});
        $audit->record($request,'meeting.created',$meeting,['starts_at'=>$meeting->starts_at,'confirmed_conflict'=>(bool)$conflicts]);
        if($conflicts) app(DailyBriefing::class)->alertClash($user,$meeting->title,$conflicts);
        return response()->json([...$meeting->toArray(),'conflicts'=>$conflicts],201);
    }
    /** Check a time slot before saving (used by the assistant drafts). */
    public function conflicts(Request $request){$user=$request->user();$d=$request->validate(['starts_at'=>['required','date'],'ends_at'=>['required','date','after:starts_at']]);return response()->json(['conflicts'=>app(ScheduleConflicts::class)->overlapping($user,UserTime::parse($d['starts_at'],$user),UserTime::parse($d['ends_at'],$user))]);}
    public function destroy(Request $request, Meeting $meeting, AuditLogger $audit){abort_unless($meeting->workspace_id===$request->user()->workspace_id && $meeting->created_by===$request->user()->id,404);$audit->record($request,'meeting.cancelled',$meeting);$meeting->update(['status'=>'cancelled']);$meeting->delete();return response()->noContent();}
}
