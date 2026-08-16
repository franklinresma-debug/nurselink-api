<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Concerns\HasUuids; use Illuminate\Database\Eloquent\Model; use Illuminate\Database\Eloquent\Relations\HasMany;
class Event extends Model { use HasUuids; protected $guarded=[]; protected $hidden=['online_url']; protected function casts():array{return ['starts_at'=>'datetime','ends_at'=>'datetime','registration_opens_at'=>'datetime','registration_closes_at'=>'datetime','waitlist_enabled'=>'boolean','certificate_enabled'=>'boolean','audience_filters'=>'array','online_url'=>'encrypted'];} public function registrations():HasMany{return $this->hasMany(EventRegistration::class);} }
