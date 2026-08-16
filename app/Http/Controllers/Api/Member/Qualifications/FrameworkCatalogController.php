<?php
namespace App\Http\Controllers\Api\Member\Qualifications;
use App\Http\Controllers\Controller; use App\Models\QualificationFramework; use App\Services\Qualifications\QualificationComparisonService; use Illuminate\Http\JsonResponse; use Illuminate\Http\Request;
class FrameworkCatalogController extends Controller
{
    public function index():JsonResponse{$items=QualificationFramework::query()->whereIn('governance_status',['reference_only','published'])->with('levels')->orderBy('scope')->orderBy('name')->get();return response()->json(['data'=>$items,'disclaimer'=>config('qualification.disclaimer')]);}
    public function compare(Request $request,QualificationComparisonService $service):JsonResponse{$data=$request->validate(['source_framework_id'=>['required','uuid','exists:qualification_frameworks,id'],'target_framework_id'=>['required','uuid','exists:qualification_frameworks,id']]);$source=QualificationFramework::findOrFail($data['source_framework_id']);$target=QualificationFramework::findOrFail($data['target_framework_id']);return response()->json(['data'=>$service->compare($source,$target)]);}
}
