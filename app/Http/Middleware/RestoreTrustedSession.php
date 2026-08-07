<?php

namespace App\Http\Middleware;

use App\Services\TrustedLoginService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RestoreTrustedSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guest()) {
            app(TrustedLoginService::class)->restore();
        }

        return $next($request);
    }
}
