<?php
namespace App\Services\Credentials;
use App\Models\Application; use App\Models\Member; use App\Models\MemberDocument;
class MemberDocumentImportService
{
    public function fromApprovedApplication(Member $member,Application $application):int
    {
        $count=0;
        foreach($application->documents()->get() as $source){
            MemberDocument::query()->firstOrCreate(['member_id'=>$member->id,'source_application_document_id'=>$source->id],[
                'document_type'=>$source->category,'title'=>$source->original_name,'original_name'=>$source->original_name,'storage_disk'=>$source->disk,'storage_path'=>$source->path,'mime_type'=>$source->mime_type,'size_bytes'=>$source->size_bytes,'sha256'=>$source->sha256,'security_status'=>$source->malware_scan_status==='clean'?'clean':$source->malware_scan_status,'visibility'=>'private',
            ]);$count++;
        }
        return $count;
    }
}
