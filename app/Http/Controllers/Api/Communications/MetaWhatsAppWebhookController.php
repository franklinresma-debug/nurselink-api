<?php
namespace App\Http\Controllers\Api\Communications;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAttempt;
use Illuminate\Http\Request;

class MetaWhatsAppWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $settings = app(\App\Services\Communications\MetaWhatsAppSettings::class);
        $settings->apply();
        $token = (string) config('whatsapp.verify_token');
        // PHP normalizes dots in query-string keys to underscores.
        abort_unless($token !== '' && $request->query('hub_mode') === 'subscribe'
            && hash_equals($token, (string) $request->query('hub_verify_token')), 403);
        $challenge = (string) $request->query('hub_challenge');
        abort_unless(preg_match('/^\d{1,100}$/', $challenge), 400);
        $settings->update(function ($s) use ($token) {
            if (hash_equals((string)($s['verify_token']??''), $token)) $s['webhook_verified_at'] = now()->toIso8601String();
            return $s;
        });
        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request)
    {
        app(\App\Services\Communications\MetaWhatsAppSettings::class)->apply();
        $secret = (string) config('whatsapp.app_secret');
        abort_unless($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $secret), (string)$request->header('X-Hub-Signature-256')), 403);
        abort_unless($request->input('object') === 'whatsapp_business_account', 400);
        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                if ((string)($value['metadata']['phone_number_id'] ?? '') !== (string)config('whatsapp.phone_number_id')) continue;
                foreach ($value['statuses'] ?? [] as $event) {
                    $status = $event['status'] ?? '';
                    if (!in_array($status, ['sent', 'delivered', 'read', 'failed'], true) || empty($event['id'])) continue;
                    $query = DeliveryAttempt::query()->where('channel', 'whatsapp')->where('provider', 'meta_cloud')->where('provider_message_id', $event['id']);
                    // Ask Meta to retry if the callback overtook the initial attempt insert.
                    if (!(clone $query)->exists()) return response()->json(['retry'=>true], 503);
                    if (in_array($status, ['delivered', 'read'], true)) {
                        $query->where('status', '!=', 'delivered')->update(['status'=>'delivered', 'delivered_at'=>now(), 'error_code'=>null, 'error_message'=>null]);
                    } elseif ($status === 'failed') {
                        $code = $event['errors'][0]['code'] ?? null;
                        $query->whereNotIn('status', ['delivered', 'failed'])->update(['status'=>'failed', 'error_code'=>is_numeric($code) ? 'meta_'.(int)$code : 'meta_delivery_failed']);
                    }
                    // 'sent' is acceptance, not delivery; do not regress a later status.
                }
            }
        }
        return response()->json(['received'=>true]);
    }
}
