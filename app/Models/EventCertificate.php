<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class EventCertificate extends Model { use HasUuids; protected $guarded=[]; protected $hidden=['verification_token']; protected function casts():array{return ['issued_at'=>'datetime'];} public function registration():BelongsTo{return $this->belongsTo(EventRegistration::class,'event_registration_id');} }
