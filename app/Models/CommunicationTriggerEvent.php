<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CommunicationTriggerEvent extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['payload'=>'array','occurred_at'=>'datetime','processed_at'=>'datetime'];} public function user():BelongsTo{return $this->belongsTo(User::class);} }
