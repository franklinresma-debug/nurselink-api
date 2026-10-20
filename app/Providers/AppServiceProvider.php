<?php

namespace App\Providers;

use App\Http\Responses\RegisterResponse;
use App\Http\Responses\PasswordResetLinkResponse;
use App\Services\SmartRegistration\DocumentExtractor;
use App\Services\SmartRegistration\LocalOcrDocumentExtractor;
use App\Services\SmartRegistration\ManualReviewExtractor;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(DocumentExtractor::class, function ($app): DocumentExtractor {
            return match ((string) config('smart_registration.extractor')) {
                'local_ocr' => $app->make(LocalOcrDocumentExtractor::class),
                default => $app->make(ManualReviewExtractor::class),
            };
        });

        $this->app->singleton(
            RegisterResponseContract::class,
            RegisterResponse::class
        );

        $this->app->singleton(
            FailedPasswordResetLinkRequestResponse::class,
            PasswordResetLinkResponse::class
        );
        $this->app->singleton(
            SuccessfulPasswordResetLinkRequestResponse::class,
            PasswordResetLinkResponse::class
        );
    }

    public function boot(): void
    {
        config(['app.name' => 'LBS NurseLink', 'mail.from.name' => 'LBS NurseLink']);
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Mail\Events\MessageSending::class, function ($event): void {
            $mail = $event->message;
            $mail->subject(\App\Services\Communications\NotificationBrand::subject($mail->getSubject()));
            if ($mail->getTextBody() !== null) {
                $mail->text(\App\Services\Communications\NotificationBrand::text($mail->getTextBody()));
            }
            if ($mail->getHtmlBody() !== null) {
                $html = preg_replace_callback('/(^|>)([^<]*)(?=<|$)/s', fn ($part) => $part[1] . \App\Services\Communications\NotificationBrand::text($part[2]), $mail->getHtmlBody());
                $mail->html($html);
            }
            $senders = array_map(fn ($address) => new \Symfony\Component\Mime\Address($address->getAddress(), 'LBS NurseLink'), $mail->getFrom());
            if ($senders) $mail->from(...$senders);
        });
    }
}
