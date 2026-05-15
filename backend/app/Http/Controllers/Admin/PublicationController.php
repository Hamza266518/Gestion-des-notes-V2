<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SemestrePublication;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class PublicationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $publications = SemestrePublication::with(['groupe.niveau.filiere', 'anneeAcademique'])
                ->when($request->annee_academique_id, fn($q) => $q->where('annee_academique_id', $request->annee_academique_id))
                ->when($request->groupe_id, fn($q) => $q->where('groupe_id', $request->groupe_id))
                ->get();
            return response()->json(['success' => true, 'data' => $publications]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors du chargement des publications'], 500);
        }
    }

    public function publish(Request $request)
    {
        try {
            $request->validate([
                'groupe_id'           => 'required|exists:groupes,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'type'                => 'required|in:notes_s1,notes_s2,bulletin',
            ]);

            $publication = SemestrePublication::updateOrCreate(
                [
                    'groupe_id'           => $request->groupe_id,
                    'annee_academique_id' => $request->annee_academique_id,
                    'type'                => $request->type,
                ],
                [
                    'is_published' => true,
                    'published_at' => now(),
                ]
            );

            // Log activity
            $typeLabels = [
                'notes_s1' => 'Notes S1',
                'notes_s2' => 'Notes S2',
                'bulletin' => 'Bulletin',
            ];
            $groupe = \App\Models\Groupe::find($request->groupe_id);
            $user = auth()->user();
            ActivityLog::create([
                'admin_id' => $user->id,
                'admin_name' => $user->name,
                'action_type' => 'publish',
                'description' => 'Admin IFP a publié ' . ($typeLabels[$request->type] ?? $request->type) . ' pour ' . ($groupe->nom ?? 'Groupe'),
                'model_type' => 'publication',
                'model_id' => $publication->id,
            ]);

            $typeMessages = [
                'notes_s1' => 'Notes S1 publiées',
                'notes_s2' => 'Notes S2 publiées',
                'bulletin' => 'Bulletin publié',
            ];

            return response()->json(['success' => true, 'data' => $publication, 'message' => $typeMessages[$request->type] ?? 'Publication mise à jour']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la publication'], 500);
        }
    }

    public function publishAll(Request $request)
    {
        try {
            $request->validate([
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'type'                => 'required|in:notes_s1,notes_s2,bulletin',
            ]);

            $groupes = \App\Models\Groupe::where('annee_academique_id', $request->annee_academique_id)->get();

            if ($groupes->isEmpty()) {
                return response()->json(['success' => false, 'message' => 'Aucun groupe trouvé'], 404);
            }

            $count = 0;
            foreach ($groupes as $groupe) {
                SemestrePublication::updateOrCreate(
                    [
                        'groupe_id'           => $groupe->id,
                        'annee_academique_id' => $request->annee_academique_id,
                        'type'                => $request->type,
                    ],
                    [
                        'is_published' => true,
                        'published_at' => now(),
                    ]
                );
                $count++;
            }

            $typeLabels = [
                'notes_s1' => 'Notes S1',
                'notes_s2' => 'Notes S2',
                'bulletin' => 'Bulletin',
            ];

            $user = auth()->user();
            ActivityLog::create([
                'admin_id' => $user->id,
                'admin_name' => $user->name,
                'action_type' => 'publish',
                'description' => 'Admin IFP a publié ' . ($typeLabels[$request->type] ?? $request->type) . ' pour tous les groupes (' . $count . ')',
                'model_type' => 'publication',
                'model_id' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => ($typeLabels[$request->type] ?? $request->type) . ' publiées pour ' . $count . ' groupe(s)',
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la publication en masse'], 500);
        }
    }

    public function unpublish(Request $request)
    {
        try {
            $request->validate([
                'groupe_id'           => 'required|exists:groupes,id',
                'annee_academique_id' => 'required|exists:annees_academiques,id',
                'type'                => 'required|in:notes_s1,notes_s2,bulletin',
            ]);

            $publication = SemestrePublication::where([
                'groupe_id'           => $request->groupe_id,
                'annee_academique_id' => $request->annee_academique_id,
                'type'                => $request->type,
            ])->firstOrFail();

            $publication->update(['is_published' => false, 'published_at' => null]);

            // Log activity
            $typeLabels = [
                'notes_s1' => 'Notes S1',
                'notes_s2' => 'Notes S2',
                'bulletin' => 'Bulletin',
            ];
            $user = auth()->user();
            ActivityLog::create([
                'admin_id' => $user->id,
                'admin_name' => $user->name,
                'action_type' => 'unpublish',
                'description' => 'Admin IFP a dépublié ' . ($typeLabels[$request->type] ?? $request->type),
                'model_type' => 'publication',
                'model_id' => $publication->id,
            ]);

            return response()->json(['success' => true, 'message' => 'Publication annulée']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => 'Erreur lors de la dépublication'], 500);
        }
    }
}
