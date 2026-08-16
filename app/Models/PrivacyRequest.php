<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PrivacyRequest extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['submitted_at'=>'datetime','acknowledged_at'=>'datetime','completed_at'=>'datetime','export_expires_at'=>'datetime'];} public function user():BelongsTo{return $this->belongsTo(User::class);} public function assignee():BelongsTo{return $this->belongsTo(User::class,'assigned_to');} }
