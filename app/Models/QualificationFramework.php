<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\HasMany;
class QualificationFramework extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['assessment_enabled'=>'boolean','effective_from'=>'date','effective_to'=>'date','validated_at'=>'datetime'];} public function levels():HasMany{return $this->hasMany(QualificationFrameworkLevel::class,'framework_id')->orderBy('ordinal');} public function requirements():HasMany{return $this->hasMany(QualificationRequirement::class,'framework_id');} }
