<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class ApplicationDocument extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected function casts(): array { return ['malware_scanned_at'=>'datetime','extracted_at'=>'datetime']; }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by_user_id'); }
    public function extractionJobs(): HasMany { return $this->hasMany(DocumentExtractionJob::class); }
    public function facts(): HasMany { return $this->hasMany(ExtractedFact::class); }
}
