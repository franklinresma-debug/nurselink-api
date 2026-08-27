<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NurseLinkDesignSetting extends Model
{
    protected $table = 'nurselink_design_settings';

    protected $fillable = [
        'scope','version','status','payload','created_by',
        'published_by','published_at','restored_from_version',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'version' => 'integer',
            'published_at' => 'datetime',
            'restored_from_version' => 'integer',
        ];
    }
}
