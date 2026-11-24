<?php

namespace App\Http\Controllers\Saisie;

use App\Http\Controllers\Controller;
use App\Models\Saisie\ModeleEcriture;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Modèles d'écriture : pièces types réutilisables (paie, écolages, factures…).
 */
class ModeleEcritureController extends Controller
{
    public function index()
    {
        return response()->json(
            ModeleEcriture::with('journal:Id_Journal,Code,Libelle')->orderBy('nom')->get()
        );
    }

    public function store(Request $request)
    {
        $modele = ModeleEcriture::create($this->valider($request) + ['created_by' => Auth::id()]);
        return response()->json(['message' => "Modèle « {$modele->nom} » enregistré", 'modele' => $modele], 201);
    }

    public function update(Request $request, ModeleEcriture $modeles_ecriture)
    {
        $modeles_ecriture->update($this->valider($request, $modeles_ecriture->id));
        return response()->json(['message' => "Modèle « {$modeles_ecriture->nom} » mis à jour", 'modele' => $modeles_ecriture]);
    }

    public function destroy(ModeleEcriture $modeles_ecriture)
    {
        $modeles_ecriture->delete();
        return response()->json(['message' => "Modèle « {$modeles_ecriture->nom} » supprimé"]);
    }

    private function valider(Request $request, ?int $ignorerId = null): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:150', Rule::unique('modeles_ecriture', 'nom')->ignore($ignorerId)],
            'Id_Journal' => 'nullable|exists:journals,Id_Journal',
            'libelle' => 'nullable|string|max:255',
            'lignes' => 'required|array|min:2',
            'lignes.*.Id_Sous_compte' => 'required|exists:sous_comptes,Id_Sous_compte',
            'lignes.*.Libelle' => 'nullable|string|max:255',
            'lignes.*.sens' => 'required|in:debit,credit',
            'lignes.*.montant' => 'nullable|numeric|min:0',
        ], [
            'nom.unique' => 'Un modèle porte déjà ce nom.',
            'lignes.min' => 'Un modèle doit comporter au moins deux lignes.',
        ]);
    }
}
