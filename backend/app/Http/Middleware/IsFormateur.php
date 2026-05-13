<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IsFormateur
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();
        if (!$user || $user->role !== 'formateur') {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }
        return $next($request);
    }
}
