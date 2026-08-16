<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationStatusEvent extends Model
{
    use HasUuids;
    public const UPDATED_AT = null;
    protected $guarded = [];
    protected function casts(): array { return ['metadata' => 'array']; }
    public function application(): BelongsTo { return $this->belongsTo(Application::class); }
    public function actor(): BelongsTo { return $this->belongsTo(User::class, 'actor_user_id'); }
}
