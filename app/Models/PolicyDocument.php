<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PolicyDocument extends Model { use HasUuids; protected $guarded=[]; public function policy():BelongsTo{return $this->belongsTo(PolicyRecord::class,'policy_record_id');} }
