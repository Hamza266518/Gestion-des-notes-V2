<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IsFormateur
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();
        if (!$user || !in_array($user->role, ['formateur', 'admin'])) {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }
        return $next($request);
    }
}
