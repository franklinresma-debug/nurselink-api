<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PortfolioEducation extends Model { use HasUuids; protected $table='portfolio_education'; protected $guarded=[]; protected function casts(): array { return ['started_on'=>'date','completed_on'=>'date','verified_at'=>'datetime']; } public function member(): BelongsTo{return $this->belongsTo(Member::class);} }
