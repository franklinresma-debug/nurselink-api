<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo; use Illuminate\Database\Eloquent\Relations\HasMany;
class QualificationFrameworkLevel extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['descriptors'=>'array'];} public function framework():BelongsTo{return $this->belongsTo(QualificationFramework::class,'framework_id');} public function requirements():HasMany{return $this->hasMany(QualificationRequirement::class,'framework_level_id');} }
