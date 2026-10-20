<?php
namespace App\Services\Communications;

use Illuminate\Support\Facades\Crypt;

class MetaWhatsAppSettings
{
    public function path(): string { return config('whatsapp.settings_path', storage_path('app/private/integrations/meta-whatsapp.enc')); }

    public function stored(): array
    {
        if (!is_file($this->path())) return [];
        return json_decode(Crypt::decryptString(file_get_contents($this->path())), true, 64, JSON_THROW_ON_ERROR);
    }

    public function effective(): array
    {
        return array_replace(config('whatsapp', []), $this->stored());
    }

    public function apply(): void
    {
        if (!is_file($this->path())) return;
        $settings = $this->effective();
        config(['whatsapp'=>$settings, 'communications.external_providers.whatsapp'=>'meta_cloud', 'communications.external_delivery_enabled.whatsapp'=>false]);
    }

    public function update(callable $change): array
    {
        $dir = dirname($this->path());
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('Unable to save integration settings.');
        $lock = fopen($this->path().'.lock', 'c');
        if (!$lock) throw new \RuntimeException('Unable to lock integration settings.');
        chmod($this->path().'.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Unable to lock integration settings.');
            $next = $change($this->effective());
            $temp = tempnam($dir, '.meta-');
            try {
                chmod($temp, 0600);
                if (file_put_contents($temp, Crypt::encryptString(json_encode($next, JSON_THROW_ON_ERROR))) === false || !rename($temp, $this->path())) throw new \RuntimeException('Unable to save integration settings.');
            } finally { if (is_file($temp)) unlink($temp); }
            return $next;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function publicStatus(): array
    {
        $s = $this->effective();
        $secrets = [];
        foreach (['access_token','app_secret','verify_token'] as $key) $secrets[$key] = !empty($s[$key]);
        return [
            'revision'=>(int)($s['revision']??0), 'phone_number_id'=>$s['phone_number_id']??'',
            'business_account_id'=>$s['business_account_id']??'', 'graph_version'=>$s['graph_version']??'',
            'secrets_configured'=>$secrets, 'templates'=>$s['templates']??[],
            'connection'=>$s['connection']??null, 'webhook_verified_at'=>$s['webhook_verified_at']??null,
            'updated_at'=>$s['updated_at']??null, 'sending_enabled'=>false,
            'webhook_url'=>'https://lbs.amsertech.com/backend/api/communications/whatsapp/webhook',
        ];
    }
}
