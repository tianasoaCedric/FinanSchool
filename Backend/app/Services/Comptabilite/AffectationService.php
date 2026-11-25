<?php

namespace App\Services\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Affectation du résultat de N (décision de l'assemblée), passée dans N+1 :
 *  - bénéfice : débit 120, crédit réserves (106x), report à nouveau (110),
 *    dividendes à payer (457) ;
 *  - perte : crédit 129, débit report à nouveau débiteur (119) ou imputation
 *    sur les réserves (106x) / le report créditeur (110).
 */
class AffectationService
{
    /** Destinations proposées : code => [libellé, sens possible]. */
    private function destinations(bool $benefice): array
    {
        $comptes = $benefice
            ? [
                ComptesSysteme::sousCompte('106100', 'Réserve légale'),
                ComptesSysteme::sousCompte('106300', 'Réserves statutaires'),
                ComptesSysteme::sousCompte('106800', 'Autres réserves'),
                ComptesSysteme::reportBeneficiaire(),
                ComptesSysteme::sousCompte('457000', 'Associés — dividendes à payer'),
            ]
            : [
                ComptesSysteme::reportDeficitaire(),
                ComptesSysteme::sousCompte('106800', 'Autres réserves'),
                ComptesSysteme::reportBeneficiaire(),
            ];
        return collect($comptes)->mapWithKeys(fn ($c) => [$c->Code_sous_compte => $c])->all();
    }

    public function preparation(ExerciceComptable $exercice): array
    {
        $resultat = (float) $exercice->resultat_cloture;
        $suivant = ExerciceComptable::where('Annee_fiscale', $exercice->Annee_fiscale + 1)->first();
        $piece = self::pieceActive($exercice);

        $motif = match (true) {
            !$exercice->isClotureDefinitive() => "Clôturez d'abord l'exercice {$exercice->Annee_fiscale}.",
            abs($resultat) < 0.005 => 'Résultat nul : rien à affecter.',
            !$suivant || !$suivant->isOuvert() => 'L\'exercice ' . ($exercice->Annee_fiscale + 1) . ' doit être ouvert.',
            default => null,
        };

        $benefice = $resultat >= 0;
        return [
            'exercice' => $exercice->Annee_fiscale,
            'resultat' => $resultat,
            'compte_resultat' => $benefice ? '120000' : '129000',
            'destinations' => $motif && !$exercice->isClotureDefinitive() ? [] : collect($this->destinations($benefice))
                ->map(fn ($c) => ['code' => $c->Code_sous_compte, 'libelle' => $c->Libelle])->values()->all(),
            'exercice_suivant' => $suivant ? [
                'annee' => $suivant->Annee_fiscale,
                'date_debut' => Carbon::parse($suivant->Date_debut)->toDateString(),
                'date_fin' => Carbon::parse($suivant->Date_fin)->toDateString(),
            ] : null,
            'piece' => $piece ? [
                'id' => $piece->Id_Mouvement_ecriture,
                'numero' => $piece->numeroAffiche(),
                'validee' => $piece->lignes->isNotEmpty() && $piece->lignes->every(fn ($l) => $l->statut === EcritureGuard::STATUT_VALIDE),
            ] : null,
            'motif_blocage' => $motif,
        ];
    }

    /**
     * @param array $repartition [['code' => '106100', 'montant' => 1000], …]
     */
    public function generer(ExerciceComptable $exercice, string $date, string $reference, array $repartition, bool $valider, ?int $userId): MouvementEcriture
    {
        $prep = $this->preparation($exercice);
        if ($prep['motif_blocage']) {
            $this->refuser($prep['motif_blocage']);
        }
        if ($prep['piece'] && $prep['piece']['validee']) {
            $this->refuser("Le résultat {$exercice->Annee_fiscale} est déjà affecté (pièce {$prep['piece']['numero']}). Contre-passez-la pour la corriger.");
        }
        $s = $prep['exercice_suivant'];
        if ($date < $s['date_debut'] || $date > $s['date_fin']) {
            $this->refuser("L'affectation se passe dans l'exercice {$s['annee']} (date de la décision de l'assemblée).");
        }

        $benefice = $prep['resultat'] >= 0;
        $destinations = $this->destinations($benefice);
        $lignes = collect($repartition)
            ->map(fn ($r) => ['code' => (string) ($r['code'] ?? ''), 'montant' => round((float) ($r['montant'] ?? 0), 2)])
            ->filter(fn ($r) => $r['montant'] > 0);
        foreach ($lignes as $r) {
            if (!isset($destinations[$r['code']])) {
                $this->refuser("Compte {$r['code']} non autorisé pour l'affectation d'un " . ($benefice ? 'bénéfice' : 'déficit') . '.');
            }
        }
        $total = round($lignes->sum('montant'), 2);
        $attendu = round(abs($prep['resultat']), 2);
        if ($lignes->isEmpty() || abs($total - $attendu) >= EcritureGuard::TOLERANCE) {
            $this->refuser(sprintf('La répartition (%s) doit égaler le résultat à affecter (%s).',
                number_format($total, 2, ',', ' '), number_format($attendu, 2, ',', ' ')));
        }

        return DB::transaction(function () use ($exercice, $date, $reference, $lignes, $benefice, $attendu, $destinations, $valider, $userId, $prep) {
            EcritureGuard::exerciceOuvertPour($date);
            if ($prep['piece']) {
                $ancienne = MouvementEcriture::find($prep['piece']['id']);
                if ($ancienne) {
                    Audit::piece(Audit::PIECE_SUPPRESSION, $ancienne, Audit::etatPiece($ancienne),
                        ['motif' => "Remplacée par une nouvelle affectation du résultat"]);
                }
                LigneEcriture::where('Id_Mouvement_ecriture', $prep['piece']['id'])->delete();
                MouvementEcriture::whereKey($prep['piece']['id'])->delete();
            }

            $journal = ComptesSysteme::journalOperationsDiverses();
            $compte12 = $benefice ? ComptesSysteme::resultatBenefice() : ComptesSysteme::resultatPerte();
            $libelle = "Affectation du résultat {$exercice->Annee_fiscale}";
            $piece = MouvementEcriture::create([
                'Date_mouvement' => $date,
                'Id_Journal' => $journal->Id_Journal,
                'Libelle' => $libelle,
                'Reference' => mb_substr($reference, 0, 100),
                'type_piece' => MouvementEcriture::TYPE_AFFECTATION,
                'created_by' => $userId,
            ]);
            $commun = [
                'Id_Mouvement_ecriture' => $piece->Id_Mouvement_ecriture,
                'Id_Journal' => $journal->Id_Journal,
                'Reference' => mb_substr($reference, 0, 50),
                'statut' => EcritureGuard::STATUT_BROUILLON,
            ];
            LigneEcriture::create($commun + [
                'Id_Sous_compte' => $compte12->Id_Sous_compte, 'Libelle' => $libelle,
                'Debit' => $benefice ? $attendu : 0, 'Credit' => $benefice ? 0 : $attendu,
            ]);
            foreach ($lignes as $r) {
                $c = $destinations[$r['code']];
                LigneEcriture::create($commun + [
                    'Id_Sous_compte' => $c->Id_Sous_compte, 'Libelle' => "{$libelle} — {$c->Libelle}",
                    'Debit' => $benefice ? 0 : $r['montant'], 'Credit' => $benefice ? $r['montant'] : 0,
                ]);
            }
            Audit::piece(Audit::RESULTAT_AFFECTATION, $piece, null, [
                'exercice' => $exercice->Annee_fiscale,
                'reference' => $reference,
                'repartition' => collect($lignes)->map(fn ($r) => ['compte' => $r['code'], 'montant' => (float) $r['montant']])->values()->all(),
            ]);
            if ($valider) {
                EcritureGuard::validerPiece($piece->load('lignes'), $userId);
            }
            $exercice->update(['id_piece_affectation' => $piece->Id_Mouvement_ecriture]);
            return $piece;
        });
    }

    /** Pièce d'affectation en vigueur (ni supprimée ni contre-passée), ou null. */
    public static function pieceActive(ExerciceComptable $exercice): ?MouvementEcriture
    {
        if (!$exercice->id_piece_affectation) {
            return null;
        }
        $piece = MouvementEcriture::with('lignes')->find($exercice->id_piece_affectation);
        if (!$piece || MouvementEcriture::where('id_piece_origine', $piece->Id_Mouvement_ecriture)->exists()) {
            return null;
        }
        return $piece;
    }

    private function refuser(string $message): never
    {
        throw ValidationException::withMessages(['affectation' => [$message]]);
    }
}
