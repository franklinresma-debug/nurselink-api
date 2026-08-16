<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
class AnalyticsSnapshot extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['snapshot_date'=>'date','metrics'=>'array','dimensions'=>'array','captured_at'=>'datetime'];} }
