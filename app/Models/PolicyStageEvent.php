<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PolicyStageEvent extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['occurred_at'=>'datetime','notify_members'=>'boolean','audience_filters'=>'array'];} public function policy():BelongsTo{return $this->belongsTo(PolicyRecord::class,'policy_record_id');} public function actor():BelongsTo{return $this->belongsTo(User::class,'changed_by');} }
