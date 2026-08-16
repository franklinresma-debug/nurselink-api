<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class QualificationAssessmentResult extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['score'=>'decimal:2','experience_years'=>'decimal:2','evidence_snapshot'=>'array'];} public function assessment():BelongsTo{return $this->belongsTo(QualificationAssessment::class,'assessment_id');} public function requirement():BelongsTo{return $this->belongsTo(QualificationRequirement::class,'requirement_id');} }
