<?php

namespace App\Jobs;

use App\Services\SmartRegistration\SmartRegistrationDocumentProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessSmartRegistrationDocument implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public readonly int $documentId)
    {
        $this->onQueue((string) config('smart_registration.queue.name', 'document-extraction'));
    }

    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(SmartRegistrationDocumentProcessor $processor): void
    {
        $processor->process($this->documentId);
    }

    public function failed(Throwable $error): void
    {
        app(SmartRegistrationDocumentProcessor::class)->markFailed(
            $this->documentId,
            'Automatic extraction failed after three attempts. Please retry the upload or enter the missing information manually.'
        );
    }
}
