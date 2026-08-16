<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class PortfolioTimelineEvent extends Model { use HasUuids; protected $table='portfolio_timeline_events'; protected $guarded=[]; protected function casts():array{return ['occurred_on'=>'date'];} public function member():BelongsTo{return $this->belongsTo(Member::class);} }
