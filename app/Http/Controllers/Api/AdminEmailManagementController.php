<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AdminEmailManagementController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeMailbox($request);
        $query = DB::table('nurselink_email_threads as t')
            ->leftJoin('users as m', 'm.id', '=', 't.member_user_id')
            ->leftJoin('users as a', 'a.id', '=', 't.assigned_user_id')
            ->select('t.*', 'm.name as member_name', 'a.name as assigned_name')
            ->selectSub(function ($messages) {
                $messages->from('nurselink_email_messages as em')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('em.thread_id', 't.id')
                    ->where('em.direction', 'inbound')
                    ->whereNull('em.read_at');
            }, 'unread_count')
            ->orderByDesc('t.last_message_at');
        if ($request->filled('status')) $query->where('t.status', $request->string('status'));
        if ($request->filled('category')) $query->where('t.category', $request->string('category'));
        if ($request->filled('search')) {
            $search = '%'.str_replace(['%','_'], ['\\%','\\_'], $request->string('search')).'%';
            $query->where(fn ($q) => $q->where('t.subject', 'like', $search)->orWhere('t.contact_email', 'like', $search));
        }
        return response()->json(['data' => $query->limit(100)->get(), 'mailbox' => config('email_management.mailbox'), 'imap_available' => function_exists('imap_open')]);
    }

    public function show(Request $request, int $threadId): JsonResponse
    {
        $this->authorizeMailbox($request);
        $thread = DB::table('nurselink_email_threads')->find($threadId);
        abort_unless($thread, 404);
        DB::table('nurselink_email_messages')->where('thread_id', $threadId)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
        return response()->json(['data' => ['thread' => $thread, 'messages' => DB::table('nurselink_email_messages')->where('thread_id', $threadId)->orderBy('sent_at')->orderBy('id')->get()]]);
    }

    public function send(Request $request): JsonResponse
    {
        $this->authorizeMailbox($request);
        $data = $request->validate([
            'thread_id' => ['nullable','integer','exists:nurselink_email_threads,id'],
            'to' => ['required','email:rfc','max:255'],
            'subject' => ['required','string','max:190'],
            'body' => ['required','string','max:20000'],
            'category' => ['nullable', Rule::in(['member_support','events','training','welfare','privacy','technical','membership','policy'])],
            'priority' => ['nullable', Rule::in(['normal','important','urgent'])],
            'attachments.*' => ['file','max:'.config('email_management.max_attachment_kb'),'mimes:pdf,jpg,jpeg,png,docx,xlsx'],
        ]);
        $memberId = User::query()->whereRaw('LOWER(email) = ?', [strtolower($data['to'])])->value('id');
        return DB::transaction(function () use ($request, $data, $memberId) {
            $threadId = $data['thread_id'] ?? DB::table('nurselink_email_threads')->insertGetId([
                'subject' => $data['subject'], 'contact_email' => strtolower($data['to']), 'member_user_id' => $memberId,
                'assigned_user_id' => $request->user()->id, 'category' => $data['category'] ?? 'member_support',
                'priority' => $data['priority'] ?? 'normal', 'status' => 'open', 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $stored = [];
            foreach ($request->file('attachments', []) as $file) {
                $path = $file->store("email-management/{$threadId}", ['disk' => 'local']);
                $stored[] = ['name' => $file->getClientOriginalName(), 'path' => $path, 'size' => $file->getSize(), 'mime' => $file->getMimeType()];
            }
            app(\App\Services\NurseLinkEmailSettings::class)->mailer()->raw($data['body'], function ($message) use ($data, $stored) {
                $message->to($data['to'])->subject($data['subject'])->replyTo(config('email_management.mailbox'));
                foreach ($stored as $attachment) $message->attach(Storage::disk('local')->path($attachment['path']), ['as' => $attachment['name']]);
            });
            $messageId = DB::table('nurselink_email_messages')->insertGetId([
                'thread_id' => $threadId, 'direction' => 'outbound', 'from_email' => config('email_management.mailbox'), 'to_email' => strtolower($data['to']),
                'body_text' => $data['body'], 'attachments' => $stored ? json_encode($stored) : null, 'sent_by_user_id' => $request->user()->id,
                'delivery_status' => 'sent', 'read_at' => now(), 'sent_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('nurselink_email_threads')->where('id', $threadId)->update(['last_message_at' => now(), 'updated_at' => now()]);
            $this->audit->write('email.message_sent', $request->user(), 'email_thread', (string) $threadId, ['message_id' => $messageId, 'recipient' => strtolower($data['to']), 'subject' => $data['subject'], 'body_excluded' => true], $request);
            return response()->json(['message' => 'Email sent and recorded.', 'data' => ['thread_id' => $threadId, 'message_id' => $messageId]], 201);
        });
    }

    public function update(Request $request, int $threadId): JsonResponse
    {
        $this->authorizeMailbox($request);
        $data = $request->validate(['status' => ['nullable', Rule::in(['open','waiting_member','escalated','resolved','archived'])], 'priority' => ['nullable', Rule::in(['normal','important','urgent'])], 'assigned_user_id' => ['nullable','integer','exists:users,id'], 'category' => ['nullable','string','max:60']]);
        abort_unless(DB::table('nurselink_email_threads')->where('id', $threadId)->exists(), 404);
        $data['archived_at'] = ($data['status'] ?? null) === 'archived' ? now() : null;
        $data['updated_at'] = now();
        DB::table('nurselink_email_threads')->where('id', $threadId)->update($data);
        $this->audit->write('email.thread_updated', $request->user(), 'email_thread', (string) $threadId, array_diff_key($data, ['updated_at' => true]), $request);
        return response()->json(['message' => 'Email conversation updated.']);
    }

    public function intakeDocument(Request $request,int $id,int $document)
    {
        $this->authorizeMailbox($request);
        $row=DB::table('nurselink_email_intake_documents')->where('intake_id',$id)->where('id',$document)->first();
        abort_unless($row,404);abort_unless($row->security_status==='clean',423,'Security scanning must pass before viewing a document.');
        return \Illuminate\Support\Facades\Storage::disk('local')->download($row->storage_path,$row->original_name,['Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']);
    }
    public function linkIntake(Request $request,int $id):JsonResponse
    {
        $this->authorizeMailbox($request);
        $data=$request->validate(['candidate_email'=>['required','email'],'note'=>['required','string','min:10','max:3000']]);
        $ids=User::query()->whereRaw('LOWER(email) = ?',[strtolower($data['candidate_email'])])->pluck('id');
        abort_unless($ids->count()===1,422,'Identify one existing candidate account before linking.');
        abort_if((string)$ids[0]===(string)$request->user()->getKey(),403,'Another administrator must review your intake.');
        app(\App\Services\SmartRegistration\EmailAttachmentIntake::class)->link($id,(string)$ids[0],(string)$request->user()->getKey(),$data['note']);
        $this->audit->write('email.intake_linked',$request->user(),'email_intake',(string)$id,['review_note_recorded'=>true],$request);
        return response()->json(['message'=>'Documents linked. The candidate must review and confirm the extracted information.']);
    }
    public function intakes(Request $request):JsonResponse
    {
        $this->authorizeMailbox($request);
        return response()->json(['data'=>DB::table('nurselink_email_intakes')->orderByDesc('id')->limit(100)->get(['id','status','proposed_user_id','created_at'])->map(function($row){
            $row->documents=DB::table('nurselink_email_intake_documents')->where('intake_id',$row->id)->get(['id','original_name','status','security_status'])->map(function($doc)use($row){$doc->download_url='/backend/api/nurselink/admin/email-intakes/'.$row->id.'/documents/'.$doc->id;return $doc;});return $row;
        }),
            'configured'=>(bool)config('email_management.imap.host') && (bool)config('email_management.imap.username') && (bool)config('email_management.imap.password')]);
    }
    private function authorizeMailbox(Request $request): void
    {
        app(\App\Services\NurseLinkEmailSettings::class)->applyIntake();
        $email = strtolower((string) $request->user()?->email);
        $allowed = array_map('strtolower', config('email_management.allowed_admins', []));
        abort_unless(in_array($email, $allowed, true), 403, 'Your administrator account is not authorized for LBS NurseLink email management.');
    }
}
