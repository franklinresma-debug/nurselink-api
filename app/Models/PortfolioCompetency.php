<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PortfolioCompetency extends Model { use HasUuids; protected $table='portfolio_competencies'; protected $guarded=[]; protected function casts():array{return ['assessed_at'=>'datetime','verified_at'=>'datetime'];} public function member():BelongsTo{return $this->belongsTo(Member::class);} }
