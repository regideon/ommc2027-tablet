<?php

if (! function_exists('gethostname')) {
    /**
     * Polyfill for platforms where ext-standard's gethostname() isn't
     * available (e.g. NativePHP's Android runtime). Loaded via Composer's
     * autoload.files so it's declared before any service provider runs —
     * Laravel\Pulse\PulseServiceProvider::register() calls it unconditionally
     * while merging its own vendor config, crashing app boot on Android
     * without this.
     */
    function gethostname(): string|false
    {
        return php_uname('n');
    }
}
