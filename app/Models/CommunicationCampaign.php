<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo; use Illuminate\Database\Eloquent\Relations\HasMany;
class CommunicationCampaign extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['channels'=>'array','audience_filters'=>'array','scheduled_at'=>'datetime','started_at'=>'datetime','completed_at'=>'datetime','approved_at'=>'datetime'];} public function recipients():HasMany{return $this->hasMany(CampaignRecipient::class,'campaign_id');} public function template():BelongsTo{return $this->belongsTo(MessageTemplate::class,'template_id');} public function creator():BelongsTo{return $this->belongsTo(User::class,'created_by');} }
