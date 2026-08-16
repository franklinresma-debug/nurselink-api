<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InitiativeBudgetLine extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['planned_amount'=>'decimal:2','committed_amount'=>'decimal:2','spent_amount'=>'decimal:2'];} public function initiative():BelongsTo{return $this->belongsTo(Initiative::class);} }
