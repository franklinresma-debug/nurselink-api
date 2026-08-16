<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ProfessionalDevelopmentRecord extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['completed_on'=>'date','cpd_units'=>'decimal:2','hours'=>'decimal:2','verified_at'=>'datetime'];} public function member():BelongsTo{return $this->belongsTo(Member::class);} public function evidenceDocument():BelongsTo{return $this->belongsTo(MemberDocument::class,'evidence_document_id');} }
