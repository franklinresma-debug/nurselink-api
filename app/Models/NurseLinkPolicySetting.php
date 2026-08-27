<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NurseLinkPolicySetting extends Model
{
    protected $table = 'nurselink_policy_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'terms_effective_date' => 'date',
            'privacy_effective_date' => 'date',
            'published_at' => 'datetime',
        ];
    }
}
