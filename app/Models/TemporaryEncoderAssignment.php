<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TemporaryEncoderAssignment extends Model
{
    protected $table = 'temporary_encoder_assignments';

    protected $fillable = [
        'encoder_account_id',
        'nurse_user_id',
        'active',
        'expires_at',
        'assigned_by_user_id',
        'assignment_reason',
        'revoked_at',
        'revoked_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function encoder(): BelongsTo
    {
        return $this->belongsTo(
            TemporaryEncoderAccount::class,
            'encoder_account_id'
        );
    }

    public function isUsable(): bool
    {
        return $this->active
            && ! $this->revoked_at
            && (
                ! $this->expires_at
                || $this->expires_at->isFuture()
            );
    }
}
