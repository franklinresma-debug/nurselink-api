<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MigrationStaging\StagingStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminMigrationStagingController extends Controller
{
    protected function store(): StagingStore
    {
        return new StagingStore(storage_path('app/private/lbs-migration-staging'));
    }
    protected function owner(Request $request): string
    {
        $user=$request->user();abort_unless($user,401);
        $id=(string)$user->getKey();
        $granular=Schema::hasTable('nurselink_admin_role_assignments') && DB::table('nurselink_admin_role_assignments')->where('user_id',$id)->where('active',true)->where('role_key','super_administrator')->exists();
        abort_unless($granular || (method_exists($user,'hasRole') && $user->hasRole('super_administrator')),403,'Super Administrator access is required for migration staging.');
        return $id;
    }
    private function result(callable $fn)
    {
        try{return response()->json(['data'=>$fn()])->header('Cache-Control','no-store, private');}
        catch(\RuntimeException $e){return response()->json(['message'=>$e->getMessage()],422)->header('Cache-Control','no-store, private');}
    }
    public function index(Request $request)
    {
        $owner=$this->owner($request);
        return $this->result(fn()=>['batches'=>$this->store()->listing($owner),'fields'=>StagingStore::FIELDS,'limits'=>['file_bytes'=>StagingStore::MAX_BYTES,'rows'=>StagingStore::MAX_ROWS],'production_import_enabled'=>false]);
    }
    public function upload(Request $request)
    {
        $owner=$this->owner($request);
        $v=$request->validate(['file'=>['required','file','max:10240'],'source_system'=>['required','string','max:80'],'name'=>['required','string','max:120'],'delimiter'=>['nullable','in:comma,semicolon,tab']]);
        $file=$request->file('file');$delimiter=match($v['delimiter']??'comma'){'tab'=>"\t",'semicolon'=>';',default=>','};
        return $this->result(fn()=>$this->store()->upload($file->getRealPath(),$file->getClientOriginalName(),$owner,$v['source_system'],$v['name'],$delimiter));
    }
    public function show(Request $request,string $batch)
    {
        $owner=$this->owner($request);$v=$request->validate(['page'=>['nullable','integer','min:1','max:40']]);
        return $this->result(fn()=>$this->store()->show($batch,$owner,(int)($v['page']??1)));
    }
    public function validateMapping(Request $request,string $batch)
    {
        $owner=$this->owner($request);$v=$request->validate(['mapping'=>['required','array','max:10'],'mapping.*'=>['nullable','string','max:100']]);
        return $this->result(fn()=>$this->store()->validate($batch,$owner,$v['mapping'],function(array $emails){
            $found=[];
            foreach(array_chunk(array_values(array_filter($emails)),300) as $chunk){
                $values=DB::table('users')->whereIn(DB::raw('LOWER(TRIM(email))'),$chunk)->pluck('email');
                foreach($values as $email)$found[]=mb_strtolower(trim($email));
            }
            return $found;
        }));
    }
    public function download(Request $request,string $batch)
    {
        $owner=$this->owner($request);
        try{$data=$this->store()->review($batch,$owner);}
        catch(\RuntimeException $e){return response()->json(['message'=>$e->getMessage()],422);}
        return response()->json($data,200, ['Cache-Control'=>'no-store, private','X-Content-Type-Options'=>'nosniff','Content-Disposition'=>'attachment; filename="staging-review-'.$batch.'.json"'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    }
}
