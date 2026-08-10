<?php

namespace Ommc2027\Geolocation\Providers;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Geolocation;

class GeolocationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Geolocation::class, function () {
            return new Geolocation;
        });
    }
}
