<?php

namespace Ommc2027\Camera\Providers;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Camera;

class CameraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Camera::class, function () {
            return new Camera;
        });
    }
}
