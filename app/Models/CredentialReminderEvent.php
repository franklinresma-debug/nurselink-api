<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CredentialReminderEvent extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['trigger_on'=>'date','queued_at'=>'datetime','delivered_at'=>'datetime','suppressed_at'=>'datetime'];} public function credential():BelongsTo{return $this->belongsTo(ProfessionalCredential::class,'credential_id');} public function member():BelongsTo{return $this->belongsTo(Member::class);} }
