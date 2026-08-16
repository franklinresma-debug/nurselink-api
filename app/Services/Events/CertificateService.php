<?php
namespace App\Services\Events;
use App\Models\EventCertificate; use App\Models\EventRegistration; use App\Services\IdentifierService; use Illuminate\Support\Str;
class CertificateService { public function __construct(private IdentifierService $ids){} public function issue(EventRegistration $r,?string $issuerId=null):EventCertificate{abort_unless($r->status==='attended'&&$r->event->certificate_enabled,422,'Attendance and certificate-enabled event are required.');return EventCertificate::query()->firstOrCreate(['event_registration_id'=>$r->id],['certificate_no'=>$this->ids->next('event_certificate','NLCERT'),'verification_token'=>hash('sha256',Str::uuid().Str::random(40)),'issued_at'=>now(),'issued_by'=>$issuerId]);} }
