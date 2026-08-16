<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasUuids, Notifiable, TwoFactorAuthenticatable;

    protected $fillable = ['name','email','password','status','mfa_required'];
    protected $hidden = ['password','remember_token','two_factor_secret','two_factor_recovery_codes'];
    protected function casts(): array { return ['email_verified_at'=>'datetime','password'=>'hashed','mfa_required'=>'boolean','two_factor_confirmed_at'=>'datetime']; }

    public function roles(): BelongsToMany { return $this->belongsToMany(Role::class)->withPivot('assigned_at'); }
    public function application(): HasOne { return $this->hasOne(Application::class); }
    public function member(): HasOne { return $this->hasOne(Member::class); }
    public function notificationPreference(): HasOne { return $this->hasOne(NotificationPreference::class); }
    public function inboxMessages(): HasMany { return $this->hasMany(InboxMessage::class); }
    public function hasRole(string $code): bool { return $this->roles()->where('code',$code)->exists(); }
    public function hasPermission(string $code): bool {
        if ($this->hasRole('super_administrator')) return true;
        return $this->roles()->whereHas('permissions', fn($q) => $q->where('code',$code))->exists();
    }
    public function roleCodes(): array { return $this->roles()->pluck('code')->all(); }
}
