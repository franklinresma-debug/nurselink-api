<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo; use Illuminate\Database\Eloquent\Relations\HasMany;
class CampaignRecipient extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['resolved_channels'=>'array','suppressed_channels'=>'array','queued_at'=>'datetime','processed_at'=>'datetime'];} public function campaign():BelongsTo{return $this->belongsTo(CommunicationCampaign::class,'campaign_id');} public function user():BelongsTo{return $this->belongsTo(User::class);} public function deliveryAttempts():HasMany{return $this->hasMany(DeliveryAttempt::class,'campaign_recipient_id');} }
