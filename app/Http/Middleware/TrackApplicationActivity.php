<?php
namespace App\Http\Middleware;
use Closure;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;
class TrackApplicationActivity {
 public function handle(Request $r,Closure $next){$response=$next($r);if(in_array($r->method(),['GET','HEAD','OPTIONS'])||$response->getStatusCode()>=400)return $response;
  app(\App\Services\ApplicationProgressService::class)->activity($r->user());
  if(in_array(basename($r->path()),['personal','professional'])){$row=DB::table('nurselink_application_support')->where('user_id',$r->user()->id)->first();$draft=json_decode($row->draft_data??'{}',true)?:[];unset($draft[basename($r->path())]);$rev=(int)$row->draft_revision+1;DB::table('nurselink_application_support')->where('id',$row->id)->update(['draft_data'=>json_encode($draft),'draft_revision'=>$rev]);if($response instanceof \Illuminate\Http\JsonResponse){$data=$response->getData(true);$data['sync_revision']=$rev;$response->setData($data);}}
  return $response;
 }
}
