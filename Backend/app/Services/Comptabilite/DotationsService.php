<?php

namespace App\Services\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dotations aux amortissements (linéaire, prorata temporis au jour).
 * Génère la pièce de l'exercice : débit 681 / crédit 28x, en brouillon pour
 * relecture, puis validée par l'utilisateur.
 */
class DotationsService
{
    /** Plan d'amortissement de l'exercice, immobilisation par immobilisation. */
    public function calcul(ExerciceComptable $exercice): array
    {
        $debut = Carbon::parse($exercice->Date_debut)->startOfDay();
        $fin = Carbon::parse($exercice->Date_fin)->startOfDay();
        $joursExercice = $debut->diffInDays($fin) + 1;

        $immos = DB::table('immobilisations as i')
            ->join('taux_amortissement as t', 't.id', '=', 'i.taux_amortissement_id')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'i.Id_Sous_compte')
            // Seules les immobilisations (classe 2, hors amortissements 28 et dépréciations 29)
            ->where('s.Code_sous_compte', 'like', '2%')
            ->where('s.Code_sous_compte', 'not like', '28%')
            ->where('s.Code_sous_compte', 'not like', '29%')
            ->select('i.*', 't.taux', 't.intitule as categorie', 's.Code_sous_compte', 's.Libelle as compte_libelle')
            ->orderBy('i.date_acquisition')
            ->get();

        $lignes = [];
        foreach ($immos as $immo) {
            $base = (float) $immo->valeur_brute;
            $annuite = round($base * (float) $immo->taux / 100, 2);
            $depart = Carbon::parse($immo->date_debut_utilisation ?? $immo->date_acquisition)->startOfDay();

            // Amortissement théorique déjà pratiqué avant l'exercice
            $cumulAvant = 0.0;
            if ($depart->lt($debut)) {
                $cumulAvant = min($base, round($annuite * $depart->diffInDays($debut) / 365, 2));
            }
            // Dotation de l'exercice (prorata au jour)
            $dotation = 0.0;
            if ($depart->lte($fin)) {
                $debutCalcul = $depart->gt($debut) ? $depart : $debut;
                $jours = $debutCalcul->diffInDays($fin) + 1;
                $dotation = min(max($base - $cumulAvant, 0), round($annuite * $jours / $joursExercice, 2));
            }

            $lignes[] = [
                'id' => $immo->id,
                'libelle' => $immo->libelle,
                'compte' => $immo->Code_sous_compte . ' — ' . $immo->compte_libelle,
                'categorie' => $immo->categorie,
                'valeur_brute' => $base,
                'taux' => (float) $immo->taux,
                'debut_amortissement' => $depart->toDateString(),
                'cumul_anterieur' => round($cumulAvant, 2),
                'dotation' => round($dotation, 2),
                'vnc_fin' => round($base - $cumulAvant - $dotation, 2),
                'Id_Sous_compte' => $immo->Id_Sous_compte,
            ];
        }

        $piece = $this->pieceExistante($exercice);
        return [
            'exercice' => $exercice->Annee_fiscale,
            'total' => round(array_sum(array_column($lignes, 'dotation')), 2),
            'immobilisations' => $lignes,
            'piece' => $piece ? [
                'id' => $piece->Id_Mouvement_ecriture,
                'numero' => $piece->Numero_piece,
                'validee' => $this->estValidee($piece),
            ] : null,
        ];
    }

    /** Génère (ou régénère si encore en brouillon) la pièce de dotations. */
    public function generer(ExerciceComptable $exercice, ?int $userId): MouvementEcriture
    {
        EcritureGuard::exerciceOuvertPour($exercice->Date_fin);
        $calcul = $this->calcul($exercice);
        $aPasser = array_filter($calcul['immobilisations'], fn ($l) => $l['dotation'] > 0);
        if (!$aPasser) {
            throw ValidationException::withMessages(['dotations' => ["Aucune dotation à passer pour l'exercice {$exercice->Annee_fiscale}."]]);
        }

        return DB::transaction(function () use ($exercice, $aPasser, $userId) {
            $existante = $this->pieceExistante($exercice);
            if ($existante) {
                if ($this->estValidee($existante)) {
                    throw ValidationException::withMessages(['dotations' => ["Les dotations {$exercice->Annee_fiscale} sont déjà validées (pièce {$existante->Numero_piece})."]]);
                }
                $existante->lignes()->delete();
                $existante->delete();
            }

            $journal = ComptesSysteme::journalOperationsDiverses();
            $compteDotation = ComptesSysteme::dotationsAmortissements();
            $fin = Carbon::parse($exercice->Date_fin)->toDateString();

            $piece = MouvementEcriture::create([
                'Date_mouvement' => $fin,
                'Id_Journal' => $journal->Id_Journal,
                'Numero_piece' => EcritureGuard::prochainNumeroPiece($journal, (int) $exercice->Annee_fiscale),
                'Libelle' => "Dotations aux amortissements {$exercice->Annee_fiscale}",
                'Reference' => "DOT-{$exercice->Annee_fiscale}",
                'type_piece' => MouvementEcriture::TYPE_DOTATION,
                'created_by' => $userId,
            ]);

            foreach ($aPasser as $l) {
                $compteAmort = ComptesSysteme::amortissementDe(SousCompte::findOrFail($l['Id_Sous_compte']));
                $commun = [
                    'Libelle' => "Dotation {$exercice->Annee_fiscale} — {$l['libelle']}",
                    'Id_Mouvement_ecriture' => $piece->Id_Mouvement_ecriture,
                    'Id_Journal' => $journal->Id_Journal,
                    'Reference' => "DOT-{$exercice->Annee_fiscale}",
                    'statut' => EcritureGuard::STATUT_BROUILLON,
                ];
                LigneEcriture::create($commun + ['Id_Sous_compte' => $compteDotation->Id_Sous_compte, 'Debit' => $l['dotation'], 'Credit' => 0]);
                LigneEcriture::create($commun + ['Id_Sous_compte' => $compteAmort->Id_Sous_compte, 'Debit' => 0, 'Credit' => $l['dotation']]);
            }

            return $piece;
        });
    }

    public function pieceExistante(ExerciceComptable $exercice): ?MouvementEcriture
    {
        return MouvementEcriture::with('lignes')
            ->where('type_piece', MouvementEcriture::TYPE_DOTATION)
            ->whereBetween('Date_mouvement', [Carbon::parse($exercice->Date_debut)->toDateString(), Carbon::parse($exercice->Date_fin)->toDateString()])
            ->first();
    }

    public function estValidee(MouvementEcriture $piece): bool
    {
        $piece->loadMissing('lignes');
        return $piece->lignes->isNotEmpty() && $piece->lignes->every(fn ($l) => $l->statut === EcritureGuard::STATUT_VALIDE);
    }
}
