<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Ommc2027\Camera\Providers\CameraServiceProvider;
use Ommc2027\Geolocation\Providers\GeolocationServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            CameraServiceProvider::class,
            GeolocationServiceProvider::class,
        ];
    }
}
