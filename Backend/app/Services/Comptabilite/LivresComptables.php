<?php

namespace App\Services\Comptabilite;

use App\Models\exercice\ExerciceComptable;
use App\Models\Saisie\MouvementEcriture;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Livres comptables (PCG 2005) : balance générale et grand livre.
 *
 *  - seules les écritures VALIDÉES y figurent (le brouillard n'est pas un livre) ;
 *  - l'écriture de clôture (6/7 → 12) en est exclue : les livres de N restent
 *    ceux « avant clôture » et les comptes de gestion conservent leurs soldes ;
 *  - chaque compte s'ouvre sur un solde d'ouverture : à-nouveaux de
 *    l'exercice + écritures antérieures à la période dans l'exercice ;
 *  - les charges et produits d'exercices jamais clôturés par écritures sont
 *    regroupés sur une ligne « résultats antérieurs non clôturés », pour que
 *    la balance reste équilibrée sans les mêler aux comptes de l'exercice.
 *
 * La période doit tenir dans un seul exercice.
 */
class LivresComptables
{
    public const CODE_RESULTATS_ANTERIEURS = '11—';

    public function __construct(private EtatsFinanciers $etats = new EtatsFinanciers()) {}

    /**
     * Balance : une ligne par sous-compte. Montants signés (débit − crédit).
     */
    public function balance(string $debut, string $fin, ?string $classe = null): array
    {
        [$debut, $fin] = $this->bornes($debut, $fin);
        $lignes = $this->lignes($debut, $fin, $classe, details: false);

        $comptes = [];
        $anterieurs = 0.0;
        foreach ($lignes as $l) {
            if ($l->bucket === 'anterieur') {
                $anterieurs += (float) $l->solde;
                continue;
            }
            $cle = $l->code_sous_compte;
            $comptes[$cle] ??= [
                'code_compte' => $l->code_compte, 'libelle_compte' => $l->libelle_compte,
                'code_sous_compte' => $l->code_sous_compte, 'libelle_sous_compte' => $l->libelle_sous_compte,
                'ouverture' => 0.0, 'total_debit' => 0.0, 'total_credit' => 0.0,
            ];
            if ($l->bucket === 'ouverture') {
                $comptes[$cle]['ouverture'] += (float) $l->solde;
            } else {
                $comptes[$cle]['total_debit'] += (float) $l->debit;
                $comptes[$cle]['total_credit'] += (float) $l->credit;
            }
        }
        if (abs($anterieurs) >= 0.005 && (!$classe || $classe === '1')) {
            $comptes[self::CODE_RESULTATS_ANTERIEURS] = [
                'code_compte' => '11', 'libelle_compte' => 'Report à nouveau',
                'code_sous_compte' => self::CODE_RESULTATS_ANTERIEURS,
                'libelle_sous_compte' => 'Résultats antérieurs non clôturés (classes 6 et 7)',
                'ouverture' => $anterieurs, 'total_debit' => 0.0, 'total_credit' => 0.0,
            ];
        }

        $res = [];
        foreach ($comptes as $c) {
            $c['solde_final'] = round($c['ouverture'] + $c['total_debit'] - $c['total_credit'], 2);
            $c = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $c);
            if (abs($c['ouverture']) < 0.005 && $c['total_debit'] == 0 && $c['total_credit'] == 0) {
                continue;
            }
            $res[] = $c;
        }
        usort($res, fn ($a, $b) => strcmp($a['code_sous_compte'], $b['code_sous_compte']));
        return EtatsFinanciers::nettoyer($res);
    }

    /**
     * Grand livre : pour chaque sous-compte, une ligne « Report à nouveau »
     * (solde d'ouverture) puis les écritures de la période, avec solde progressif.
     * Format compatible avec l'ancienne vue vue_grand_livre.
     */
    public function grandLivre(string $debut, string $fin, ?string $codeCompte = null): array
    {
        [$debut, $fin] = $this->bornes($debut, $fin);
        $ouvertures = [];
        foreach ($this->lignes($debut, $fin, null, details: false, codeCompte: $codeCompte) as $l) {
            if ($l->bucket === 'ouverture') {
                $ouvertures[$l->code_sous_compte] = ['ligne' => $l, 'solde' => ($ouvertures[$l->code_sous_compte]['solde'] ?? 0) + (float) $l->solde];
            }
        }
        $mouvements = $this->lignes($debut, $fin, null, details: true, codeCompte: $codeCompte);

        $parCompte = [];
        foreach ($ouvertures as $code => $o) {
            if (abs($o['solde']) < 0.005) {
                continue;
            }
            $s = round($o['solde'], 2);
            $parCompte[$code][] = $this->ligneGL($o['ligne'], $debut, 'REPORT', 'Report à nouveau', $s > 0 ? $s : 0, $s < 0 ? -$s : 0);
        }
        foreach ($mouvements as $m) {
            $parCompte[$m->code_sous_compte][] = $this->ligneGL($m, $m->date_mouvement, $m->numero_piece, $m->libelle_ecriture, (float) $m->debit, (float) $m->credit);
        }
        ksort($parCompte, SORT_STRING);

        $res = [];
        foreach ($parCompte as $lignes) {
            $solde = 0.0;
            $totalD = array_sum(array_column($lignes, 'Debit'));
            $totalC = array_sum(array_column($lignes, 'Credit'));
            foreach ($lignes as $l) {
                $solde += $l['Debit'] - $l['Credit'];
                $l['solde_progressif'] = round($solde, 2);
                $l['solde_final_sous_compte'] = round($totalD - $totalC, 2);
                $res[] = $l;
            }
        }
        return EtatsFinanciers::nettoyer($res);
    }

    // ------------------------------------------------------------------

    /** Bornes : la période est ramenée à l'exercice qui contient le début. */
    private function bornes(string $debut, string $fin): array
    {
        $debut = Carbon::parse($debut)->toDateString();
        $fin = Carbon::parse($fin)->toDateString();
        $ex = ExerciceComptable::getExerciceByDate($debut);
        if ($ex && $fin > ($f = Carbon::parse($ex->Date_fin)->toDateString())) {
            $fin = $f;
        }
        return [$debut, $fin];
    }

    /**
     * Lignes validées classées en : ouverture (avant la période, ou à-nouveaux),
     * mouvement (dans la période), anterieur (6/7 d'exercices non clôturés).
     * details=false : agrégées par sous-compte et classe ; true : ligne à ligne
     * (mouvements de la période uniquement).
     */
    private function lignes(string $debut, string $fin, ?string $classe, bool $details, ?string $codeCompte = null)
    {
        $ex = ExerciceComptable::getExerciceByDate($debut);
        $debutExercice = $ex ? Carbon::parse($ex->Date_debut)->toDateString() : $debut;
        $fenetre = $this->etats->fenetre($debut); // début du dernier exercice ouvert par des à-nouveaux

        $q = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->join('comptes as c', 'c.Id_Compte', '=', 's.Id_Compte')
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->where('m.type_piece', '!=', MouvementEcriture::TYPE_CLOTURE)
            ->where('m.Date_mouvement', '<=', $fin)
            ->when($fenetre, fn ($q) => $q->where('m.Date_mouvement', '>=', $fenetre))
            ->when($classe, fn ($q) => $q->where('s.Code_sous_compte', 'like', $classe . '%'))
            ->when($codeCompte, fn ($q) => $q->where('c.Code_compte', $codeCompte));

        $an = MouvementEcriture::TYPE_A_NOUVEAU;
        if ($details) {
            return $q->where('m.Date_mouvement', '>=', $debut)
                ->where('m.type_piece', '!=', $an)
                ->orderBy('s.Code_sous_compte')->orderBy('m.Date_mouvement')->orderBy('l.Id_Ligne_ecriture')
                ->select('c.Code_compte as code_compte', 'c.Libelle as libelle_compte',
                    's.Code_sous_compte as code_sous_compte', 's.Libelle as libelle_sous_compte',
                    'm.Date_mouvement as date_mouvement', 'm.Numero_piece as numero_piece',
                    'l.Libelle as libelle_ecriture', 'l.Debit as debit', 'l.Credit as credit')
                ->get();
        }

        // Classement de chaque ligne (bindings : début période, type AN, début exercice)
        $bucket = "CASE
            WHEN m.\"Date_mouvement\" >= ? AND m.type_piece <> ? THEN 'mouvement'
            WHEN substr(s.\"Code_sous_compte\", 1, 1) IN ('6', '7') AND m.\"Date_mouvement\" < ? THEN 'anterieur'
            ELSE 'ouverture' END";
        return $q->groupBy('c.Code_compte', 'c.Libelle', 's.Code_sous_compte', 's.Libelle', 'bucket')
            ->selectRaw('c."Code_compte" as code_compte, c."Libelle" as libelle_compte, s."Code_sous_compte" as code_sous_compte, s."Libelle" as libelle_sous_compte')
            ->selectRaw("{$bucket} as bucket", [$debut, $an, $debutExercice])
            ->selectRaw('SUM(l."Debit") as debit, SUM(l."Credit") as credit, SUM(l."Debit" - l."Credit") as solde')
            ->get();
    }

    private function ligneGL(object $l, string $date, string $piece, ?string $libelle, float $debit, float $credit): array
    {
        return [
            'code_compte' => $l->code_compte,
            'libelle_compte' => $l->libelle_compte,
            'code_sous_compte' => $l->code_sous_compte,
            'libelle_sous_compte' => $l->libelle_sous_compte,
            'date_mouvement' => substr($date, 0, 10),
            'numero_piece' => $piece,
            'libelle_ecriture' => $libelle,
            'Debit' => round($debit, 2),
            'Credit' => round($credit, 2),
        ];
    }
}
