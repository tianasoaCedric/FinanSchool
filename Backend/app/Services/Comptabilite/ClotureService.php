<?php

namespace App\Services\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\Journal;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Clôture d'exercice (PCG 2005).
 *
 * 1. Contrôles : exercice ouvert, aucune pièce en brouillon, pièces validées
 *    équilibrées, dotations aux amortissements passées.
 * 2. Écriture de clôture dans N (journal CL, au dernier jour) : les comptes de
 *    gestion 6 et 7 de l'exercice sont soldés et le résultat est porté au 12
 *    (120 bénéfice, crédit / 129 perte, débit).
 * 3. À-nouveaux dans N+1 (journal AN, au premier jour) : soldes des comptes de
 *    bilan (classes 1 à 5, dont le 12) après clôture. Les résultats d'exercices
 *    antérieurs jamais clôturés par écritures (6/7 résiduels) sont reportés au
 *    110 (créditeur) ou 119 (débiteur). L'affectation du 12 (réserves,
 *    dividendes, report) se fait ensuite dans N+1 par une écriture ordinaire.
 * 4. Verrouillage de N (statut CLOTURE) et ouverture de N+1.
 *
 * La pièce de clôture est exclue des états financiers (voir
 * EtatsFinanciers::soldes) : le compte de résultat de N reste consultable.
 */
class ClotureService
{
    public function __construct(
        private EtatsFinanciers $etats = new EtatsFinanciers(),
        private DotationsService $dotations = new DotationsService(),
    ) {}

    /** Liste de contrôle avant clôture et aperçu des écritures générées. */
    public function preparation(ExerciceComptable $exercice): array
    {
        [$debut, $fin] = $this->bornes($exercice);

        $brouillons = MouvementEcriture::whereBetween('Date_mouvement', [$debut, $fin])
            ->whereHas('lignes', fn ($q) => $q->where(fn ($w) => $w->where('statut', '!=', EcritureGuard::STATUT_VALIDE)->orWhereNull('statut')))
            ->count();
        $desequilibrees = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->whereBetween('m.Date_mouvement', [$debut, $fin])
            ->groupBy('l.Id_Mouvement_ecriture')
            ->havingRaw('ABS(SUM(l."Debit") - SUM(l."Credit")) >= ?', [EcritureGuard::tolerance()])
            ->select('l.Id_Mouvement_ecriture')->get()->count();

        $plan = $this->dotations->calcul($exercice);
        // Dotations comptabilisées (générées, saisies ou importées) et validées
        $brouillonDot = $plan['piece'] && !$plan['piece']['validee'];
        $dotationsOk = $plan['reste_a_passer'] < 0.01 && !$brouillonDot;
        $ecartsRegistre = $plan['rapprochement'];
        $suivant = $this->suivant($exercice);
        $precedent = ExerciceComptable::where('Annee_fiscale', $exercice->Annee_fiscale - 1)->first();
        $precedentOk = !$precedent || $precedent->isCloture();

        $controles = [
            ['cle' => 'ouvert', 'libelle' => "L'exercice est ouvert", 'ok' => $exercice->isOuvert(), 'bloquant' => true,
             'detail' => $exercice->isOuvert() ? null : ($exercice->isClotureDefinitive() ? 'Exercice déjà clôturé' : "Statut actuel : {$exercice->Statut}")],
            ['cle' => 'precedent', 'libelle' => "L'exercice précédent est clôturé", 'ok' => $precedentOk, 'bloquant' => true,
             'detail' => $precedentOk ? null : "Clôturez d'abord l'exercice {$precedent->Annee_fiscale}"],
            ['cle' => 'brouillons', 'libelle' => 'Toutes les pièces sont validées', 'ok' => $brouillons === 0, 'bloquant' => true,
             'detail' => $brouillons ? "{$brouillons} pièce(s) encore en brouillon" : null, 'lien' => $brouillons ? '/pieces' : null],
            ['cle' => 'equilibre', 'libelle' => 'Toutes les pièces validées sont équilibrées', 'ok' => $desequilibrees === 0, 'bloquant' => true,
             'detail' => $desequilibrees ? "{$desequilibrees} pièce(s) déséquilibrée(s)" : null],
            ['cle' => 'dotations', 'libelle' => 'Dotations aux amortissements passées', 'ok' => $dotationsOk, 'bloquant' => false,
             'detail' => $dotationsOk ? null : ($brouillonDot
                ? "Pièce {$plan['piece']['numero']} à valider"
                : 'Dotations de ' . number_format($plan['reste_a_passer'], 2, ',', ' ') . ' Ar à générer')],
            // Informatif : n'empêche pas la clôture
            ['cle' => 'registre', 'libelle' => 'Registre des immobilisations conforme à la comptabilité', 'ok' => !$ecartsRegistre, 'bloquant' => false,
             'informatif' => true, 'lien' => $ecartsRegistre ? '/amortissement' : null,
             'detail' => $ecartsRegistre ? collect($ecartsRegistre)->map(fn ($e) => "{$e['compte']} : registre "
                . number_format($e['registre'], 2, ',', ' ') . ' / comptabilité ' . number_format($e['comptabilite'], 2, ',', ' '))->implode(' ; ') : null],
        ];

        $resultat = $this->etats->resultatNet($debut, $fin);
        $cloture = $this->lignesCloture($debut, $fin);
        $aExtourner = $this->regularisationsAExtourner($debut, $fin)->count();

        return [
            'exercice' => $this->resume($exercice),
            'resultat' => $resultat,
            'controles' => $controles,
            'peut_cloturer' => collect($controles)->where('bloquant', true)->every('ok'),
            'avertissements' => collect($controles)->where('bloquant', false)->where('ok', false)->count(),
            'apercu' => [
                'comptes_gestion_soldes' => count($cloture['lignes']) - ($cloture['compte_resultat'] ? 1 : 0),
                'compte_resultat' => $cloture['compte_resultat'],
                'date_cloture' => $fin,
                'date_a_nouveaux' => Carbon::parse($fin)->addDay()->toDateString(),
                'regularisations_a_extourner' => $aExtourner,
            ],
            'exercice_suivant' => $suivant ? $this->resume($suivant) : [
                'annee' => $exercice->Annee_fiscale + 1, 'a_creer' => true,
            ],
        ];
    }

    /** Clôture complète de l'exercice. */
    public function cloturer(ExerciceComptable $exercice, ?int $userId, bool $ignorerDotations = false): array
    {
        $prep = $this->preparation($exercice);
        foreach ($prep['controles'] as $c) {
            if (!$c['ok'] && empty($c['informatif']) && ($c['bloquant'] || !$ignorerDotations)) {
                throw ValidationException::withMessages(['cloture' => ["Clôture impossible : {$c['libelle']} — {$c['detail']}."]]);
            }
        }

        return DB::transaction(function () use ($exercice, $userId, $prep) {
            [$debut, $fin] = $this->bornes($exercice);
            $debutSuivant = Carbon::parse($fin)->addDay();

            // 1. Écriture de clôture : 6/7 de l'exercice → 12
            $cloture = $this->lignesCloture($debut, $fin);
            $pieceCloture = null;
            if ($cloture['lignes']) {
                $pieceCloture = $this->creerPiece(
                    ComptesSysteme::journalCloture(), (int) $exercice->Annee_fiscale, $fin,
                    "Clôture des comptes de gestion {$exercice->Annee_fiscale}", "CL-{$exercice->Annee_fiscale}",
                    MouvementEcriture::TYPE_CLOTURE, $cloture['lignes'], $userId
                );
            }

            // 2. Soldes des comptes au dernier jour de N, après l'écriture de clôture
            $soldes = $this->etats->soldesCumules($fin); // hors pièce de clôture
            foreach ($cloture['lignes'] as $l) {
                $code = $l['code'];
                $soldes[$code] = round(($soldes[$code] ?? 0) + $l['Debit'] - $l['Credit'], 2);
            }

            $codes = array_map('strval', array_keys($soldes));
            $ids = SousCompte::whereIn('Code_sous_compte', $codes)->pluck('Id_Sous_compte', 'Code_sous_compte');

            // Comptes de tiers : à-nouveaux détaillés par tiers (suivi et lettrage en N+1)
            $parTiers = collect(TiersService::soldesParTiers($fin))
                ->groupBy('Id_Sous_compte')
                ->filter(fn ($g) => $g->contains(fn ($r) => $r['id_tiers'] !== null));

            $lignes = [];
            $aReporter = 0.0; // résultats antérieurs jamais clôturés (débit − crédit)
            foreach ($soldes as $code => $s) {
                $code = (string) $code;
                if (abs($s) < 0.005) {
                    continue;
                }
                if (in_array($code[0], ['6', '7'], true)) {
                    $aReporter += $s;
                    continue;
                }
                if ($code[0] === '4' && $parTiers->has($ids[$code])) {
                    foreach ($parTiers[$ids[$code]] as $r) {
                        if (abs($r['solde']) < 0.005) {
                            continue;
                        }
                        $lignes[] = [
                            'Id_Sous_compte' => $ids[$code], 'id_tiers' => $r['id_tiers'],
                            'Debit' => $r['solde'] > 0 ? $r['solde'] : 0, 'Credit' => $r['solde'] < 0 ? -$r['solde'] : 0,
                            'Libelle' => 'À-nouveau',
                        ];
                    }
                    continue;
                }
                if (in_array($code[0], ['1', '2', '3', '4', '5'], true)) {
                    $lignes[] = [
                        'Id_Sous_compte' => $ids[$code], 'Debit' => $s > 0 ? $s : 0, 'Credit' => $s < 0 ? -$s : 0,
                        'Libelle' => str_starts_with($code, '12') ? "Résultat {$exercice->Annee_fiscale} en instance d'affectation" : 'À-nouveau',
                    ];
                }
            }
            $aReporter = round($aReporter, 2);
            if (abs($aReporter) >= 0.005) {
                $compte = $aReporter < 0 ? ComptesSysteme::reportBeneficiaire() : ComptesSysteme::reportDeficitaire();
                $lignes[] = [
                    'Id_Sous_compte' => $compte->Id_Sous_compte,
                    'Debit' => $aReporter > 0 ? $aReporter : 0,
                    'Credit' => $aReporter < 0 ? -$aReporter : 0,
                    'Libelle' => 'Résultats antérieurs non affectés',
                ];
            }
            $totalD = round(array_sum(array_column($lignes, 'Debit')), 2);
            $totalC = round(array_sum(array_column($lignes, 'Credit')), 2);
            if (abs($totalD - $totalC) >= EcritureGuard::tolerance()) {
                throw ValidationException::withMessages(['cloture' => ["À-nouveaux déséquilibrés ({$totalD} ≠ {$totalC}) : des écritures portent sur des comptes hors classes 1 à 7."]]);
            }

            // 3. Exercice suivant (créé au besoin), ouvert sur les à-nouveaux ; N verrouillé
            $suivant = $this->suivant($exercice) ?? ExerciceComptable::create([
                'Annee_fiscale' => $exercice->Annee_fiscale + 1,
                'Date_debut' => $debutSuivant->toDateString(),
                'Date_fin' => $debutSuivant->copy()->addYear()->subDay()->toDateString(),
                'Statut' => 'CLOTURE',
            ]);

            $pieceAn = null;
            if ($lignes) {
                $pieceAn = $this->creerPiece(
                    ComptesSysteme::journalANouveaux(), (int) $suivant->Annee_fiscale, $debutSuivant->toDateString(),
                    'À-nouveaux au ' . $debutSuivant->format('d/m/Y'), "AN-{$suivant->Annee_fiscale}",
                    MouvementEcriture::TYPE_A_NOUVEAU, $lignes, $userId
                );
            }

            $exercice->update([
                'Statut' => 'CLOTURE',
                'cloture_le' => now(),
                'cloture_par' => $userId,
                'resultat_cloture' => $prep['resultat'],
                'id_piece_cloture' => $pieceCloture?->Id_Mouvement_ecriture,
                'id_piece_an' => $pieceAn?->Id_Mouvement_ecriture,
            ]);
            $suivant->ouvrir();
            $suivant->update(['ouvert_par_a_nouveaux' => true]);

            // 4. Extourne des régularisations (CCA, PCA, charges à payer…) au 1er jour de N+1
            $extournes = 0;
            foreach ($this->regularisationsAExtourner($debut, $fin) as $origine) {
                $piece = EcritureGuard::contrePasser($origine, $debutSuivant->toDateString(), MouvementEcriture::TYPE_EXTOURNE_AUTO, $userId);
                EcritureGuard::validerPiece($piece->load('lignes'), $userId);
                $extournes++;
            }

            Audit::enregistrer(Audit::EXERCICE_CLOTURE, 'exercice', $exercice->Id_Exercice_comptable,
                "Exercice {$exercice->Annee_fiscale}", null, null, [
                    'resultat' => $prep['resultat'],
                    'piece_cloture' => $pieceCloture?->Numero_piece,
                    'piece_a_nouveaux' => $pieceAn?->Numero_piece,
                    'regularisations_extournees' => $extournes,
                    'exercice_ouvert' => $suivant->Annee_fiscale,
                ]);

            $sens = $prep['resultat'] >= 0 ? 'bénéfice' : 'perte';
            return [
                'message' => "Exercice {$exercice->Annee_fiscale} clôturé : {$sens} de "
                    . number_format(abs($prep['resultat']), 2, ',', ' ') . " Ar porté au 12. "
                    . "Exercice {$suivant->Annee_fiscale} ouvert sur les à-nouveaux"
                    . ($extournes ? ", {$extournes} régularisation(s) extournée(s)." : '.'),
                'resultat' => $prep['resultat'],
                'extournes' => $extournes,
                'piece_cloture' => $pieceCloture ? ['id' => $pieceCloture->Id_Mouvement_ecriture, 'numero' => $pieceCloture->Numero_piece] : null,
                'piece_an' => $pieceAn ? ['id' => $pieceAn->Id_Mouvement_ecriture, 'numero' => $pieceAn->Numero_piece, 'lignes' => count($lignes)] : null,
                'exercice_ouvert' => $this->resume($suivant->fresh()),
            ];
        });
    }

    /**
     * Annule une clôture (supprime l'écriture de clôture, les à-nouveaux et les
     * extournes automatiques, puis rouvre N). Les écritures déjà saisies dans
     * N+1 sont conservées. Refusée dès que N+1 a un mois clôturé ou que le
     * résultat a été affecté : la chronologie de N+1 est alors figée.
     */
    public function annuler(ExerciceComptable $exercice, string $motif): array
    {
        $refus = fn (string $m) => throw ValidationException::withMessages(['cloture' => [$m]]);
        if (!$exercice->isClotureDefinitive()) {
            $refus("L'exercice {$exercice->Annee_fiscale} n'a pas été clôturé par la procédure de clôture.");
        }
        $suivant = $this->suivant($exercice);
        if (!$suivant) {
            $refus("Exercice " . ($exercice->Annee_fiscale + 1) . ' introuvable.');
        }
        if ($suivant->isClotureDefinitive()) {
            $refus("Annulez d'abord la clôture de l'exercice {$suivant->Annee_fiscale}.");
        }
        if ($suivant->periode_cloturee_au) {
            $refus("Impossible d'annuler : des mois de {$suivant->Annee_fiscale} sont déjà clôturés.");
        }
        if (AffectationService::pieceActive($exercice)) {
            $refus("Impossible d'annuler : le résultat {$exercice->Annee_fiscale} a été affecté. Contre-passez ou supprimez d'abord l'affectation.");
        }

        return DB::transaction(function () use ($exercice, $suivant, $motif) {
            [$debut, $fin] = $this->bornes($exercice);
            $extournes = MouvementEcriture::where('type_piece', MouvementEcriture::TYPE_EXTOURNE_AUTO)
                ->whereIn('id_piece_origine', MouvementEcriture::whereBetween('Date_mouvement', [$debut, $fin])->select('Id_Mouvement_ecriture'))
                ->pluck('Id_Mouvement_ecriture')->all();
            $aSupprimer = array_values(array_filter([$exercice->id_piece_an, $exercice->id_piece_cloture, ...$extournes]));

            Audit::enregistrer(Audit::EXERCICE_ANNULATION_CLOTURE, 'exercice', $exercice->Id_Exercice_comptable,
                "Exercice {$exercice->Annee_fiscale}", null, null, [
                    'motif' => $motif,
                    'resultat_cloture' => $exercice->resultat_cloture,
                    'pieces_supprimees' => MouvementEcriture::whereIn('Id_Mouvement_ecriture', $aSupprimer)
                        ->orderBy('Id_Mouvement_ecriture')->get()->map->numeroAffiche()->all(),
                ]);

            // Lettrages portant sur des lignes supprimées : défaits
            LettrageService::liberer(LigneEcriture::whereIn('Id_Mouvement_ecriture', $aSupprimer)->pluck('Id_Ligne_ecriture')->all());
            foreach ($aSupprimer as $id) {
                LigneEcriture::where('Id_Mouvement_ecriture', $id)->delete();
                MouvementEcriture::whereKey($id)->delete();
            }
            // N+1 reste ouvert (N et N+1 peuvent l'être ensemble), sans à-nouveaux
            $suivant->update(['ouvert_par_a_nouveaux' => false]);
            $exercice->update([
                'cloture_le' => null, 'cloture_par' => null, 'id_piece_an' => null,
                'id_piece_cloture' => null, 'resultat_cloture' => null,
            ]);
            $exercice->ouvrir();
            return ['message' => "Clôture de l'exercice {$exercice->Annee_fiscale} annulée : il est de nouveau ouvert."];
        });
    }

    public function resume(ExerciceComptable $e): array
    {
        return [
            'id' => $e->Id_Exercice_comptable,
            'annee' => $e->Annee_fiscale,
            'date_debut' => Carbon::parse($e->Date_debut)->toDateString(),
            'date_fin' => Carbon::parse($e->Date_fin)->toDateString(),
            'statut' => $e->Statut,
            'cloture_definitive' => $e->isClotureDefinitive(),
            'cloture_le' => $e->cloture_le,
            'resultat_cloture' => $e->resultat_cloture,
            'ouvert_par_a_nouveaux' => (bool) $e->ouvert_par_a_nouveaux,
            'id_piece_cloture' => $e->id_piece_cloture,
            'id_piece_an' => $e->id_piece_an,
            'id_piece_affectation' => $e->id_piece_affectation,
            'periode_cloturee_au' => $e->periode_cloturee_au?->toDateString(),
        ];
    }

    // ------------------------------------------------------------------

    /**
     * Lignes de l'écriture de clôture : chaque compte 6/7 de l'exercice est
     * soldé, la différence (résultat) est portée au 120 ou au 129.
     */
    private function lignesCloture(string $debut, string $fin): array
    {
        $soldes = array_filter(
            $this->etats->soldes($debut, $fin),
            fn ($s, $code) => in_array(((string) $code)[0], ['6', '7'], true),
            ARRAY_FILTER_USE_BOTH
        );
        if (!$soldes) {
            return ['lignes' => [], 'compte_resultat' => null];
        }

        $ids = SousCompte::whereIn('Code_sous_compte', array_map('strval', array_keys($soldes)))->pluck('Id_Sous_compte', 'Code_sous_compte');
        $lignes = [];
        foreach ($soldes as $code => $s) {
            $lignes[] = [
                'code' => (string) $code, 'Id_Sous_compte' => $ids[(string) $code],
                'Debit' => $s < 0 ? -$s : 0, 'Credit' => $s > 0 ? $s : 0,
                'Libelle' => 'Solde de clôture',
            ];
        }

        $resultat = round(-EtatsFinanciers::somme($soldes, ['6', '7']), 2);
        $compteResultat = null;
        if (abs($resultat) >= 0.005) {
            $compte = $resultat > 0 ? ComptesSysteme::resultatBenefice() : ComptesSysteme::resultatPerte();
            $compteResultat = $compte->Code_sous_compte . ' — ' . $compte->Libelle;
            $lignes[] = [
                'code' => $compte->Code_sous_compte, 'Id_Sous_compte' => $compte->Id_Sous_compte,
                'Debit' => $resultat < 0 ? -$resultat : 0, 'Credit' => $resultat > 0 ? $resultat : 0,
                'Libelle' => $resultat > 0 ? "Résultat de l'exercice : bénéfice" : "Résultat de l'exercice : perte",
            ];
        }
        return ['lignes' => $lignes, 'compte_resultat' => $compteResultat];
    }

    /** Pièces validées de l'exercice marquées « à extourner » et pas encore contre-passées. */
    private function regularisationsAExtourner(string $debut, string $fin)
    {
        return MouvementEcriture::with(['lignes', 'journal'])
            ->where('a_extourner', true)
            ->whereBetween('Date_mouvement', [$debut, $fin])
            ->whereDoesntHave('contrePassation')
            ->whereHas('lignes')
            ->whereDoesntHave('lignes', fn ($q) => $q->where(fn ($w) => $w->where('statut', '!=', EcritureGuard::STATUT_VALIDE)->orWhereNull('statut')))
            ->orderBy('Date_mouvement')->orderBy('Id_Mouvement_ecriture')
            ->get();
    }

    /** Pièce système validée d'office (reprise de soldes contrôlés). À appeler dans une transaction. */
    private function creerPiece(Journal $journal, int $annee, string $date, string $libelle, string $reference,
                                string $type, array $lignes, ?int $userId): MouvementEcriture
    {
        $piece = MouvementEcriture::create([
            'Date_mouvement' => $date,
            'Id_Journal' => $journal->Id_Journal,
            'Numero_piece' => EcritureGuard::prochainNumeroPiece($journal, $annee),
            'Libelle' => $libelle,
            'Reference' => $reference,
            'type_piece' => $type,
            'created_by' => $userId,
        ]);
        foreach ($lignes as $l) {
            unset($l['code']);
            LigneEcriture::create($l + [
                'Id_Mouvement_ecriture' => $piece->Id_Mouvement_ecriture,
                'Id_Journal' => $journal->Id_Journal,
                'Reference' => $reference,
                'statut' => EcritureGuard::STATUT_VALIDE,
                'date_validation' => now(),
                'valide_par' => $userId,
            ]);
        }
        return $piece;
    }

    private function bornes(ExerciceComptable $e): array
    {
        return [Carbon::parse($e->Date_debut)->toDateString(), Carbon::parse($e->Date_fin)->toDateString()];
    }

    private function suivant(ExerciceComptable $e): ?ExerciceComptable
    {
        return ExerciceComptable::where('Annee_fiscale', $e->Annee_fiscale + 1)->first();
    }
}
