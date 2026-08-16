<?php
namespace App\Http\Controllers\Api\Member\Credentials;
use App\Http\Controllers\Controller; use App\Http\Requests\Credentials\UpsertCredentialRequest; use App\Models\Member; use App\Models\MemberDocument; use App\Models\ProfessionalCredential; use App\Services\AuditLogger; use App\Services\Credentials\CredentialNumberService; use App\Services\Credentials\CredentialReminderService; use App\Services\Credentials\CredentialStatusService; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class CredentialController extends Controller
{
    public function index(Request $request):JsonResponse{$m=$this->member($request);return response()->json(['data'=>$m->credentials()->with('primaryDocument')->orderByRaw('expires_on is null')->orderBy('expires_on')->get()]);}
    public function store(UpsertCredentialRequest $request,CredentialNumberService $numbers,CredentialStatusService $statuses,CredentialReminderService $reminders,AuditLogger $audit):JsonResponse
    {
        $m=$this->member($request);$data=$request->validated();$this->validateDocument($m,$data['primary_document_id']??null);$raw=$data['credential_number']??null;
        $row=$m->credentials()->create($data+['credential_number_hash'=>$numbers->fingerprint($raw),'credential_number_last4'=>$numbers->last4($raw),'verification_status'=>'unverified','credential_status'=>'active']);
        $row=$statuses->refresh($row);$reminders->rebuild($row);$audit->write('credential.created',$request->user(),'professional_credential',$row->id,['category'=>$row->category,'credential_type'=>$row->credential_type],$request);
        return response()->json(['data'=>$this->present($row)],201);
    }
    public function update(UpsertCredentialRequest $request,ProfessionalCredential $credential,CredentialNumberService $numbers,CredentialStatusService $statuses,CredentialReminderService $reminders,AuditLogger $audit):JsonResponse
    {
        $m=$this->member($request);$this->own($m,$credential);$data=$request->validated();$this->validateDocument($m,$data['primary_document_id']??null);$raw=$data['credential_number']??null;
        if(array_key_exists('credential_number',$data)){$data['credential_number_hash']=$numbers->fingerprint($raw);$data['credential_number_last4']=$numbers->last4($raw);} $data['verification_status']='unverified';$data['verified_by']=null;$data['verified_at']=null;
        $credential->update($data);$credential=$statuses->refresh($credential);$reminders->rebuild($credential);$audit->write('credential.updated',$request->user(),'professional_credential',$credential->id,[],$request);
        return response()->json(['data'=>$this->present($credential)]);
    }
    public function destroy(Request $request,ProfessionalCredential $credential,AuditLogger $audit):JsonResponse{$m=$this->member($request);$this->own($m,$credential);$audit->write('credential.deleted',$request->user(),'professional_credential',$credential->id,['title'=>$credential->title],$request);$credential->delete();return response()->json([],204);}
    public function linkDocument(Request $request,ProfessionalCredential $credential):JsonResponse{$m=$this->member($request);$this->own($m,$credential);$data=$request->validate(['document_id'=>['required','uuid'],'purpose'=>['nullable','string','max:80']]);$doc=MemberDocument::query()->where('member_id',$m->id)->findOrFail($data['document_id']);$credential->documents()->syncWithoutDetaching([$doc->id=>['purpose'=>$data['purpose']??'evidence']]);return response()->json(['data'=>$credential->load('documents')]);}
    private function member(Request $r):Member{return Member::query()->where('user_id',$r->user()->id)->firstOrFail();}
    private function own(Member $m,ProfessionalCredential $c):void{abort_unless($c->member_id===$m->id,403);}
    private function validateDocument(Member $m,?string $id):void{if($id)abort_unless(MemberDocument::query()->where('member_id',$m->id)->whereKey($id)->exists(),422,'Evidence document does not belong to this member.');}
    private function present(ProfessionalCredential $c):array{$a=$c->fresh()->toArray();$a['masked_number']=$c->masked_number;return $a;}
}
