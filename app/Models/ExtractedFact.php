<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ExtractedFact extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected function casts(): array { return ['confidence'=>'decimal:4','member_confirmed_at'=>'datetime','verified_at'=>'datetime']; }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function document(): BelongsTo { return $this->belongsTo(ApplicationDocument::class, 'application_document_id'); }
    public function job(): BelongsTo { return $this->belongsTo(DocumentExtractionJob::class, 'extraction_job_id'); }
}
