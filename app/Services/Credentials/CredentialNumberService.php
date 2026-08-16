<?php
namespace App\Services\Credentials;
class CredentialNumberService
{
    public function fingerprint(?string $value):?string
    {
        if (!$value) return null;
        $normalized=mb_strtoupper(preg_replace('/\s+/', '', trim($value)));
        return hash_hmac('sha256',$normalized,(string)config('app.key'));
    }
    public function last4(?string $value):?string
    {
        if (!$value) return null;
        $v=preg_replace('/\s+/', '', trim($value));
        return mb_substr($v,-4);
    }
}
