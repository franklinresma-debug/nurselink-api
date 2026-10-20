<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NurseLinkPolicyVersion extends Model
{
    protected $table = 'nurselink_policy_versions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'published_at' => 'datetime',
        ];
    }
}
