<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ApplicationDataReview extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected function casts(): array { return ['rule_meta'=>'array','resolved_at'=>'datetime']; }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
}
