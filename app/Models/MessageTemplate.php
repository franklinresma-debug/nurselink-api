<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
class MessageTemplate extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['channel_overrides'=>'array'];} }
