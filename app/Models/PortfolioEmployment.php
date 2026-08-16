<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PortfolioEmployment extends Model { use HasUuids; protected $table='portfolio_employment'; protected $guarded=[]; protected function casts(): array { return ['started_on'=>'date','ended_on'=>'date','is_current'=>'boolean','verified_at'=>'datetime']; } public function member(): BelongsTo{return $this->belongsTo(Member::class);} }
