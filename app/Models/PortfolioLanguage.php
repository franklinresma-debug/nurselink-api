<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PortfolioLanguage extends Model { use HasUuids; protected $table='portfolio_languages'; protected $guarded=[]; public function member():BelongsTo{return $this->belongsTo(Member::class);} }
