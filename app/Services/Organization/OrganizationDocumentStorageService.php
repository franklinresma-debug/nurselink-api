<?php
namespace App\Services\Organization;
use App\Models\Initiative; use App\Models\InitiativeDocument; use App\Models\PolicyDocument; use App\Models\PolicyRecord; use App\Models\User; use Illuminate\Http\UploadedFile; use Illuminate\Support\Facades\Storage;
class OrganizationDocumentStorageService {
 public function initiative(Initiative $i,User $u,UploadedFile $f,array $data=[]):InitiativeDocument{$path=$this->store('initiatives/'.$i->id,$f);return InitiativeDocument::query()->create($this->attrs($f,$path,$u,$data)+['initiative_id'=>$i->id]);}
 public function policy(PolicyRecord $p,User $u,UploadedFile $f,array $data=[]):PolicyDocument{$path=$this->store('policies/'.$p->id,$f);return PolicyDocument::query()->create($this->attrs($f,$path,$u,$data)+['policy_record_id'=>$p->id]);}
 private function store(string $base,UploadedFile $f):string{$name=(string)str()->uuid().'.'.($f->guessExtension()?:'bin');$path=$base.'/'.$name;Storage::disk(config('organization.private_disk','private'))->put($path,file_get_contents($f->getRealPath()));return $path;}
 private function attrs(UploadedFile $f,string $path,User $u,array $data):array{return ['title'=>$data['title']??$f->getClientOriginalName(),'document_type'=>$data['document_type']??'supporting','original_name'=>$f->getClientOriginalName(),'storage_disk'=>config('organization.private_disk','private'),'storage_path'=>$path,'mime_type'=>$f->getMimeType(),'size_bytes'=>$f->getSize(),'sha256'=>hash_file('sha256',$f->getRealPath()),'security_status'=>'pending','visibility'=>$data['visibility']??'internal','uploaded_by'=>$u->id];}
}
