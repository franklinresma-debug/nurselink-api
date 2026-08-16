<?php
namespace App\Http\Controllers\Api\Admin\Organization;
use App\Http\Controllers\Controller; use App\Http\Requests\Organization\UploadOrganizationDocumentRequest; use App\Models\Initiative; use App\Models\InitiativeDocument; use App\Models\PolicyDocument; use App\Models\PolicyRecord; use App\Services\AuditLogger; use App\Services\Organization\OrganizationDocumentStorageService; use Illuminate\Support\Facades\Storage;
class OrganizationDocumentController extends Controller {
 public function __construct(private OrganizationDocumentStorageService $storage,private AuditLogger $audit){}
 public function initiative(UploadOrganizationDocumentRequest $r,Initiative $initiative){$doc=$this->storage->initiative($initiative,$r->user(),$r->file('file'),$r->validated());$this->audit->write('initiative.document_uploaded',$r->user(),'initiative',$initiative->id,['document_id'=>$doc->id]);return response()->json($doc,201);}
 public function policy(UploadOrganizationDocumentRequest $r,PolicyRecord $policy){$doc=$this->storage->policy($policy,$r->user(),$r->file('file'),$r->validated());$this->audit->write('policy.document_uploaded',$r->user(),'policy_record',$policy->id,['document_id'=>$doc->id]);return response()->json($doc,201);}
 public function initiativeDownload(InitiativeDocument $document){abort_unless($document->security_status==='clean',423,'Document security scan must complete before download.');return Storage::disk($document->storage_disk)->download($document->storage_path,$document->original_name);}
 public function policyDownload(PolicyDocument $document){abort_unless($document->security_status==='clean',423,'Document security scan must complete before download.');return Storage::disk($document->storage_disk)->download($document->storage_path,$document->original_name);}
}
