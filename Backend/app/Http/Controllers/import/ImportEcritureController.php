<?php

namespace App\Http\Controllers\import;

use App\Http\Controllers\Controller;
use App\Models\import\ImportHistory;
use App\Models\Saisie\MouvementEcriture;
use App\Services\Comptabilite\ImportEcritures;
use App\Services\Parametres;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Import d'écritures.
 *
 * Interface actuelle : le navigateur lit le fichier (CSV / XLSX / XLS), fait
 * correspondre les colonnes, puis envoie les lignes :
 *   POST /ecritures/import/analyse  { lignes }            → aperçu détaillé
 *   POST /ecritures/import/lignes   { lignes, fichier }   → import (tout ou rien)
 * Historique : GET /ecritures/imports/historique, annulation d'un import
 * réussi dont aucune pièce n'est validée : POST /ecritures/imports/{id}/annuler.
 *
 * Les anciennes routes à fichier CSV (import, import/validate) restent
 * disponibles et passent par le même service.
 */
class ImportEcritureController extends Controller
{
    public function __construct(private ImportEcritures $service) {}

    private function reglesLignes(): array
    {
        return [
            'lignes' => 'required|array|min:1|max:' . ImportEcritures::MAX_LIGNES,
            'lignes.*' => 'array',
        ];
    }

    private const MESSAGES = [
        'lignes.required' => 'Le fichier ne contient aucune ligne.',
        'lignes.max' => 'Le fichier dépasse ' . ImportEcritures::MAX_LIGNES . ' lignes.',
    ];

    public function analyse(Request $request)
    {
        $data = $request->validate($this->reglesLignes(), self::MESSAGES);
        return response()->json($this->service->analyser($data['lignes']));
    }

    public function importLignes(Request $request)
    {
        $data = $request->validate($this->reglesLignes() + ['fichier' => 'nullable|string|max:255'], self::MESSAGES);
        return $this->repondre(...$this->service->importer($data['lignes'], $data['fichier'] ?? 'Import'));
    }

    // ---- Anciennes routes : fichier CSV ----------------------------------

    private function regleFichier(): array
    {
        return ['file_ecritures' => 'required|file|mimes:csv,txt|max:' . (1024 * (int) Parametres::get('import.taille_max'))];
    }

    private function lignesCsv(Request $request): array
    {
        $s = Parametres::get('import.separateur_csv');
        return ImportEcritures::lignesDepuisCsv($request->file('file_ecritures')->getRealPath(), $s === 'tab' ? "\t" : $s);
    }

    public function import(Request $request)
    {
        $request->validate($this->regleFichier());
        return $this->repondre(...$this->service->importer($this->lignesCsv($request), $request->file('file_ecritures')->getClientOriginalName()));
    }

    public function validateImport(Request $request)
    {
        $request->validate($this->regleFichier());
        $analyse = $this->service->analyser($this->lignesCsv($request));
        return response()->json($analyse + [
            'total_lignes' => $analyse['resume']['lignes'],
            'preview' => array_slice($analyse['lignes'], 0, 5),
            'format_valide' => $analyse['resume']['erreurs'] === 0,
        ]);
    }

    private function repondre(array $analyse, ImportHistory $historique)
    {
        $r = $analyse['resume'];
        if ($historique->statut !== 'réussi') {
            return response()->json([
                'message' => $r['lignes'] === 0 ? 'Le fichier ne contient aucune écriture.' : "{$r['erreurs']} erreur(s) : aucune écriture n'a été importée.",
                'errors' => ['import' => $historique->details['erreurs'] ?? []],
                'analyse' => $analyse,
                'historique' => $this->presenter($historique),
            ], 422);
        }
        return response()->json([
            'message' => "Import réussi : {$r['pieces']} pièce(s) et {$r['lignes']} ligne(s) créées en brouillon.",
            'analyse' => $analyse,
            'historique' => $this->presenter($historique),
            'data' => ['mouvements' => $r['pieces'], 'lignes' => $r['lignes']],
        ]);
    }

    // ---- Historique ------------------------------------------------------

    public function historique(Request $request)
    {
        $request->validate([
            'statut' => 'nullable|in:réussi,échec,annulé',
            'q' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        // Pièces encore en brouillon / validées par import (annulation possible ?)
        $etat = DB::table('mouvement_ecritures as m')
            ->join('ligne_ecritures as l', 'l.Id_Mouvement_ecriture', '=', 'm.Id_Mouvement_ecriture')
            ->whereNotNull('m.id_import')
            ->groupBy('m.id_import')
            ->selectRaw("m.id_import, COUNT(DISTINCT m.\"Id_Mouvement_ecriture\") as pieces, COUNT(DISTINCT m.\"Id_Mouvement_ecriture\") FILTER (WHERE l.statut = 'valide') as validees");

        $page = ImportHistory::with(['user:id,name', 'annulePar:id,name'])
            ->leftJoinSub($etat, 'e', 'e.id_import', '=', 'import_histories.id')
            ->select('import_histories.*', 'e.pieces as pieces_actuelles', 'e.validees as pieces_validees')
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->statut))
            ->when($request->filled('q'), fn ($q) => $q->where('nom_fichier', 'ILIKE', '%' . $request->q . '%'))
            ->orderByDesc('import_histories.created_at')
            ->orderByDesc('import_histories.id')
            ->paginate($request->input('per_page', Parametres::get('affichage.lignes_par_page')));

        $page->getCollection()->transform(fn ($h) => $this->presenter($h));

        $compteurs = ImportHistory::selectRaw('statut, COUNT(*) as n')->groupBy('statut')->pluck('n', 'statut');
        return response()->json($page->toArray() + ['compteurs' => $compteurs]);
    }

    public function annuler(int $id)
    {
        $import = ImportHistory::findOrFail($id);
        $n = $this->service->annuler($import);
        return response()->json([
            'message' => "Import annulé : {$n} pièce(s) brouillon supprimée(s).",
            'historique' => $this->presenter($import->fresh(['user:id,name', 'annulePar:id,name'])),
        ]);
    }

    private function presenter(ImportHistory $h): array
    {
        $pieces = (int) ($h->pieces_actuelles ?? MouvementEcriture::where('id_import', $h->id)->count());
        $validees = (int) ($h->pieces_validees ?? 0);
        return [
            'id' => $h->id,
            'nom_fichier' => $h->nom_fichier,
            'date' => ($h->created_at ?? $h->imported_at)?->toIso8601String(),
            'par' => $h->user?->name,
            'statut' => $h->statut,
            'nombre_pieces' => $h->nombre_mouvements,
            'nombre_lignes' => $h->nombre_lignes,
            'total' => $h->total_debit,
            'date_min' => $h->date_min?->toDateString(),
            'date_max' => $h->date_max?->toDateString(),
            'erreur' => $h->erreur,
            'erreurs' => $h->details['erreurs'] ?? ($h->erreur ? array_values(array_filter(explode("\n", $h->erreur))) : []),
            'pieces_restantes' => $pieces,
            'pieces_validees' => $validees,
            // Traçabilité par pièce : imports réalisés depuis cette version
            'tracable' => $h->statut === 'réussi' && $pieces > 0,
            'annulable' => $h->statut === 'réussi' && $pieces > 0 && $validees === 0,
            'annule_le' => $h->annule_le?->toIso8601String(),
            'annule_par' => $h->annulePar?->name,
        ];
    }
}
