<?php

namespace App\Providers;

use App\Console\Commands\SyncLoginCommand;
use App\Console\Commands\SyncPullCommand;
use App\Console\Commands\SyncPushCommand;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Native\Mobile\Events\Geolocation\LocationReceived;
use App\Listeners\HandleLocationReceived;
use Illuminate\Support\Facades\Event;

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

        if (function_exists('nativephp_call')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }
}
