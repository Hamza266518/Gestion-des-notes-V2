<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class HealthController extends Controller
{
    public function check()
    {
        try {
            // Check database connection
            \DB::connection()->getPdo();
            
            return response()->json([
                'success' => true,
                'status' => 'healthy',
                'timestamp' => now(),
                'app_name' => config('app.name'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'status' => 'unhealthy',
                'error' => 'Database connection failed',
                'timestamp' => now(),
            ], 503);
        }
    }
}
