<?php

namespace App\Providers;

use App\Http\Responses\RegisterResponse;
use App\Services\SmartRegistration\DocumentExtractor;
use App\Services\SmartRegistration\LocalOcrDocumentExtractor;
use App\Services\SmartRegistration\ManualReviewExtractor;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

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
    }

    public function boot(): void
    {
        //
    }
}
