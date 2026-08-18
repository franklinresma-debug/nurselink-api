<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class NotifyPolicyConsent extends Command
{
    protected $signature = 'nurselink:notify-policy-consent
        {--send : Create in-app reminders; without this option the command is a dry run}';

    protected $description = 'Remind active users who must personally accept the current NurseLink policies';

    public function handle(): int
    {
        $termsVersion = (string) config('registration.terms_version');
        $privacyVersion = (string) config('registration.privacy_version');

        $pending = User::query()
            ->where('status', 'active')
            ->where(function ($query) use ($termsVersion, $privacyVersion): void {
                $query->whereNull('terms_accepted_at')
                    ->orWhereNull('privacy_accepted_at')
                    ->orWhere('terms_version', '!=', $termsVersion)
                    ->orWhere('privacy_version', '!=', $privacyVersion);
            })
            ->get(['id']);

        $created = 0;
        $alreadyPresent = 0;

        foreach ($pending as $user) {
            $exists = DB::table('nurselink_notifications')
                ->where('user_id', $user->id)
                ->where('type', 'policy_consent_required')
                ->whereNull('read_at')
                ->exists();

            if ($exists) {
                $alreadyPresent++;

                continue;
            }

            if (! $this->option('send')) {
                continue;
            }

            DB::table('nurselink_notifications')->insert([
                'user_id' => $user->id,
                'type' => 'policy_consent_required',
                'severity' => 'info',
                'title' => 'Review NurseLink’s current policies',
                'message' => 'Please review and personally accept the current Terms of Use and Privacy Notice to keep your consent record up to date.',
                'action_url' => '/policy-center',
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $created++;
        }

        $mode = $this->option('send') ? 'send' : 'dry-run';
        $this->info(sprintf(
            'Policy consent reminders (%s): %d pending user(s), %d created, %d already present.',
            $mode,
            $pending->count(),
            $created,
            $alreadyPresent,
        ));

        return self::SUCCESS;
    }
}
