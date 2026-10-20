<?php
namespace App\Services\Communications\Channels;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

/** Direct Meta transport. Only explicitly mapped, approved templates are sent. */
class MetaWhatsAppChannelProvider implements ChannelProvider
{
    public function name(): string { return 'meta_cloud'; }

    public function send(User $user, string $subject, string $body, array $context = []): array
    {
        app(\App\Services\Communications\MetaWhatsAppSettings::class)->apply();
        if (!config('communications.external_delivery_enabled.whatsapp')) return $this->result('skipped', 'channel_disabled');
        $settings = config('whatsapp', []);
        foreach (['access_token', 'phone_number_id', 'graph_version'] as $key) {
            if (empty($settings[$key])) return $this->result('skipped', 'provider_not_configured');
        }
        if (!preg_match('/^v\d+\.\d+$/', $settings['graph_version']) || !ctype_digit((string) $settings['phone_number_id'])) {
            return $this->result('skipped', 'invalid_provider_configuration');
        }
        if (!$user->notificationPreference?->whatsapp_enabled) return $this->result('suppressed', 'member_preference');
        $phone = preg_replace('/[\s()+-]/', '', (string) $user->member?->profile?->mobile_phone);
        if (preg_match('/^09\d{9}$/', $phone)) $phone = '63'.substr($phone, 1);
        if (!preg_match('/^[1-9]\d{7,14}$/', $phone)) return $this->result('skipped', 'invalid_recipient');
        $key = $context['whatsapp_template_key'] ?? '';
        $template = $settings['templates'][$key] ?? null;
        if (!is_array($template) || empty($template['name']) || empty($template['language'])) {
            return $this->result('skipped', 'approved_template_required');
        }
        $values = $context['whatsapp_parameters'] ?? [];
        if (!is_array($values) || !array_is_list($values) || count($values) !== (int) ($template['parameter_count'] ?? 0)) {
            return $this->result('skipped', 'invalid_template_parameters');
        }
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 1024) return $this->result('skipped', 'invalid_template_parameters');
        }
        $payload = ['messaging_product'=>'whatsapp', 'to'=>$phone, 'type'=>'template', 'template'=>['name'=>$template['name'], 'language'=>['code'=>$template['language']]]];
        if ($values) $payload['template']['components'] = [['type'=>'body', 'parameters'=>array_map(fn($value)=>['type'=>'text', 'text'=>$value], $values)]];
        try {
            // No automatic retry: a timeout may occur after Meta accepted a billable message.
            $response = Http::withToken($settings['access_token'])->acceptJson()->connectTimeout(5)->timeout(15)
                ->post('https://graph.facebook.com/'.$settings['graph_version'].'/'.$settings['phone_number_id'].'/messages', $payload);
        } catch (ConnectionException $e) {
            return $this->result('unknown', 'delivery_outcome_unknown');
        }
        $id = $response->json('messages.0.id');
        if ($response->successful() && is_string($id) && $id !== '') return ['status'=>'sent', 'provider'=>$this->name(), 'provider_message_id'=>$id];
        if ($response->successful() || $response->serverError()) return $this->result('unknown', 'delivery_outcome_unknown');
        $code = $response->json('error.code');
        return $this->result('failed', is_numeric($code) ? 'meta_'.(int)$code : 'meta_request_rejected');
    }

    private function result(string $status, string $code): array
    {
        return ['status'=>$status, 'provider'=>$this->name(), 'error_code'=>$code];
    }
}
