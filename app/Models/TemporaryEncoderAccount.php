<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class TemporaryEncoderAccount extends Authenticatable
{
    use Notifiable;

    protected $table = 'temporary_encoder_accounts';

    protected $fillable = [
        'username',
        'email',
        'display_name',
        'password',
        'active',
        'expires_at',
        'created_by_user_id',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'active' => 'boolean',
            'expires_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(
            TemporaryEncoderAssignment::class,
            'encoder_account_id'
        );
    }

    public function isUsable(): bool
    {
        return $this->active
            && $this->expires_at
            && $this->expires_at->isFuture();
    }
}
