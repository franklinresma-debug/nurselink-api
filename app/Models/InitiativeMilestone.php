<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InitiativeMilestone extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['due_on'=>'date','completed_at'=>'datetime','weight'=>'decimal:2'];} public function initiative():BelongsTo{return $this->belongsTo(Initiative::class);} }
