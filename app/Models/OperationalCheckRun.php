<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model;
class OperationalCheckRun extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['checks'=>'array','checked_at'=>'datetime'];} }
