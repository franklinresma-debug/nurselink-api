<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InitiativeUpdate extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['published_at'=>'datetime','notify_members'=>'boolean','audience_filters'=>'array'];} public function initiative():BelongsTo{return $this->belongsTo(Initiative::class);} public function author():BelongsTo{return $this->belongsTo(User::class,'created_by');} }
