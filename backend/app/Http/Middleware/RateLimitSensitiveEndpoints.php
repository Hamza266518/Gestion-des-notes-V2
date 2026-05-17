<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Cache\RateLimiter;

class RateLimitSensitiveEndpoints
{
    public function __construct(private RateLimiter $limiter)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $sensitivePaths = [
            'admin/scan-cin',
            'admin/import',
            'admin/scan-unites',
            'formateur/scan',
        ];

        foreach ($sensitivePaths as $path) {
            if ($request->is("api/$path*")) {
                $key = 'sensitive:' . auth()->id() . ':' . $request->path();
                
                if ($this->limiter->tooManyAttempts($key, 10)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Trop de requêtes. Veuillez réessayer après quelques secondes.',
                    ], 429);
                }

                $this->limiter->hit($key, 60);
            }
        }

        return $next($request);
    }
}
