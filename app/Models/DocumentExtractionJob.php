<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class DocumentExtractionJob extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected function casts(): array { return ['result_meta'=>'array','started_at'=>'datetime','completed_at'=>'datetime']; }
    public function document(): BelongsTo { return $this->belongsTo(ApplicationDocument::class, 'application_document_id'); }
    public function facts(): HasMany { return $this->hasMany(ExtractedFact::class, 'extraction_job_id'); }
}
