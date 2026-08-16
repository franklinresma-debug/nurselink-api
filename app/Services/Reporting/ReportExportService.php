<?php
namespace App\Services\Reporting;
use App\Models\Member; use App\Models\ProfessionalCredential; use App\Models\ReportExportJob; use Illuminate\Support\Facades\Storage; use RuntimeException;
class ReportExportService {
 public function generate(ReportExportJob $job):ReportExportJob{
  $job->update(['status'=>'processing','started_at'=>now(),'failure_reason'=>null]);
  try{$rows=$this->rows($job->report_type,$job->filters?:[]);$csv=$this->csv($rows);$path='reports/'.$job->id.'.csv';$disk=config('analytics.export_disk','private');Storage::disk($disk)->put($path,$csv);$job->update(['status'=>'completed','storage_disk'=>$disk,'storage_path'=>$path,'sha256'=>hash('sha256',$csv),'size_bytes'=>strlen($csv),'completed_at'=>now(),'expires_at'=>now()->addDays(config('analytics.export_retention_days',14))]);return $job->refresh();}
  catch(\Throwable $e){$job->update(['status'=>'failed','failure_reason'=>mb_substr($e->getMessage(),0,1000)]);throw $e;}
 }
 private function rows(string $type,array $filters):array{return match($type){
  'member_directory'=>Member::query()->with('profile')->get()->map(fn($m)=>['member_no'=>$m->member_no,'status'=>$m->status,'joined_at'=>optional($m->joined_at)->toDateString(),'country'=>$m->profile?->country,'professional_title'=>$m->profile?->professional_title])->all(),
  'credential_health'=>ProfessionalCredential::query()->get()->map(fn($c)=>['member_id'=>$c->member_id,'category'=>$c->category,'credential_type'=>$c->credential_type,'title'=>$c->title,'country'=>$c->country,'expires_on'=>optional($c->expires_on)->toDateString(),'credential_status'=>$c->credential_status,'verification_status'=>$c->verification_status])->all(),
  default=>throw new RuntimeException('Unsupported report type.'),};}
 private function csv(array $rows):string{if(!$rows)return "No data\n";$fp=fopen('php://temp','r+');fputcsv($fp,array_keys($rows[0]));foreach($rows as $row)fputcsv($fp,array_map([$this,'safeCell'],$row));rewind($fp);$out=stream_get_contents($fp);fclose($fp);return $out;}
 private function safeCell(mixed $value):string{$v=(string)($value??'');if($v!==''&&in_array($v[0],['=','+','-','@'],true))$v="'".$v;return $v;}
}
