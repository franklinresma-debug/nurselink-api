<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ReportExportJob extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['filters'=>'array','started_at'=>'datetime','completed_at'=>'datetime','expires_at'=>'datetime'];} public function requester():BelongsTo{return $this->belongsTo(User::class,'requested_by');} }
