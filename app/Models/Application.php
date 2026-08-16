<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Application extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'profile_data' => 'array',
            'submitted_at' => 'datetime',
            'review_started_at' => 'datetime',
            'returned_at' => 'datetime',
            'resubmitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'current_reviewer_user_id'); }
    public function events(): HasMany { return $this->hasMany(ApplicationStatusEvent::class); }
    public function member(): HasOne { return $this->hasOne(Member::class, 'approved_from_application_id'); }
    public function documents(): HasMany { return $this->hasMany(ApplicationDocument::class); }
    public function extractedFacts(): HasMany { return $this->hasMany(ExtractedFact::class); }
    public function dataReviews(): HasMany { return $this->hasMany(ApplicationDataReview::class); }
}
