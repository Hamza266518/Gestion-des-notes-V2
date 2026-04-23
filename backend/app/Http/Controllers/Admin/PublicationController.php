<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SemestrePublication;
use Illuminate\Http\Request;

class PublicationController extends Controller
{
    public function index(Request $request)
    {
        $publications = SemestrePublication::with(['groupe.niveau.filiere', 'anneeAcademique'])
            ->when($request->groupe_id, fn($q) => $q->where('groupe_id', $request->groupe_id))
            ->get();
        return response()->json(['success' => true, 'data' => $publications]);
    }

    public function publish(Request $request)
    {
        $request->validate([
            'groupe_id'           => 'required|exists:groupes,id',
            'annee_academique_id' => 'required|exists:annees_academiques,id',
            'semestre'            => 'required|integer|in:1,2',
        ]);

        $publication = SemestrePublication::updateOrCreate(
            [
                'groupe_id'           => $request->groupe_id,
                'annee_academique_id' => $request->annee_academique_id,
                'semestre'            => $request->semestre,
            ],
            [
                'is_published' => true,
                'published_at' => now(),
            ]
        );

        return response()->json(['success' => true, 'data' => $publication, 'message' => 'Semestre publié']);
    }

    public function unpublish($id)
    {
        $publication = SemestrePublication::findOrFail($id);
        $publication->update(['is_published' => false, 'published_at' => null]);
        return response()->json(['success' => true, 'message' => 'Publication annulée']);
    }
}