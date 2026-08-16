<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class QualificationRequirement extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['mandatory'=>'boolean','weight'=>'decimal:2','evidence_rule'=>'array'];} public function framework():BelongsTo{return $this->belongsTo(QualificationFramework::class,'framework_id');} public function level():BelongsTo{return $this->belongsTo(QualificationFrameworkLevel::class,'framework_level_id');} }
