<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        try {
            $query = ActivityLog::select(['id', 'admin_name', 'action_type', 'description', 'created_at'])
                ->latest();

            if ($request->filled('model_type')) {
                $query->where('model_type', $request->model_type);
            }

            if ($request->filled('etudiant_id')) {
                $query->where(function ($q) use ($request) {
                    $q->where('model_type', 'App\Models\Etudiant')
                      ->orWhere('model_type', 'App\Models\Note');
                    $q->where('model_id', $request->etudiant_id);
                });
            }

            if ($request->filled('limit')) {
                $query->limit((int) $request->limit);
            } else {
                $query->limit(20);
            }

            $logs = $query->get();

            return response()->json([
                'success' => true,
                'data' => $logs,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur chargement journaux d\'activité: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des journaux d\'activité'], 500);
        }
    }

    public static function log($adminId, $adminName, $actionType, $description, $modelType = null, $modelId = null, $oldValue = null, $newValue = null)
    {
        try {
            return ActivityLog::create([
                'admin_id' => $adminId,
                'admin_name' => $adminName,
                'action_type' => $actionType,
                'description' => $description,
                'model_type' => $modelType,
                'model_id' => $modelId,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ]);
        } catch (\Exception $e) {
            Log::error('Erreur enregistrement journal d\'activité: ' . $e->getMessage());
            return null;
        }
    }
}
