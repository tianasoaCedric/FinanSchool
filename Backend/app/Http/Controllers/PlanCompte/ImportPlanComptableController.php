<?php

namespace App\Http\Controllers\PlanCompte;

use App\Http\Controllers\Controller;
use App\Services\Comptabilite\Audit;
use App\Services\Comptabilite\ImportPlanComptable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Import et export du plan comptable.
 * Le fichier (CSV ou Excel) est lu par le navigateur, qui envoie les lignes
 * { code, libelle } : le serveur n'a pas à gérer encodages ni séparateurs.
 */
class ImportPlanComptableController extends Controller
{
    /** Aperçu (simulation : true) ou import effectif. */
    public function import(Request $request, ImportPlanComptable $service)
    {
        $data = $request->validate([
            'lignes' => 'required|array|min:1|max:' . ImportPlanComptable::MAX_LIGNES,
            'lignes.*.code' => 'nullable',
            'lignes.*.libelle' => 'nullable',
            'mettre_a_jour_libelles' => 'boolean',
            'simulation' => 'boolean',
            'fichier' => 'nullable|string|max:255',
        ], [
            'lignes.required' => 'Le fichier ne contient aucune ligne.',
            'lignes.max' => 'Le fichier dépasse ' . ImportPlanComptable::MAX_LIGNES . ' lignes.',
        ]);
        $maj = (bool) ($data['mettre_a_jour_libelles'] ?? false);

        if ($data['simulation'] ?? true) {
            return response()->json($service->analyser($data['lignes'], $maj));
        }

        $resultat = $service->importer($data['lignes'], $maj);
        $resume = $resultat['resume'];
        Audit::enregistrer(Audit::PLAN_IMPORT, 'plan_comptable', null, $data['fichier'] ?? 'Import du plan comptable', null, null, [
            'crees' => $resume['creer'],
            'libelles_modifies' => $resume['maj'],
            'parents_crees' => $resume['parents'],
            'ignores_erreur' => $resume['erreur'],
        ]);

        return response()->json($resultat + [
            'message' => sprintf(
                '%d élément(s) créé(s), %d libellé(s) mis à jour%s%s.',
                $resume['creer'] + $resume['parents'],
                $resume['maj'],
                $resume['parents'] ? " (dont {$resume['parents']} parent(s) créé(s) automatiquement)" : '',
                $resume['erreur'] ? ", {$resume['erreur']} ligne(s) en erreur ignorée(s)" : ''
            ),
        ]);
    }

    /** Plan comptable complet (pour l'export / le modèle à compléter). */
    public function export()
    {
        $lignes = collect()
            ->concat(DB::table('classes')->get(['Code as code', 'Libelle as libelle'])->map(fn ($r) => (array) $r + ['niveau' => 'Classe']))
            ->concat(DB::table('rubriques')->get(['Code_rubrique as code', 'Libelle as libelle'])->map(fn ($r) => (array) $r + ['niveau' => 'Rubrique']))
            ->concat(DB::table('comptes')->get(['Code_compte as code', 'Libelle as libelle'])->map(fn ($r) => (array) $r + ['niveau' => 'Compte']))
            ->concat(DB::table('sous_comptes')->get(['Code_sous_compte as code', 'Libelle as libelle'])->map(fn ($r) => (array) $r + ['niveau' => 'Sous-compte']))
            // Ordre hiérarchique : 6 < 60 < 601 < 601001 < 602
            ->sortBy(fn ($r) => (string) $r['code'], SORT_STRING)
            ->values();

        return response()->json($lignes);
    }
}
