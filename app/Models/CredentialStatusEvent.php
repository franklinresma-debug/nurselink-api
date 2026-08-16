<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CredentialStatusEvent extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['occurred_at'=>'datetime'];} public function credential():BelongsTo{return $this->belongsTo(ProfessionalCredential::class,'credential_id');} }
