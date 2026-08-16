<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InitiativePartner extends Model { use HasUuids; protected $guarded=[]; public function initiative():BelongsTo{return $this->belongsTo(Initiative::class);} }
