<?php

namespace App\Services\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\PlanCompte\Amortissement;
use App\Models\PlanCompte\SousCompte;
use App\Models\Saisie\LigneEcriture;
use App\Models\Saisie\MouvementEcriture;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Amortissements des immobilisations (PCG 2005).
 *
 *  - Linéaire, prorata temporis au jour, à partir de la mise en service, jusqu'à
 *    la sortie de l'actif ou l'amortissement complet.
 *  - Chaque exercice est calculé sur sa propre durée (365 ou 366 jours) : le cumul
 *    antérieur est la somme des dotations des exercices précédents, recalculées à
 *    l'identique (pas d'écart en année bissextile).
 *  - Comptes non amortissables exclus : terrains (211), immobilisations en cours (23),
 *    participations et immobilisations financières (26, 27), amortissements et
 *    dépréciations (28, 29).
 *  - Les dotations déjà comptabilisées (pièce générée, saisie manuelle ou import)
 *    sont lues sur les comptes 28 : seule la différence est proposée (complément).
 */
class DotationsService
{
    public const PREFIXES_NON_AMORTISSABLES = ['211', '23', '26', '27', '28', '29'];
    private const EPSILON = 0.005;

    private ?Collection $exercices = null;

    public static function estImmobilisation(string $code): bool
    {
        return str_starts_with($code, '2') && !str_starts_with($code, '28') && !str_starts_with($code, '29');
    }

    public static function estAmortissable(string $code): bool
    {
        if (!self::estImmobilisation($code)) {
            return false;
        }
        foreach (self::PREFIXES_NON_AMORTISSABLES as $p) {
            if (str_starts_with($code, $p)) {
                return false;
            }
        }
        return true;
    }

    /** Compte 28 d'une immobilisation : celui de la fiche, sinon la règle 2Xyzzz → 28Xyzz. */
    public static function compteAmortissement(Amortissement $immo): SousCompte
    {
        if ($immo->Id_Sous_compte_amortissement) {
            return SousCompte::findOrFail($immo->Id_Sous_compte_amortissement);
        }
        return ComptesSysteme::amortissementDe(SousCompte::findOrFail($immo->Id_Sous_compte));
    }

    // ------------------------------------------------------------------
    // Calcul
    // ------------------------------------------------------------------

    /** Plan d'amortissement de l'exercice, immobilisation par immobilisation. */
    public function calcul(ExerciceComptable $exercice): array
    {
        [$debut, $fin] = $this->bornes($exercice);

        $immos = Amortissement::with(['tauxAmortissement', 'sousCompte'])->orderBy('date_acquisition')->get()
            ->filter(fn ($i) => $i->sousCompte && self::estAmortissable($i->sousCompte->Code_sous_compte) && $i->tauxAmortissement)
            ->filter(fn ($i) => !$i->date_sortie || Carbon::parse($i->date_sortie)->gte($debut));

        $lignes = [];
        foreach ($immos as $immo) {
            $cumulAvant = $this->cumulAu($immo, $debut->copy()->subDay());
            $cumulFin = $this->cumulAu($immo, $fin);
            $compteAmort = self::compteAmortissement($immo);
            $lignes[] = [
                'id' => $immo->id,
                'libelle' => $immo->libelle,
                'compte' => $immo->sousCompte->Code_sous_compte . ' — ' . $immo->sousCompte->Libelle,
                'compte_amortissement' => $compteAmort->Code_sous_compte,
                'categorie' => $immo->tauxAmortissement->intitule,
                'valeur_brute' => (float) $immo->valeur_brute,
                'taux' => (float) $immo->tauxAmortissement->taux,
                'debut_amortissement' => $this->depart($immo)->toDateString(),
                'date_sortie' => $immo->date_sortie ? Carbon::parse($immo->date_sortie)->toDateString() : null,
                'cumul_anterieur' => $cumulAvant,
                'dotation' => round($cumulFin - $cumulAvant, 2),
                'vnc_fin' => round((float) $immo->valeur_brute - $cumulFin, 2),
                'Id_Sous_compte' => $immo->Id_Sous_compte,
                'Id_Sous_compte_amortissement' => $compteAmort->Id_Sous_compte,
            ];
        }

        // Rapprochement par compte 28 : théorique / déjà comptabilisé / reste
        $comptabilise = $this->dejaComptabilise($exercice);
        $parCompte = [];
        foreach ($lignes as $l) {
            $c = $l['compte_amortissement'];
            $parCompte[$c] ??= ['compte' => $c, 'theorique' => 0.0, 'comptabilise' => 0.0];
            $parCompte[$c]['theorique'] += $l['dotation'];
        }
        foreach ($comptabilise as $c => $m) {
            $parCompte[$c] ??= ['compte' => $c, 'theorique' => 0.0, 'comptabilise' => 0.0];
            $parCompte[$c]['comptabilise'] = $m;
        }
        foreach ($parCompte as &$p) {
            $p['theorique'] = round($p['theorique'], 2);
            $p['reste'] = round($p['theorique'] - $p['comptabilise'], 2);
        }
        unset($p);

        $total = round(array_sum(array_column($lignes, 'dotation')), 2);
        $reste = round(array_sum(array_map(fn ($p) => max(0, $p['reste']), $parCompte)), 2);
        $exces = array_values(array_filter($parCompte, fn ($p) => $p['reste'] < -self::EPSILON));

        $piece = $this->pieceExistante($exercice);
        return [
            'exercice' => $exercice->Annee_fiscale,
            'total' => $total,
            'deja_comptabilise' => round(array_sum(array_column($parCompte, 'comptabilise')), 2),
            'reste_a_passer' => $reste,
            'exces' => $exces,
            'par_compte' => array_values($parCompte),
            'immobilisations' => $lignes,
            'rapprochement' => $this->rapprochementRegistre($fin),
            'piece' => $piece ? [
                'id' => $piece->Id_Mouvement_ecriture,
                'numero' => $piece->numeroAffiche(),
                'validee' => $this->estValidee($piece),
            ] : null,
        ];
    }

    /**
     * Amortissement cumulé d'une immobilisation au soir du $date (sortie et
     * plafond de la valeur brute compris), exercice par exercice.
     */
    public function cumulAu(Amortissement $immo, Carbon $date): float
    {
        $base = (float) $immo->valeur_brute;
        $taux = (float) ($immo->tauxAmortissement?->taux ?? 0);
        $sousCompte = $immo->sousCompte;
        if ($base <= 0 || $taux <= 0 || !$sousCompte || !self::estAmortissable($sousCompte->Code_sous_compte)) {
            return 0.0;
        }
        $annuite = $base * $taux / 100;
        $a = $this->depart($immo);
        $b = $date->copy()->startOfDay();
        if ($immo->date_sortie && Carbon::parse($immo->date_sortie)->lt($b)) {
            $b = Carbon::parse($immo->date_sortie)->startOfDay();
        }

        $cumul = 0.0;
        foreach ($this->segments($a, $b) as [$jours, $joursPeriode]) {
            $cumul += min(round($annuite * $jours / $joursPeriode, 2), $base - $cumul);
            if ($cumul >= $base) {
                return round($base, 2);
            }
        }
        return round($cumul, 2);
    }

    /**
     * Découpe [a, b] selon les exercices comptables : [jours dans le segment,
     * durée de l'exercice]. Hors exercice connu, on retient 365 jours.
     */
    private function segments(Carbon $a, Carbon $b): array
    {
        $segments = [];
        $curseur = $a->copy();
        foreach ($this->exercices() as $e) {
            if ($curseur->gt($b)) {
                break;
            }
            [$ed, $ef] = $this->bornes($e);
            if ($ef->lt($curseur)) {
                continue;
            }
            if ($ed->gt($b)) {
                break;
            }
            if ($ed->gt($curseur)) {
                $finTrou = $ed->copy()->subDay()->min($b);
                $segments[] = [$this->jours($curseur, $finTrou), 365];
                $curseur = $finTrou->copy()->addDay();
                if ($curseur->gt($b)) {
                    break;
                }
            }
            $finSeg = $ef->copy()->min($b);
            $segments[] = [$this->jours($curseur, $finSeg), $this->jours($ed, $ef)];
            $curseur = $finSeg->copy()->addDay();
        }
        if ($curseur->lte($b)) {
            $segments[] = [$this->jours($curseur, $b), 365];
        }
        return $segments;
    }

    /**
     * Dotations déjà comptabilisées sur l'exercice, par compte 28 (crédit − débit),
     * toutes origines : pièces générées validées, saisies manuelles, imports.
     * Exclus : à-nouveaux, clôture, sorties d'actif, pièces de dotation en brouillon
     * (elles seront régénérées).
     */
    public function dejaComptabilise(ExerciceComptable $exercice): array
    {
        [$debut, $fin] = $this->bornes($exercice);
        $brouillonsDot = $this->piecesDotation($exercice)->reject(fn ($p) => $this->estValidee($p))->pluck('Id_Mouvement_ecriture');

        return DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->whereBetween('m.Date_mouvement', [$debut->toDateString(), $fin->toDateString()])
            ->where('s.Code_sous_compte', 'like', '28%')
            ->where(fn ($q) => $q->whereNull('m.type_piece')->orWhereNotIn('m.type_piece', [
                MouvementEcriture::TYPE_A_NOUVEAU, MouvementEcriture::TYPE_CLOTURE, MouvementEcriture::TYPE_CESSION,
            ]))
            ->whereNotIn('m.Id_Mouvement_ecriture', $brouillonsDot->all())
            ->groupBy('s.Code_sous_compte')
            ->selectRaw('s."Code_sous_compte" as code, ROUND(SUM(l."Credit" - l."Debit")::numeric, 2) as montant')
            ->pluck('montant', 'code')
            ->map(fn ($m) => (float) $m)
            ->filter(fn ($m) => abs($m) >= self::EPSILON)
            ->all();
    }

    /**
     * Registre ↔ comptabilité : valeur brute des fiches en service au $fin
     * comparée au solde des comptes 2 (hors 28/29), à-nouveaux exclus pour
     * ne pas dépendre de la clôture de l'exercice précédent.
     */
    public function rapprochementRegistre(Carbon $fin): array
    {
        $registre = [];
        foreach (Amortissement::with('sousCompte')->get() as $i) {
            if (!$i->sousCompte || Carbon::parse($i->date_acquisition)->gt($fin)
                || ($i->date_sortie && Carbon::parse($i->date_sortie)->lte($fin))) {
                continue;
            }
            $c = $i->sousCompte->Code_sous_compte;
            $registre[$c] = ($registre[$c] ?? 0) + (float) $i->valeur_brute;
        }

        $compta = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->where('m.Date_mouvement', '<=', $fin->toDateString())
            ->where('s.Code_sous_compte', 'like', '2%')
            ->where('s.Code_sous_compte', 'not like', '28%')
            ->where('s.Code_sous_compte', 'not like', '29%')
            ->where(fn ($q) => $q->whereNull('m.type_piece')->orWhereNotIn('m.type_piece', [
                MouvementEcriture::TYPE_A_NOUVEAU, MouvementEcriture::TYPE_CLOTURE,
            ]))
            ->groupBy('s.Code_sous_compte')
            ->selectRaw('s."Code_sous_compte" as code, ROUND(SUM(l."Debit" - l."Credit")::numeric, 2) as solde')
            ->pluck('solde', 'code')->map(fn ($v) => (float) $v)->all();

        $ecarts = [];
        foreach (array_unique(array_merge(array_keys($registre), array_keys($compta))) as $code) {
            $r = round($registre[$code] ?? 0, 2);
            $s = round($compta[$code] ?? 0, 2);
            if (abs($r - $s) >= 0.01) {
                $ecarts[] = ['compte' => (string) $code, 'registre' => $r, 'comptabilite' => $s, 'ecart' => round($s - $r, 2)];
            }
        }
        usort($ecarts, fn ($x, $y) => strcmp($x['compte'], $y['compte']));
        return $ecarts;
    }

    // ------------------------------------------------------------------
    // Pièce de dotations
    // ------------------------------------------------------------------

    /**
     * Génère la pièce des dotations restant à passer (brouillon) ; remplace un
     * brouillon existant. Si des dotations sont déjà comptabilisées sur un compte
     * 28, seul le complément est passé.
     */
    public function generer(ExerciceComptable $exercice, ?int $userId): MouvementEcriture
    {
        EcritureGuard::exerciceOuvertPour($exercice->Date_fin);

        return DB::transaction(function () use ($exercice, $userId) {
            foreach ($this->piecesDotation($exercice)->reject(fn ($p) => $this->estValidee($p)) as $brouillon) {
                Audit::piece(Audit::PIECE_SUPPRESSION, $brouillon, Audit::etatPiece($brouillon),
                    ['motif' => 'Remplacée par une nouvelle génération des dotations']);
                $brouillon->lignes()->delete();
                $brouillon->delete();
            }

            $calcul = $this->calcul($exercice);
            if ($calcul['reste_a_passer'] < 0.01) {
                $message = $calcul['total'] > 0
                    ? "Les dotations {$exercice->Annee_fiscale} sont déjà comptabilisées (" . number_format($calcul['deja_comptabilise'], 2, ',', ' ') . ' Ar).'
                    : "Aucune dotation à passer pour l'exercice {$exercice->Annee_fiscale}.";
                throw ValidationException::withMessages(['dotations' => [$message]]);
            }

            $journal = ComptesSysteme::journalOperationsDiverses();
            $compteDotation = ComptesSysteme::dotationsAmortissements();
            $fin = Carbon::parse($exercice->Date_fin)->toDateString();
            $reference = "DOT-{$exercice->Annee_fiscale}";
            $complement = $calcul['deja_comptabilise'] > 0;

            $piece = MouvementEcriture::create([
                'Date_mouvement' => $fin,
                'Id_Journal' => $journal->Id_Journal,
                'Libelle' => ($complement ? 'Complément de dotations' : 'Dotations') . " aux amortissements {$exercice->Annee_fiscale}",
                'Reference' => $reference,
                'type_piece' => MouvementEcriture::TYPE_DOTATION,
                'created_by' => $userId,
            ]);
            $commun = [
                'Id_Mouvement_ecriture' => $piece->Id_Mouvement_ecriture,
                'Id_Journal' => $journal->Id_Journal,
                'Reference' => $reference,
                'statut' => EcritureGuard::STATUT_BROUILLON,
            ];

            $immosParCompte = collect($calcul['immobilisations'])->where('dotation', '>', 0)->groupBy('compte_amortissement');
            foreach ($calcul['par_compte'] as $p) {
                if ($p['reste'] < 0.01) {
                    continue;
                }
                $immos = $immosParCompte[$p['compte']] ?? collect();
                $idAmort = SousCompte::where('Code_sous_compte', $p['compte'])->value('Id_Sous_compte');
                // Rien encore passé sur ce compte : une ligne par immobilisation ; sinon le complément
                $detail = $p['comptabilise'] == 0
                    ? $immos->map(fn ($l) => [$l['dotation'], "Dotation {$exercice->Annee_fiscale} — {$l['libelle']}"])
                    : collect([[$p['reste'], "Complément dotation {$exercice->Annee_fiscale} — compte {$p['compte']}"]]);
                foreach ($detail as [$montant, $libelle]) {
                    LigneEcriture::create($commun + ['Libelle' => $libelle, 'Id_Sous_compte' => $compteDotation->Id_Sous_compte, 'Debit' => $montant, 'Credit' => 0]);
                    LigneEcriture::create($commun + ['Libelle' => $libelle, 'Id_Sous_compte' => $idAmort, 'Debit' => 0, 'Credit' => $montant]);
                }
            }

            Audit::piece(Audit::DOTATIONS_GENERATION, $piece, null, [
                'exercice' => $exercice->Annee_fiscale,
                'montant' => $calcul['reste_a_passer'],
                'complement' => $complement,
            ]);

            return $piece;
        });
    }

    /** Pièces de dotation générées sur l'exercice. */
    public function piecesDotation(ExerciceComptable $exercice): Collection
    {
        [$debut, $fin] = $this->bornes($exercice);
        return MouvementEcriture::with('lignes')
            ->where('type_piece', MouvementEcriture::TYPE_DOTATION)
            ->whereBetween('Date_mouvement', [$debut->toDateString(), $fin->toDateString()])
            ->orderBy('Id_Mouvement_ecriture')
            ->get();
    }

    /** Pièce à afficher : le brouillon en cours, sinon la dernière validée. */
    public function pieceExistante(ExerciceComptable $exercice): ?MouvementEcriture
    {
        $pieces = $this->piecesDotation($exercice);
        return $pieces->first(fn ($p) => !$this->estValidee($p)) ?? $pieces->last();
    }

    public function estValidee(MouvementEcriture $piece): bool
    {
        $piece->loadMissing('lignes');
        return $piece->lignes->isNotEmpty() && $piece->lignes->every(fn ($l) => $l->statut === EcritureGuard::STATUT_VALIDE);
    }

    // ------------------------------------------------------------------
    // Sortie d'actif (cession, mise au rebut)
    // ------------------------------------------------------------------

    /**
     * Sortie d'une immobilisation : pièce en brouillon à la date de sortie.
     *   D 28x  amortissements cumulés à la date de sortie
     *   C 2x   valeur brute
     *   D contrepartie (banque, créance 462…) du prix de cession, s'il y en a un
     *   et la différence prix − VNC : C 752 (plus-value) ou D 652 (moins-value).
     * La dotation de l'exercice court jusqu'à la date de sortie.
     */
    public function sortir(Amortissement $immo, string $date, float $prix, ?int $idContrepartie, ?int $userId): MouvementEcriture
    {
        if ($immo->date_sortie) {
            throw ValidationException::withMessages(['date_sortie' => ['Cette immobilisation est déjà sortie de l\'actif.']]);
        }
        $jour = Carbon::parse($date)->startOfDay();
        if ($jour->lt(Carbon::parse($immo->date_acquisition))) {
            throw ValidationException::withMessages(['date_sortie' => ['La date de sortie précède la date d\'acquisition.']]);
        }
        if ($prix > 0 && !$idContrepartie) {
            throw ValidationException::withMessages(['Id_Sous_compte_contrepartie' => ['Indiquez le compte qui reçoit le prix de cession.']]);
        }
        if ($motif = EcritureGuard::motifDateFermee($jour->toDateString())) {
            throw ValidationException::withMessages(['date_sortie' => [$motif]]);
        }

        return DB::transaction(function () use ($immo, $jour, $prix, $idContrepartie, $userId) {
            $immo->date_sortie = $jour->toDateString();   // le cumul s'arrête à la sortie
            $base = round((float) $immo->valeur_brute, 2);
            $cumul = $this->cumulAu($immo, $jour);
            $vnc = round($base - $cumul, 2);
            $resultat = round($prix - $vnc, 2);

            $journal = ComptesSysteme::journalOperationsDiverses();
            $reference = "SORTIE-IMMO-{$immo->id}";
            $piece = MouvementEcriture::create([
                'Date_mouvement' => $jour->toDateString(),
                'Id_Journal' => $journal->Id_Journal,
                'Libelle' => ($prix > 0 ? 'Cession' : 'Mise au rebut') . " — {$immo->libelle}",
                'Reference' => $reference,
                'type_piece' => MouvementEcriture::TYPE_CESSION,
                'created_by' => $userId,
            ]);
            $ligne = function (int $idSousCompte, float $debit, float $credit, string $libelle) use ($piece, $journal, $reference) {
                LigneEcriture::create([
                    'Libelle' => $libelle, 'Id_Sous_compte' => $idSousCompte, 'Debit' => $debit, 'Credit' => $credit,
                    'Id_Mouvement_ecriture' => $piece->Id_Mouvement_ecriture, 'Id_Journal' => $journal->Id_Journal,
                    'Reference' => $reference, 'statut' => EcritureGuard::STATUT_BROUILLON,
                ]);
            };

            if ($cumul > 0) {
                $ligne(self::compteAmortissement($immo)->Id_Sous_compte, $cumul, 0, "Amortissements cumulés — {$immo->libelle}");
            }
            $ligne($immo->Id_Sous_compte, 0, $base, "Sortie de l'actif — {$immo->libelle}");
            if ($prix > 0) {
                $ligne($idContrepartie, $prix, 0, "Prix de cession — {$immo->libelle}");
            }
            if ($resultat > 0) {
                $ligne(ComptesSysteme::sousCompte('752000', "Plus-values sur sorties d'actifs immobilisés")->Id_Sous_compte, 0, $resultat, "Plus-value de cession — {$immo->libelle}");
            } elseif ($resultat < 0) {
                $ligne(ComptesSysteme::sousCompte('652000', "Moins-values sur sorties d'actifs immobilisés")->Id_Sous_compte, -$resultat, 0, "Valeur nette sortie — {$immo->libelle}");
            }

            $immo->prix_cession = $prix;
            $immo->id_piece_cession = $piece->Id_Mouvement_ecriture;
            $immo->save();

            Audit::enregistrer(Audit::IMMO_SORTIE, 'immobilisation', $immo->id, $immo->libelle, null, [
                'date_sortie' => $immo->date_sortie, 'prix_cession' => $prix, 'valeur_brute' => $base,
                'amortissements' => $cumul, 'vnc' => $vnc, 'piece' => $piece->Id_Mouvement_ecriture,
            ]);
            return $piece;
        });
    }

    /** Annule une sortie tant que sa pièce n'est pas validée. */
    public function annulerSortie(Amortissement $immo): void
    {
        if (!$immo->date_sortie) {
            throw ValidationException::withMessages(['date_sortie' => ['Cette immobilisation n\'est pas sortie de l\'actif.']]);
        }
        $piece = $immo->id_piece_cession ? MouvementEcriture::with('lignes')->find($immo->id_piece_cession) : null;
        if ($piece && $this->estValidee($piece)) {
            throw ValidationException::withMessages(['date_sortie' => ["La pièce de sortie {$piece->numeroAffiche()} est validée : contre-passez-la d'abord."]]);
        }
        DB::transaction(function () use ($immo, $piece) {
            if ($piece) {
                Audit::piece(Audit::PIECE_SUPPRESSION, $piece, Audit::etatPiece($piece), ['motif' => 'Annulation de la sortie d\'immobilisation']);
                $piece->lignes()->delete();
                $piece->delete();
            }
            $avant = ['date_sortie' => (string) $immo->date_sortie, 'prix_cession' => $immo->prix_cession];
            $immo->update(['date_sortie' => null, 'prix_cession' => null, 'id_piece_cession' => null]);
            Audit::enregistrer(Audit::IMMO_ANNULATION_SORTIE, 'immobilisation', $immo->id, $immo->libelle, $avant, null);
        });
    }

    /**
     * Une immobilisation est verrouillée dès que des amortissements validés
     * existent sur son compte 28 depuis sa mise en service, ou qu'elle est sortie :
     * changer sa base réécrirait le passé.
     */
    public function estVerrouillee(Amortissement $immo): bool
    {
        if ($immo->date_sortie) {
            return true;
        }
        if (!$immo->sousCompte || !self::estAmortissable($immo->sousCompte->Code_sous_compte)) {
            return false;
        }
        $idAmort = self::compteAmortissement($immo)->Id_Sous_compte;
        return DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->where('l.Id_Sous_compte', $idAmort)
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->where('l.Credit', '>', 0)
            ->where('m.Date_mouvement', '>=', $this->depart($immo)->toDateString())
            ->where(fn ($q) => $q->whereNull('m.type_piece')->orWhereNotIn('m.type_piece', [MouvementEcriture::TYPE_A_NOUVEAU, MouvementEcriture::TYPE_CLOTURE]))
            ->exists();
    }

    // ------------------------------------------------------------------

    private function depart(Amortissement $immo): Carbon
    {
        return Carbon::parse($immo->date_debut_utilisation ?? $immo->date_acquisition)->startOfDay();
    }

    private function exercices(): Collection
    {
        return $this->exercices ??= ExerciceComptable::orderBy('Date_debut')->get();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function bornes(ExerciceComptable $e): array
    {
        return [Carbon::parse($e->Date_debut)->startOfDay(), Carbon::parse($e->Date_fin)->startOfDay()];
    }

    private function jours(Carbon $a, Carbon $b): int
    {
        return (int) round(abs($a->diffInDays($b))) + 1;
    }
}
