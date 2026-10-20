<?php
namespace App\Services\Communications;

/** Separate encrypted configuration; Messenger never shares WhatsApp credentials. */
class MetaMessengerSettings extends MetaWhatsAppSettings
{
    public function path(): string { return config('messenger.settings_path', storage_path('app/private/integrations/meta-messenger.enc')); }
    public function effective(): array { return array_replace(config('messenger', []), $this->stored()); }
    public function apply(): void { /* Messenger setup does not enable message sending. */ }
    public function publicStatus(): array
    {
        $s=$this->effective();$secrets=[];
        foreach(['access_token','app_secret','verify_token'] as $key)$secrets[$key]=!empty($s[$key]);
        return ['revision'=>(int)($s['revision']??0),'page_id'=>$s['page_id']??'','app_id'=>$s['app_id']??'',
            'graph_version'=>$s['graph_version']??'','secrets_configured'=>$secrets,'connection'=>$s['connection']??null,
            'webhook_verified_at'=>$s['webhook_verified_at']??null,'last_event_at'=>$s['last_event_at']??null,
            'updated_at'=>$s['updated_at']??null,'sending_enabled'=>false,
            'webhook_url'=>'https://lbs.amsertech.com/backend/api/communications/messenger/webhook'];
    }
}
