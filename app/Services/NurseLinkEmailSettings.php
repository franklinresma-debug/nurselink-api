<?php
namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class NurseLinkEmailSettings
{
    public function values(): array
    {
        $smtp = config('mail.mailers.smtp', []);
        $defaults = ['mailbox'=>config('email_management.mailbox'), 'allowed_admins'=>config('email_management.allowed_admins', []),
            'sync_enabled'=>(bool)config('email_management.sync_enabled'), 'imap'=>config('email_management.imap'),
            'smtp_enabled'=>false, 'smtp'=>['host'=>$smtp['host'] ?? '', 'port'=>(int)($smtp['port'] ?? 587),
                'encryption'=>($smtp['scheme'] ?? '') === 'smtps' ? 'ssl' : 'tls', 'username'=>$smtp['username'] ?? '', 'password'=>$smtp['password'] ?? '']];
        if (!Schema::hasTable('nurselink_email_settings')) return $defaults;
        $encrypted = DB::table('nurselink_email_settings')->where('id', 1)->value('encrypted_settings');
        if (!$encrypted) return $defaults;
        $saved=json_decode(Crypt::decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        $values=array_replace_recursive($defaults,$saved);
        if (array_key_exists('allowed_admins',$saved)) $values['allowed_admins']=$saved['allowed_admins'];
        return $values;
    }

    public function publicValues(): array
    {
        $data = $this->values();
        foreach (['imap', 'smtp'] as $kind) { $data[$kind]['password_configured'] = !empty($data[$kind]['password']); unset($data[$kind]['password']); }
        $data['imap_available'] = function_exists('imap_open');
        return $data;
    }

    public function save(array $data): void
    {
        DB::transaction(function() use ($data) {
            // Singleton row serializes concurrent settings updates, including the first save.
            DB::table('nurselink_email_settings')->insertOrIgnore(['id'=>1, 'encrypted_settings'=>Crypt::encryptString('{}'), 'created_at'=>now(), 'updated_at'=>now()]);
            DB::table('nurselink_email_settings')->where('id',1)->lockForUpdate()->first();
            $previous=$this->values();
            foreach (['imap','smtp'] as $kind) {
                $newPassword=!empty($data[$kind]['password']);
                $data[$kind]['password'] = !empty($data[$kind]['password']) ? $data[$kind]['password'] : ($previous[$kind]['password'] ?? '');
                if (($data[$kind]['host'] !== ($previous[$kind]['host'] ?? '') || $data[$kind]['username'] !== ($previous[$kind]['username'] ?? '')) && !$newPassword) {
                    // Never forward an old password to a newly entered server or account.
                    $data[$kind]['password']='';
                }
                $enabled=$kind==='imap' ? $data['sync_enabled'] : $data['smtp_enabled'];
                if ($enabled && (!$data[$kind]['host'] || !$data[$kind]['username'] || !$data[$kind]['password'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages([$kind.'.password'=>'Complete the connection details and enter a password for a new server or account.']);
                }
            }
            DB::table('nurselink_email_settings')->where('id',1)->update(['encrypted_settings'=>Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'updated_at'=>now()]);
        });
    }

    public function applyIntake(): array
    {
        $data=$this->values();
        config(['email_management.mailbox'=>$data['mailbox'], 'email_management.allowed_admins'=>$data['allowed_admins'], 'email_management.imap'=>$data['imap']]);
        return $data;
    }

    public function assertPublicHost(string $host): void
    {
        if (!preg_match('/\A[a-zA-Z0-9](?:[a-zA-Z0-9.-]{0,251}[a-zA-Z0-9])?\z/D', $host)) throw new \RuntimeException('Invalid mail server name.');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : array_merge(gethostbynamel($host) ?: [], array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'));
        if (!$addresses) throw new \RuntimeException('Mail server could not be resolved.');
        foreach ($addresses as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new \RuntimeException('Use a public mail server address.');
    }

    public function smtpTransport(array $settings): EsmtpTransport
    {
        $this->assertPublicHost($settings['host']);
        $transport=new EsmtpTransport($settings['host'], (int)$settings['port'], $settings['encryption']==='ssl');
        $transport->setRequireTls(true)->setUsername($settings['username'])->setPassword($settings['password']);
        $transport->getStream()->setTimeout(10);
        return $transport;
    }

    public function mailer(): \Illuminate\Contracts\Mail\Mailer
    {
        $data=$this->applyIntake();
        if (!$data['smtp_enabled']) return app('mail.manager')->mailer();
        $mailer=new \Illuminate\Mail\Mailer('nurselink', app('view'), $this->smtpTransport($data['smtp']), app('events'));
        $mailer->alwaysFrom($data['mailbox'], 'LBS NurseLink');
        return $mailer;
    }

    public function test(string $kind): bool
    {
        $settings=$this->values()[$kind];
        if (!$settings['host'] || !$settings['username'] || !$settings['password']) return false;
        $this->assertPublicHost($settings['host']);
        if ($kind==='smtp') { $transport=$this->smtpTransport($settings); try { $transport->start(); return true; } finally { $transport->stop(); } }
        if (!function_exists('imap_open')) return false;
        foreach ([IMAP_OPENTIMEOUT,IMAP_READTIMEOUT,IMAP_WRITETIMEOUT] as $type) imap_timeout($type,10);
        $path=sprintf('{%s:%d/imap/%s}INBOX',$settings['host'],$settings['port'],$settings['encryption']==='ssl'?'ssl':'tls');
        $connection=@imap_open($path,$settings['username'],$settings['password'],OP_READONLY,1);
        imap_errors(); imap_alerts();
        if (!$connection) return false;
        imap_close($connection); return true;
    }
}
