<?php

namespace App\Providers;

use App\Console\Commands\SyncLoginCommand;
use App\Console\Commands\SyncPullCommand;
use App\Console\Commands\SyncPushCommand;
use App\Listeners\HandleLocationReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Native\Mobile\Events\Geolocation\LocationReceived;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(LocationReceived::class, HandleLocationReceived::class);

        $this->commands([
            SyncPullCommand::class,
            SyncPushCommand::class,
            SyncLoginCommand::class,
        ]);

        // Pulse registers its <x-pulse> layout as an anonymous component keyed by
        // hash('xxh128', 'pulse') (see PulseServiceProvider::anonymousComponentPath).
        // Prepending our override path to that same namespace hint — rather than
        // editing vendor/laravel/pulse directly — lets it survive `composer update`.
        View::prependNamespace(
            hash('xxh128', 'pulse'),
            resource_path('views/vendor/pulse/components')
        );
    }
}
