<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class QualificationRecommendation extends Model { use HasUuids; protected $guarded=[]; public function assessment():BelongsTo{return $this->belongsTo(QualificationAssessment::class,'assessment_id');} public function requirement():BelongsTo{return $this->belongsTo(QualificationRequirement::class,'requirement_id');} }
