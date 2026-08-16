<?php
namespace App\Services\Credentials;
use App\Models\Member; use App\Models\MemberDocument; use App\Models\User; use Illuminate\Http\UploadedFile; use Illuminate\Support\Facades\Storage; use Illuminate\Validation\ValidationException;
class MemberDocumentStorageService
{
    private const ALLOWED=['application/pdf','image/jpeg','image/png','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    public function store(Member $member,User $user,UploadedFile $file,string $type,?string $title=null,?string $issuedOn=null,?string $expiresOn=null):MemberDocument
    {
        if(!in_array($file->getMimeType(),self::ALLOWED,true))throw ValidationException::withMessages(['file'=>'Unsupported document type.']);
        if($file->getSize()>15*1024*1024)throw ValidationException::withMessages(['file'=>'Document exceeds the 15 MB limit.']);
        $sha=hash_file('sha256',$file->getRealPath()); $stored=(string)str()->uuid().'.'.($file->guessExtension()?:'bin'); $path="members/{$member->id}/documents/{$stored}";
        Storage::disk(config('credentials.private_disk','private'))->put($path,file_get_contents($file->getRealPath()));
        return MemberDocument::query()->create(['member_id'=>$member->id,'document_type'=>$type,'title'=>$title?:$file->getClientOriginalName(),'original_name'=>$file->getClientOriginalName(),'storage_disk'=>config('credentials.private_disk','private'),'storage_path'=>$path,'mime_type'=>$file->getMimeType(),'size_bytes'=>$file->getSize(),'sha256'=>$sha,'security_status'=>'pending','visibility'=>'private','issued_on'=>$issuedOn,'expires_on'=>$expiresOn]);
    }
}
