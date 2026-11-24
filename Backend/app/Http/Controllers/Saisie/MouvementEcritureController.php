<?php

namespace App\Http\Controllers\Saisie;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Saisie\MouvementEcriture;
use App\Models\Saisie\Journal;
use App\Services\Comptabilite\EcritureGuard;
use Illuminate\Support\Facades\DB;

class MouvementEcritureController extends Controller
{
    public function index()
    {
        return MouvementEcriture::all();
    }

    public function show($id)
    {
        return MouvementEcriture::findOrFail($id);
    }

    /**
     * Création d'une pièce : date dans l'exercice ouvert, numéro séquentiel
     * par journal et par année ({CODE}{AAAA}-{NNNN}).
     */
    public function store(Request $request)
    {
        $request->validate([
            'Date_mouvement' => 'required|date',
            'Id_Journal' => 'required|exists:journals,Id_Journal',
        ]);

        EcritureGuard::exerciceOuvertPour($request->Date_mouvement);

        $mouvement = DB::transaction(function () use ($request) {
            $journal = Journal::findOrFail($request->Id_Journal);
            $annee = (int) date('Y', strtotime($request->Date_mouvement));

            return MouvementEcriture::create([
                'Date_mouvement' => $request->Date_mouvement,
                'Id_Journal' => $journal->Id_Journal,
                'Numero_piece' => EcritureGuard::prochainNumeroPiece($journal, $annee),
            ]);
        });

        return response()->json([
            'message' => 'Mouvement enregistré avec succès',
            'mouvement' => $mouvement
        ]);
    }

    /**
     * Modification de l'en-tête d'une pièce non validée (date, journal).
     */
    public function update(Request $request, $id)
    {
        $request->validate([
            'Date_mouvement' => 'sometimes|required|date',
            'Id_Journal' => 'sometimes|required|exists:journals,Id_Journal',
        ]);

        $mouvement = MouvementEcriture::with('lignes')->findOrFail($id);
        EcritureGuard::assertPieceModifiable($mouvement);

        $data = $request->only(['Date_mouvement', 'Id_Journal']);
        if (isset($data['Date_mouvement'])) {
            EcritureGuard::exerciceOuvertPour($data['Date_mouvement']);
        }

        DB::transaction(function () use ($mouvement, $data) {
            $mouvement->update($data);
            // Les lignes suivent le journal de leur pièce
            if (isset($data['Id_Journal'])) {
                $mouvement->lignes()->update(['Id_Journal' => $data['Id_Journal']]);
            }
        });

        return $mouvement->fresh();
    }

    /**
     * Suppression d'une pièce non validée et de ses lignes.
     */
    public function destroy($id)
    {
        $mouvement = MouvementEcriture::with('lignes')->findOrFail($id);
        EcritureGuard::assertPieceModifiable($mouvement);

        DB::transaction(function () use ($mouvement) {
            $mouvement->lignes()->delete();
            $mouvement->delete();
        });

        return response()->json(['message' => 'Mouvement supprimé']);
    }
}
