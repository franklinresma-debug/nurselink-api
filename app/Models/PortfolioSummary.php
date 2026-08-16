<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PortfolioSummary extends Model { use HasUuids; protected $guarded=[]; protected function casts(): array { return ['available_for_opportunities'=>'boolean']; } public function member(): BelongsTo { return $this->belongsTo(Member::class); } }
