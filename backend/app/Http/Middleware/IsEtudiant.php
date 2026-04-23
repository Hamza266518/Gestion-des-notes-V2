<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class IsEtudiant
{
    public function handle(Request $request, Closure $next)
    {
        if (auth()->user()->role !== 'etudiant') {
            return response()->json(['success' => false, 'message' => 'Accès refusé'], 403);
        }
        return $next($request);
    }
}