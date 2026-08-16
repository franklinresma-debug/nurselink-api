<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PortfolioSpecialty extends Model { use HasUuids; protected $table='portfolio_specialties'; protected $guarded=[]; public function member():BelongsTo{return $this->belongsTo(Member::class);} }
