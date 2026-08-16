<?php
namespace App\Http\Controllers\Api\Admin\Analytics;
use App\Http\Controllers\Controller; use App\Jobs\GenerateReportExport; use App\Models\ReportExportJob; use Illuminate\Http\Request; use Illuminate\Support\Facades\Storage; use Illuminate\Validation\Rule;
class ReportExportController extends Controller {
 public function index(Request $r){return response()->json(ReportExportJob::query()->where('requested_by',$r->user()->id)->latest()->limit(50)->get());}
 public function store(Request $r){$data=$r->validate(['report_type'=>['required',Rule::in(['member_directory','credential_health'])],'format'=>['nullable',Rule::in(['csv'])],'filters'=>'nullable|array']);$job=ReportExportJob::query()->create(['report_type'=>$data['report_type'],'filters'=>$data['filters']??null,'format'=>$data['format']??'csv','status'=>'queued','requested_by'=>$r->user()->id]);GenerateReportExport::dispatch($job->id);return response()->json($job,202);}
 public function download(Request $r,ReportExportJob $report){abort_unless($report->requested_by===$r->user()->id||$r->user()->canDo('analytics.view'),403);abort_unless($report->status==='completed'&&$report->storage_path,404);abort_if($report->expires_at&&$report->expires_at->isPast(),410);return Storage::disk($report->storage_disk)->download($report->storage_path,'NurseLink-'.$report->report_type.'.csv');}
}
