<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Auth\AuthenticationException;

class Authenticate
{
    public function handle(Request $request, Closure $next, ...$guards)
    {
        if (auth()->guest()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            throw new AuthenticationException('Unauthenticated.', $guards);
        }

        return $next($request);
    }
}
