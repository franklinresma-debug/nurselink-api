<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
class DeliveryAttempt extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['attempted_at'=>'datetime','delivered_at'=>'datetime','metadata'=>'array'];} }
