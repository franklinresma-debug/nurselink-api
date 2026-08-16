<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FrameworkLevelCrosswalk extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['validated_at'=>'datetime'];} public function sourceLevel():BelongsTo{return $this->belongsTo(QualificationFrameworkLevel::class,'source_level_id');} public function targetLevel():BelongsTo{return $this->belongsTo(QualificationFrameworkLevel::class,'target_level_id');} }
