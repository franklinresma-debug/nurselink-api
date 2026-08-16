<?php
namespace App\Console\Commands;
use App\Models\ProfessionalCredential; use App\Services\Credentials\CredentialReminderService; use App\Services\Credentials\CredentialStatusService; use Illuminate\Console\Command;
class RefreshCredentials extends Command
{
    protected $signature='nurselink:credentials-refresh {--queue-due : Mark due in-app reminder events as queued for NL-008 delivery adapters}';
    protected $description='Refresh credential expiry states and renewal reminder events';
    public function handle(CredentialStatusService $statuses,CredentialReminderService $reminders):int
    {
        $count=0;
        ProfessionalCredential::query()->orderBy('id')->chunk(200,function($rows)use($statuses,$reminders,&$count){foreach($rows as $row){$statuses->refresh($row);$reminders->rebuild($row->fresh());$count++;}});
        $queued=0; if($this->option('queue-due')){foreach($reminders->due() as $event){$reminders->markQueued($event);$queued++;}}
        $this->info("Refreshed {$count} credential records; queued {$queued} due reminder events.");
        return self::SUCCESS;
    }
}
