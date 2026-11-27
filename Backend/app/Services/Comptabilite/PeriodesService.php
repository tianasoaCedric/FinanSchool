<?php

namespace App\Services\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clôture périodique (mensuelle) — PCG 2005 : une procédure de clôture fige
 * la chronologie des enregistrements et garantit leur intangibilité.
 *
 *  - les mois se clôturent dans l'ordre, de façon définitive ;
 *  - un mois ne se clôture que sans pièce en brouillon ni pièce validée
 *    déséquilibrée ;
 *  - le dernier mois de l'exercice reste ouvert pour les travaux d'inventaire
 *    (dotations, régularisations) : il est verrouillé par la clôture annuelle ;
 *  - les mois de N+1 ne se clôturent qu'une fois N clôturé (les à-nouveaux,
 *    datés du premier jour, doivent exister avant).
 */
class PeriodesService
{
    public function liste(ExerciceComptable $exercice): array
    {
        $debut = Carbon::parse($exercice->Date_debut)->startOfDay();
        $fin = Carbon::parse($exercice->Date_fin)->startOfDay();
        $verrou = $exercice->periode_cloturee_au?->toDateString();

        $stats = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->whereBetween('m.Date_mouvement', [$debut->toDateString(), $fin->toDateString()])
            ->groupBy('mois')
            ->selectRaw("to_char(m.\"Date_mouvement\", 'YYYY-MM') as mois")
            ->selectRaw("COUNT(DISTINCT m.\"Id_Mouvement_ecriture\") FILTER (WHERE l.statut IS DISTINCT FROM 'valide') as brouillons")
            ->selectRaw("COUNT(DISTINCT m.\"Id_Mouvement_ecriture\") as pieces")
            ->get()->keyBy('mois');

        $mois = [];
        $prochain = null;
        for ($d = $debut->copy()->startOfMonth(); $d->lte($fin); $d->addMonth()) {
            $cle = $d->format('Y-m');
            $finMois = $d->copy()->endOfMonth()->min($fin)->toDateString();
            $dernier = $finMois === $fin->toDateString();
            $cloture = $exercice->isCloture() || ($verrou && $finMois <= $verrou);
            if (!$cloture && $prochain === null && !$dernier) {
                $prochain = $cle;
            }
            $mois[] = [
                'mois' => $cle,
                'libelle' => ucfirst($d->locale('fr')->translatedFormat('F Y')),
                'debut' => $d->copy()->max($debut)->toDateString(),
                'fin' => $finMois,
                'pieces' => (int) ($stats[$cle]->pieces ?? 0),
                'brouillons' => (int) ($stats[$cle]->brouillons ?? 0),
                'statut' => $cloture ? 'cloture' : ($dernier ? 'inventaire' : 'ouvert'),
            ];
        }
        foreach ($mois as &$m) {
            $m['prochain'] = $m['mois'] === $prochain;
        }

        return [
            'exercice' => $exercice->Annee_fiscale,
            'periode_cloturee_au' => $verrou,
            'mois' => $mois,
            'motif_blocage' => $this->motifBlocage($exercice),
        ];
    }

    /** Clôture le mois $mois (AAAA-MM), qui doit être le prochain mois ouvert. */
    public function cloturer(ExerciceComptable $exercice, string $mois): array
    {
        $liste = $this->liste($exercice);
        if ($liste['motif_blocage']) {
            $this->refuser($liste['motif_blocage']);
        }
        $m = collect($liste['mois'])->firstWhere('mois', $mois);
        if (!$m) {
            $this->refuser("Le mois {$mois} n'appartient pas à l'exercice {$exercice->Annee_fiscale}.");
        }
        if ($m['statut'] === 'cloture') {
            $this->refuser("{$m['libelle']} est déjà clôturé.");
        }
        if ($m['statut'] === 'inventaire') {
            $this->refuser("{$m['libelle']} est le dernier mois de l'exercice : il est verrouillé par la clôture de l'exercice.");
        }
        if (!$m['prochain']) {
            $precedent = collect($liste['mois'])->firstWhere('prochain', true);
            $this->refuser("Les mois se clôturent dans l'ordre : clôturez d'abord {$precedent['libelle']}.");
        }
        if ($m['brouillons'] > 0) {
            $this->refuser("{$m['libelle']} contient {$m['brouillons']} pièce(s) en brouillon : validez-les ou supprimez-les.");
        }
        $desequilibrees = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->whereBetween('m.Date_mouvement', [$m['debut'], $m['fin']])
            ->groupBy('l.Id_Mouvement_ecriture')
            ->havingRaw('ABS(SUM(l."Debit") - SUM(l."Credit")) >= ?', [EcritureGuard::tolerance()])
            ->select('l.Id_Mouvement_ecriture')->get()->count();
        if ($desequilibrees > 0) {
            $this->refuser("{$m['libelle']} contient {$desequilibrees} pièce(s) déséquilibrée(s).");
        }

        DB::transaction(function () use ($exercice, $m, $mois) {
            $exercice->update(['periode_cloturee_au' => $m['fin']]);
            Audit::enregistrer(Audit::PERIODE_CLOTURE, 'exercice', $exercice->Id_Exercice_comptable,
                "{$m['libelle']} (exercice {$exercice->Annee_fiscale})", null, null,
                ['mois' => $mois, 'cloture_jusqu_au' => $m['fin']]);
        });

        return ['message' => "{$m['libelle']} clôturé : plus aucune écriture ne peut être datée du {$this->fr($m['fin'])} ou avant."];
    }

    private function motifBlocage(ExerciceComptable $exercice): ?string
    {
        if (!$exercice->isOuvert()) {
            return "L'exercice {$exercice->Annee_fiscale} n'est pas ouvert.";
        }
        $precedent = ExerciceComptable::where('Annee_fiscale', $exercice->Annee_fiscale - 1)->first();
        if ($precedent && $precedent->isOuvert()) {
            return "Clôturez d'abord l'exercice {$precedent->Annee_fiscale} : les à-nouveaux doivent exister avant de figer les mois de {$exercice->Annee_fiscale}.";
        }
        return null;
    }

    private function fr(string $date): string
    {
        return Carbon::parse($date)->format('d/m/Y');
    }

    private function refuser(string $message): never
    {
        throw ValidationException::withMessages(['periode' => [$message]]);
    }
}
