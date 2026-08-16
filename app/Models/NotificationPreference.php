<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\BelongsTo;
class NotificationPreference extends Model { use HasUuids; protected $guarded=[]; protected function casts():array{return ['in_app_enabled'=>'boolean','email_enabled'=>'boolean','sms_enabled'=>'boolean','push_enabled'=>'boolean','whatsapp_enabled'=>'boolean','category_preferences'=>'array'];} public function user():BelongsTo{return $this->belongsTo(User::class);} }
