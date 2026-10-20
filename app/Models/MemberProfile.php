<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberProfile extends Model
{
    use HasUuids;
    protected $guarded = [];
    protected function casts(): array { return ['date_of_birth' => 'date:Y-m-d', 'profile_meta' => 'array']; }
    public function member(): BelongsTo { return $this->belongsTo(Member::class); }
}
