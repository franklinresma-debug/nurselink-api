<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo; use Illuminate\Database\Eloquent\Relations\HasOne;
class EventRegistration extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['registered_at'=>'datetime','cancelled_at'=>'datetime','attended_at'=>'datetime'];} public function event():BelongsTo{return $this->belongsTo(Event::class);} public function member():BelongsTo{return $this->belongsTo(Member::class);} public function certificate():HasOne{return $this->hasOne(EventCertificate::class);} }
