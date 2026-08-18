<?php

namespace App\Providers;

use App\Modules\Collections\Contracts\CollectionAuditRecorderInterface;
use App\Modules\Collections\Support\DatabaseCollectionAuditRecorder;
use App\Modules\Shared\Contracts\BusinessNumberGeneratorInterface;
use App\Modules\Shared\Services\BusinessNumberGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            BusinessNumberGeneratorInterface::class,
            BusinessNumberGenerator::class,
        );

        $this->app->bind(
            CollectionAuditRecorderInterface::class,
            DatabaseCollectionAuditRecorder::class,
        );
    }

    public function boot(): void
    {
        //
    }
}
