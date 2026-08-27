<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncNurseLinkMailbox extends Command
{
    protected $signature = 'nurselink:email-sync {--limit=50}';
    protected $description = 'Synchronize new messages from the NurseLink IMAP inbox';

    public function handle(): int
    {
        if (! function_exists('imap_open')) {
            $this->error('PHP IMAP extension is not available. No mailbox changes were made.');
            return self::FAILURE;
        }
        $config = config('email_management.imap');
        if (! $config['host'] || ! $config['username'] || ! $config['password']) {
            $this->error('NurseLink IMAP configuration is incomplete.');
            return self::FAILURE;
        }
        $flags = '/imap/'.($config['encryption'] === 'ssl' ? 'ssl' : 'tls').'/novalidate-cert';
        $mailbox = sprintf('{%s:%d%s}INBOX', $config['host'], $config['port'], $flags);
        $imap = @imap_open($mailbox, $config['username'], $config['password'], OP_READONLY);
        if (! $imap) {
            $this->error('Unable to connect to the configured NurseLink mailbox.');
            return self::FAILURE;
        }
        $ids = imap_search($imap, 'ALL', SE_UID) ?: [];
        rsort($ids); $ids = array_slice($ids, 0, max(1, min(200, (int) $this->option('limit')))); $imported = 0;
        foreach (array_reverse($ids) as $uid) {
            $providerId = 'imap:'.$config['username'].':'.$uid;
            if (DB::table('nurselink_email_messages')->where('provider_message_id', $providerId)->exists()) continue;
            $overview = imap_fetch_overview($imap, (string) $uid, FT_UID)[0] ?? null;
            if (! $overview) continue;
            $from = $this->address((string) ($overview->from ?? ''));
            if (! filter_var($from, FILTER_VALIDATE_EMAIL)) continue;
            $subject = trim(imap_utf8((string) ($overview->subject ?? 'No subject')));
            $normalized = trim((string) preg_replace('/^(re|fw|fwd):\s*/i', '', $subject));
            $threadId = DB::table('nurselink_email_threads')->whereRaw('LOWER(contact_email) = ?', [strtolower($from)])->whereRaw('LOWER(subject) = ?', [strtolower($normalized)])->value('id');
            if (! $threadId) {
                $memberId = User::query()->whereRaw('LOWER(email) = ?', [strtolower($from)])->value('id');
                $threadId = DB::table('nurselink_email_threads')->insertGetId(['subject' => $normalized, 'contact_email' => strtolower($from), 'member_user_id' => $memberId, 'category' => 'member_support', 'priority' => 'normal', 'status' => 'open', 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
            $body = trim((string) imap_body($imap, (string) $uid, FT_UID | FT_PEEK));
            if (($overview->encoding ?? null) === 3) $body = (string) base64_decode($body, true);
            DB::table('nurselink_email_messages')->insert(['thread_id' => $threadId, 'direction' => 'inbound', 'provider_message_id' => $providerId, 'from_email' => strtolower($from), 'to_email' => config('email_management.mailbox'), 'body_text' => mb_substr(strip_tags($body), 0, 50000), 'delivery_status' => 'received', 'sent_at' => isset($overview->date) ? date('Y-m-d H:i:s', strtotime($overview->date)) : now(), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('nurselink_email_threads')->where('id', $threadId)->update(['last_message_at' => now(), 'status' => 'open', 'updated_at' => now()]); $imported++;
        }
        imap_close($imap); $this->info("Imported {$imported} new email message(s).");
        return self::SUCCESS;
    }

    private function address(string $value): string
    {
        return preg_match('/<([^>]+)>/', $value, $matches) ? trim($matches[1]) : trim($value);
    }
}
