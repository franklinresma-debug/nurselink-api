<?php
namespace App\Http\Controllers\Api\Admin\Member;
use App\Http\Controllers\Controller; use App\Models\ProfessionalCredential; use App\Models\ProfessionalDevelopmentRecord; use App\Services\AuditLogger; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class CredentialVerificationController extends Controller
{
    public function credential(Request $request,ProfessionalCredential $credential,AuditLogger $audit):JsonResponse
    {
        $data=$request->validate(['decision'=>['required','in:verified,document_supported,unable_to_verify,discrepancy'],'note'=>['nullable','string','max:3000']]);$accepted=in_array($data['decision'],['verified','document_supported'],true);
        $credential->update(['verification_status'=>$data['decision'],'verified_by'=>$accepted?$request->user()->id:null,'verified_at'=>$accepted?now():null,'verification_note'=>$data['note']??null]);$audit->write('credential.reviewed',$request->user(),'professional_credential',$credential->id,$data,$request);return response()->json(['data'=>$credential->fresh()]);
    }
    public function professionalDevelopment(Request $request,ProfessionalDevelopmentRecord $record,AuditLogger $audit):JsonResponse
    {
        $data=$request->validate(['decision'=>['required','in:verified,document_supported,unable_to_verify,discrepancy'],'note'=>['nullable','string','max:3000']]);$accepted=in_array($data['decision'],['verified','document_supported'],true);
        $record->update(['status'=>$data['decision'],'verified_by'=>$accepted?$request->user()->id:null,'verified_at'=>$accepted?now():null,'verification_note'=>$data['note']??null]);$audit->write('professional_development.reviewed',$request->user(),'professional_development_record',$record->id,$data,$request);return response()->json(['data'=>$record->fresh()]);
    }
}
