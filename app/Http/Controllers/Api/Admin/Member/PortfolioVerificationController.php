<?php
namespace App\Http\Controllers\Api\Admin\Member;
use App\Http\Controllers\Controller;
use App\Models\PortfolioCompetency;
use App\Models\PortfolioEducation;
use App\Models\PortfolioEmployment;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class PortfolioVerificationController extends Controller
{
    public function education(Request $request, PortfolioEducation $education, AuditLogger $audit):JsonResponse
    {
        $data=$request->validate(['decision'=>['required','in:verified,document_supported,unable_to_verify,discrepancy'],'note'=>['nullable','string','max:3000']]);
        $education->update(['status'=>$data['decision'],'verified_by'=>in_array($data['decision'],['verified','document_supported'],true)?$request->user()->id:null,'verified_at'=>in_array($data['decision'],['verified','document_supported'],true)?now():null,'verification_note'=>$data['note']??null]);
        $audit->write('portfolio.education_reviewed',$request->user(),'portfolio_education',$education->id,$data,$request);
        return response()->json(['data'=>$education->fresh()]);
    }
    public function employment(Request $request, PortfolioEmployment $employment, AuditLogger $audit):JsonResponse
    {
        $data=$request->validate(['decision'=>['required','in:verified,document_supported,unable_to_verify,discrepancy'],'note'=>['nullable','string','max:3000']]);
        $employment->update(['status'=>$data['decision'],'verified_by'=>in_array($data['decision'],['verified','document_supported'],true)?$request->user()->id:null,'verified_at'=>in_array($data['decision'],['verified','document_supported'],true)?now():null,'verification_note'=>$data['note']??null]);
        $audit->write('portfolio.employment_reviewed',$request->user(),'portfolio_employment',$employment->id,$data,$request);
        return response()->json(['data'=>$employment->fresh()]);
    }
    public function competency(Request $request, PortfolioCompetency $competency, AuditLogger $audit):JsonResponse
    {
        $data=$request->validate(['decision'=>['required','in:verified,document_supported,assessed,member_confirmed,self_declared'],'note'=>['nullable','string','max:3000']]);
        $update=['evidence_state'=>$data['decision']];
        if($data['decision']==='verified'){$update['verified_by']=$request->user()->id;$update['verified_at']=now();}
        if($data['decision']==='assessed'){$update['assessed_by']=$request->user()->id;$update['assessed_at']=now();}
        $competency->update($update);
        $audit->write('portfolio.competency_reviewed',$request->user(),'portfolio_competency',$competency->id,$data,$request);
        return response()->json(['data'=>$competency->fresh()]);
    }
}
