<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class InboxMessage extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['published_at'=>'datetime','read_at'=>'datetime','archived_at'=>'datetime'];} public function user():BelongsTo{return $this->belongsTo(User::class);} public function campaign():BelongsTo{return $this->belongsTo(CommunicationCampaign::class,'campaign_id');} }
