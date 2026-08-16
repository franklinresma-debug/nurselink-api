<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo; use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class MemberDocument extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['issued_on'=>'date','expires_on'=>'date'];} public function member():BelongsTo{return $this->belongsTo(Member::class);} public function credentials():BelongsToMany{return $this->belongsToMany(ProfessionalCredential::class,'credential_documents','document_id','credential_id')->withPivot('purpose')->withTimestamps();} }
