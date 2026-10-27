<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ActivityLogController extends Controller
{
    /**
     * Per-admin marker of the last time the journal was looked at. Kept in the
     * cache rather than a new column so notifying other admins needs no schema
     * change. A missing marker means "never opened the journal", which shows no
     * badge instead of flagging the whole history as new.
     */
    private const SEEN_KEY = 'activity-log:seen:';

    private function seenAt(int $adminId): ?Carbon
    {
        $value = Cache::get(self::SEEN_KEY . $adminId);

        return $value ? Carbon::parse($value) : null;
    }

    public function unseen(Request $request)
    {
        $adminId = (int) $request->user()->id;
        $since   = $this->seenAt($adminId);

        if (! $since) {
            return response()->json([
                'success' => true,
                'data'    => ['count' => 0, 'since' => null, 'latest' => null],
            ]);
        }

        $query = ActivityLog::where('admin_id', '!=', $adminId)
            ->where('created_at', '>', $since);

        $latest = (clone $query)
            ->latest()
            ->first(['id', 'admin_name', 'action_type', 'description', 'created_at']);

        return response()->json([
            'success' => true,
            'data'    => [
                'count'  => (clone $query)->count(),
                'since'  => $since->toIso8601String(),
                'latest' => $latest,
            ],
        ]);
    }

    public function seen(Request $request)
    {
        $now = now();
        Cache::forever(self::SEEN_KEY . (int) $request->user()->id, $now->toIso8601String());

        return response()->json([
            'success' => true,
            'data'    => ['seen_at' => $now->toIso8601String()],
        ]);
    }

    public function index(Request $request)
    {
        try {
            $query = ActivityLog::select(['id', 'admin_name', 'action_type', 'description', 'model_type', 'model_id', 'created_at'])
                ->latest();

            if ($request->filled('action_type')) {
                $query->where('action_type', $request->action_type);
            }

            if ($request->filled('model_type')) {
                $query->where('model_type', $request->model_type);
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('admin_name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }

            if ($request->filled('etudiant_id')) {
                $query->where(function ($q) use ($request) {
                    $q->where('model_type', 'App\Models\Etudiant')
                      ->orWhere('model_type', 'App\Models\Note');
                    $q->where('model_id', $request->etudiant_id);
                });
            }

            if ($request->boolean('paginate')) {
                $perPage = (int) $request->input('per_page', 20);
                $logs = $query->paginate($perPage);
            } else {
                $limit = (int) $request->input('limit', 20);
                $logs = $query->limit($limit)->get();
            }

            $adminId = (int) $request->user()->id;
            $since   = $this->seenAt($adminId);

            return response()->json([
                'success' => true,
                'data'    => $logs,
                'meta'    => [
                    'last_seen_at' => $since?->toIso8601String(),
                    'unseen_count' => $since
                        ? ActivityLog::where('admin_id', '!=', $adminId)
                            ->where('created_at', '>', $since)
                            ->count()
                        : 0,
                ],
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
