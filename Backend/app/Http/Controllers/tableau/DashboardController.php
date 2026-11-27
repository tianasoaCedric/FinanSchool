<?php

namespace App\Http\Controllers\tableau;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\Saisie\MouvementEcriture;
use App\Services\Comptabilite\EcritureGuard;
use App\Services\Comptabilite\EtatsFinanciers;
use Carbon\Carbon;

class DashboardController extends Controller
{
    // 1. Évolution du Chiffre d'Affaires (CA) par mois avec soldes progressifs
     public function evolutionCA(Request $request)
    {
        $dateDebut = $request->input('date_debut', date('Y-01-01'));
        $dateFin = $request->input('date_fin', date('Y-12-31'));

        $interval = DB::table('intervalle_comptes_categorie as icc')
            ->join('categorie_fonctionelles as cf', 'icc.id_categorie_fonctionelle', '=', 'cf.id_categorie_fonctionelle')
            ->where('cf.code', 'CA')
            ->select('icc.compte_debut', 'icc.compte_fin')
            ->first();

        if (!$interval) return response()->json([]);

        $result = DB::table('vue_grand_livre as vgl')
            ->whereBetween('vgl.code_compte', [$interval->compte_debut, $interval->compte_fin])
            ->whereBetween('vgl.date_mouvement', [$dateDebut, $dateFin])
            ->select(
                DB::raw('EXTRACT(MONTH FROM vgl."date_mouvement") as mois'),
                DB::raw('ABS(SUM(vgl."Debit" - vgl."Credit")) as montant')
            )
            ->groupBy(DB::raw('EXTRACT(MONTH FROM vgl."date_mouvement")'))
            ->orderBy('mois')
            ->get();

        return response()->json($result);
    }

    // 2. Évolution de la Trésorerie par mois avec filtre date
    public function evolutionTresorerie(Request $request)
    {
        $dateDebut = $request->input('date_debut', date('Y-01-01'));
        $dateFin = $request->input('date_fin', date('Y-12-31'));

        $interval = DB::table('intervalle_comptes_categorie as icc')
            ->join('categorie_fonctionelles as cf', 'icc.id_categorie_fonctionelle', '=', 'cf.id_categorie_fonctionelle')
            ->where('cf.code', 'TRESO')
            ->select('icc.compte_debut', 'icc.compte_fin')
            ->first();

        if (!$interval) return response()->json([]);

        $result = DB::table('vue_grand_livre as vgl')
            ->whereBetween('vgl.code_compte', [$interval->compte_debut, $interval->compte_fin])
            ->whereBetween('vgl.date_mouvement', [$dateDebut, $dateFin])
            ->select(
                DB::raw('EXTRACT(MONTH FROM vgl."date_mouvement") as mois'),
                DB::raw('ABS(SUM(vgl."Debit" - vgl."Credit")) as montant')
            )
            ->groupBy(DB::raw('EXTRACT(MONTH FROM vgl."date_mouvement")'))
            ->orderBy('mois')
            ->get();

        return response()->json($result);
    }

    // 3. Composition du Bilan (fin = date_fin)
    public function compositionBilan(Request $request)
    {
        $dateFin = $request->input('date_fin', date('Y-12-31'));
        $postes = [
            'IMMOINC', 'IMMOCO', 'STOCKS', 'CLIENTS', 'TRESO',
            'CAPITAL', 'PRIME', 'DETTECT', 'FOURN', 'AUTDETTE'
        ];

        $result = [];
        foreach ($postes as $poste) {
            $interval = DB::table('intervalle_comptes_categorie as icc')
                ->join('categorie_fonctionelles as cf', 'icc.id_categorie_fonctionelle', '=', 'cf.id_categorie_fonctionelle')
                ->where('cf.code', $poste)
                ->select('icc.compte_debut', 'icc.compte_fin')
                ->first();

            if ($interval) {
                $amount = DB::table('vue_grand_livre as vgl')
                    ->whereBetween('vgl.code_compte', [$interval->compte_debut, $interval->compte_fin])
                    ->where('vgl.date_mouvement', '<=', $dateFin)
                    ->sum(DB::raw('vgl."Debit" - vgl."Credit"'));

                $result[] = ['poste' => $poste, 'montant' => $amount];
            }
        }

        return response()->json($result);
    }

    // 4. Décomposition du résultat (fin = date_fin)
    public function decompositionResultat(Request $request)
    {
        $dateFin = $request->input('date_fin', date('Y-12-31'));
        $etapes = [
            'CA', 'ACHATCONSOM', 'SERVEXT', 'CHPERS', 'IMPTAX',
            'AUTCHOP', 'AMORTPROV', 'REPRISEPROV', 'PRODFIN', 'CHARGEFIN',
            'PRODEXCEPT', 'CHAREXCEPT'
        ];

        $result = [];
        foreach ($etapes as $cat) {
            $interval = DB::table('intervalle_comptes_categorie as icc')
                ->join('categorie_fonctionelles as cf', 'icc.id_categorie_fonctionelle', '=', 'cf.id_categorie_fonctionelle')
                ->where('cf.code', $cat)
                ->select('icc.compte_debut', 'icc.compte_fin')
                ->first();

            if ($interval) {
                $amount = DB::table('vue_grand_livre as vgl')
                    ->whereBetween('vgl.code_compte', [$interval->compte_debut, $interval->compte_fin])
                    ->where('vgl.date_mouvement', '<=', $dateFin)
                    ->sum(DB::raw('ABS(vgl."Debit" - vgl."Credit")'));
                $result[] = ['etape' => $cat, 'montant' => $amount];
            }
        }

        return response()->json($result);
    }

public function resumeDashboard(Request $request)
{
    $dateDebut = $request->input('date_debut', date('Y-01-01'));
    $dateFin = $request->input('date_fin', date('Y-12-31'));

    // Solde CA actuel (valeur absolue)
    $intervalCA = DB::table('intervalle_comptes_categorie as icc')
        ->join('categorie_fonctionelles as cf', 'icc.id_categorie_fonctionelle', '=', 'cf.id_categorie_fonctionelle')
        ->where('cf.code', 'CA')
        ->select('icc.compte_debut', 'icc.compte_fin')
        ->first();

    $soldeCA = 0;
    if ($intervalCA) {
        $soldeCA = DB::table('vue_grand_livre as vgl')
            ->whereBetween('vgl.code_compte', [$intervalCA->compte_debut, $intervalCA->compte_fin])
            ->whereBetween('vgl.date_mouvement', [$dateDebut, $dateFin])
            ->sum(DB::raw('vgl."Debit" - vgl."Credit"'));
        $soldeCA = abs($soldeCA);
    }

    // Solde Charges (valeur absolue de la somme des charges)
    $codesCharges = [
        'ACHATCONSOM', 'SERVEXT', 'CHPERS', 'IMPTAX', 
        'AUTCHOP', 'AMORTPROV', 'CHARGEFIN', 'CHAREXCEPT'
    ];
    $soldeCharges = 0;
    foreach ($codesCharges as $cat) {
        $interval = DB::table('intervalle_comptes_categorie as icc')
            ->join('categorie_fonctionelles as cf', 'icc.id_categorie_fonctionelle', '=', 'cf.id_categorie_fonctionelle')
            ->where('cf.code', $cat)
            ->select('icc.compte_debut', 'icc.compte_fin')
            ->first();
        if ($interval) {
            $valCharge = DB::table('vue_grand_livre as vgl')
                ->whereBetween('vgl.code_compte', [$interval->compte_debut, $interval->compte_fin])
                ->whereBetween('vgl.date_mouvement', [$dateDebut, $dateFin])
                ->sum(DB::raw('vgl."Debit" - vgl."Credit"'));
            $soldeCharges += abs($valCharge);
        }
    }

    // Nombre d'écritures non validées
    $nbecritsNonVal = DB::table('ligne_ecritures as le')
        ->join('mouvement_ecritures as me', 'le.Id_Mouvement_ecriture', '=', 'me.Id_Mouvement_ecriture')
        ->whereBetween('me.Date_mouvement', [$dateDebut, $dateFin])
        ->where('le.statut', 'brouillon')
        ->count();

    return response()->json([
        'solde_ca' => $soldeCA,
        'solde_charges' => $soldeCharges,
        'nb_ecritures_non_validees' => $nbecritsNonVal
    ]);
}


    /**
     * Synthèse complète du tableau de bord pour une période (en général un exercice).
     *
     * Toutes les valeurs viennent du moteur EtatsFinanciers (écritures validées,
     * montants signés, pièce de clôture exclue, à-nouveaux gérés) : les chiffres
     * sont donc identiques à ceux des états financiers.
     * La période N-1 est la même période décalée d'un an.
     */
    public function synthese(Request $request)
    {
        $request->validate([
            'date_debut' => 'required|date',
            'date_fin' => 'required|date|after_or_equal:date_debut',
        ]);
        $debut = EtatsFinanciers::date($request->input('date_debut'));
        $fin = EtatsFinanciers::date($request->input('date_fin'));
        $debutN1 = Carbon::parse($debut)->subYear()->toDateString();
        $finN1 = Carbon::parse($fin)->subYear()->toDateString();

        $ef = new EtatsFinanciers();
        $p = $ef->postesResultat($debut, $fin);
        $pN1 = $ef->postesResultat($debutN1, $finN1);
        $bilan = $ef->bilan($debut, $fin);
        $cum = $ef->soldesCumules($fin);
        $cumN1 = $ef->soldesCumules($finN1);

        // ---- Indicateurs clés ----
        $tresoNette = fn (array $s) => round(EtatsFinanciers::somme($s, ['5'], ['59']), 2);
        $kpis = [
            'ca' => ['n' => $p['ca'], 'n1' => $pN1['ca']],
            'resultat_net' => ['n' => $p['resultat_net'], 'n1' => $pN1['resultat_net']],
            'ebe' => ['n' => $p['ebe'], 'n1' => $pN1['ebe']],
            'tresorerie' => ['n' => $tresoNette($cum), 'n1' => $tresoNette($cumN1)],
            'total_produits' => $p['total_produits_ao'] + $p['prod_extra'],
            'total_charges' => $p['total_charges_ao'] + $p['charges_extra'],
        ];

        // ---- Séries mensuelles (produits, charges, CA, flux de trésorerie) ----
        $mensuel = $this->seriesMensuelles($debut, $fin);
        $mensuelN1 = $this->seriesMensuelles($debutN1, $finN1);

        // Solde de trésorerie de fin de mois : solde d'ouverture + flux cumulés.
        // Si l'exercice a été ouvert par des à-nouveaux, ceux-ci sont dans les flux
        // du premier mois : l'ouverture vaut alors 0.
        $fenetre = $ef->fenetre($fin);
        $veille = Carbon::parse($debut)->subDay()->toDateString();
        $ouverture = ($fenetre === null || $fenetre < $debut)
            ? $tresoNette($ef->soldes($fenetre, $veille))
            : 0.0;
        $solde = $ouverture;
        $mois = [];
        foreach ($mensuel as $cle => $m) {
            $solde = round($solde + $m['flux_treso'], 2);
            $mN1 = $mensuelN1[Carbon::parse($cle . '-01')->subYear()->format('Y-m')] ?? null;
            $mois[] = [
                'mois' => $cle,
                'produits' => $m['produits'],
                'charges' => $m['charges'],
                'resultat' => round($m['produits'] - $m['charges'], 2),
                'ca' => $m['ca'],
                'ca_n1' => $mN1['ca'] ?? null,
                'tresorerie' => $solde,
            ];
        }

        // ---- Soldes intermédiaires de gestion ----
        $sig = [
            ['code' => 'production', 'label' => "Production de l'exercice", 'montant' => $p['production']],
            ['code' => 'consommation', 'label' => "Consommation de l'exercice", 'montant' => -$p['consommation']],
            ['code' => 'valeur_ajoutee', 'label' => 'Valeur ajoutée', 'montant' => $p['valeur_ajoutee'], 'total' => true],
            ['code' => 'personnel_impots', 'label' => 'Personnel, impôts et subventions', 'montant' => round($p['ebe'] - $p['valeur_ajoutee'], 2)],
            ['code' => 'ebe', 'label' => "Excédent brut d'exploitation", 'montant' => $p['ebe'], 'total' => true],
            ['code' => 'autres_op', 'label' => 'Dotations, reprises et autres', 'montant' => round($p['resultat_operationnel'] - $p['ebe'], 2)],
            ['code' => 'resultat_operationnel', 'label' => 'Résultat opérationnel', 'montant' => $p['resultat_operationnel'], 'total' => true],
            ['code' => 'financier_impots', 'label' => 'Financier, extraordinaire et impôts', 'montant' => round($p['resultat_net'] - $p['resultat_operationnel'], 2)],
            ['code' => 'resultat_net', 'label' => 'Résultat net', 'montant' => $p['resultat_net'], 'total' => true],
        ];

        // ---- Charges par nature (classe 6) ----
        $charges = [
            ['label' => 'Achats consommés', 'montant' => $p['achats']],
            ['label' => 'Services extérieurs', 'montant' => $p['services']],
            ['label' => 'Charges de personnel', 'montant' => $p['personnel']],
            ['label' => 'Impôts et taxes', 'montant' => $p['impots_taxes']],
            ['label' => 'Dotations aux amortissements', 'montant' => $p['dotations']],
            ['label' => 'Autres charges', 'montant' => round($p['autres_charges'] + $p['charges_fin'] + $p['charges_extra'] + $p['impots_exigibles'] + $p['impots_differes'], 2)],
        ];

        // ---- Bilan synthétique (valeurs nettes) ----
        $net = fn (array $postes) => round(array_sum(array_map(
            fn ($k) => $bilan['actif'][$k]['brut'] - $bilan['actif'][$k]['amort'], $postes)), 2);
        $pas = fn (array $postes) => round(array_sum(array_map(fn ($k) => $bilan['passif'][$k], $postes)), 2);
        $actif = [
            'immobilisations' => $net(['goodwill', 'incorporelles', 'corporelles', 'concession', 'encours', 'financieres', 'impots_diff_actif']),
            'stocks' => $net(['stocks']),
            'creances' => $net(['clients', 'impots_actif', 'autres_creances']),
            'tresorerie' => $net(['tresorerie']),
        ];
        $passif = [
            'capitaux_propres' => $pas(['capital', 'primes_reserves', 'ecarts_reeval', 'ecart_equiv', 'resultat', 'report', 'subv_invest']),
            'dettes_financieres' => $pas(['impots_diff_passif', 'emprunts', 'provisions', 'autres_pnc', 'tresorerie_passif']),
            'fournisseurs' => $pas(['fournisseurs']),
            'autres_dettes' => $pas(['impots_passif', 'autres_dettes']),
        ];

        // ---- Ratios ----
        $totalActif = array_sum($actif);
        $actifCourant = $actif['stocks'] + $actif['creances'] + $actif['tresorerie'];
        $passifCourant = $passif['fournisseurs'] + $passif['autres_dettes'] + $bilan['passif']['tresorerie_passif'];
        $jours = max(1, Carbon::parse($debut)->diffInDays(Carbon::parse($fin)) + 1);
        $div = fn ($a, $b) => abs($b) < 0.01 ? null : round($a / $b, 4);
        $ttc = 1 + (float) \App\Services\Parametres::get('compta.taux_tva') / 100;
        $ratios = [
            'liquidite_generale' => $div($actifCourant, $passifCourant),
            'autonomie_financiere' => $div($passif['capitaux_propres'], $totalActif),
            'marge_nette' => $div($p['resultat_net'], $p['ca']),
            'bfr' => round($actif['stocks'] + $actif['creances'] - $passif['fournisseurs'] - $passif['autres_dettes'], 2),
            'delai_clients' => ($r = $div($net(['clients']), $p['ca'] * $ttc)) === null ? null : round($r * $jours),
            'delai_fournisseurs' => ($r = $div($passif['fournisseurs'], ($p['achats'] + $p['services']) * $ttc)) === null ? null : round($r * $jours),
        ];

        // ---- Top 5 des comptes de charges ----
        $libelles = DB::table('sous_comptes')->pluck('Libelle', 'Code_sous_compte');
        $topCharges = collect($ef->soldes($debut, $fin))
            ->filter(fn ($s, $code) => str_starts_with((string) $code, '6') && $s > 0)
            ->sortDesc()->take(5)
            ->map(fn ($s, $code) => ['code' => (string) $code, 'libelle' => $libelles[$code] ?? '', 'montant' => round($s, 2)])
            ->values();

        // ---- État de la saisie sur la période ----
        $pieces = DB::table('mouvement_ecritures as m')
            ->whereBetween('m.Date_mouvement', [$debut, $fin])
            ->selectRaw(<<<'SQL'
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE EXISTS (
                    SELECT 1 FROM ligne_ecritures l
                    WHERE l."Id_Mouvement_ecriture" = m."Id_Mouvement_ecriture" AND l.statut = ?
                )) AS brouillons
            SQL, [EcritureGuard::STATUT_BROUILLON])
            ->first();
        $lignesBrouillon = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->whereBetween('m.Date_mouvement', [$debut, $fin])
            ->where('l.statut', EcritureGuard::STATUT_BROUILLON)
            ->count();

        return response()->json(EtatsFinanciers::nettoyer([
            'periode' => ['debut' => $debut, 'fin' => $fin, 'debut_n1' => $debutN1, 'fin_n1' => $finN1],
            'kpis' => $kpis,
            'mois' => $mois,
            'sig' => $sig,
            'charges' => $charges,
            'bilan' => ['actif' => $actif, 'passif' => $passif],
            'ratios' => $ratios,
            'top_charges' => $topCharges,
            'saisie' => [
                'pieces' => (int) ($pieces->total ?? 0),
                'pieces_brouillon' => (int) ($pieces->brouillons ?? 0),
                'lignes_brouillon' => $lignesBrouillon,
            ],
        ]));
    }

    /**
     * Mouvements validés par mois (AAAA-MM) sur la période, mois vides inclus :
     * produits (7), charges (6), chiffre d'affaires (70), flux de trésorerie (5 hors 59).
     */
    private function seriesMensuelles(string $debut, string $fin): array
    {
        $lignes = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->where('m.type_piece', '!=', MouvementEcriture::TYPE_CLOTURE)
            ->whereBetween('m.Date_mouvement', [$debut, $fin])
            ->groupByRaw('1, 2')
            ->selectRaw(<<<'SQL'
                to_char(m."Date_mouvement", 'YYYY-MM') AS mois,
                LEFT(s."Code_sous_compte", 2) AS prefixe,
                SUM(l."Debit" - l."Credit") AS solde
            SQL)
            ->get();

        $res = [];
        for ($d = Carbon::parse($debut)->startOfMonth(); $d <= Carbon::parse($fin); $d->addMonth()) {
            $res[$d->format('Y-m')] = ['produits' => 0.0, 'charges' => 0.0, 'ca' => 0.0, 'flux_treso' => 0.0];
        }
        foreach ($lignes as $r) {
            if (!isset($res[$r->mois])) continue;
            $s = (float) $r->solde;
            $px = (string) $r->prefixe;
            match (true) {
                str_starts_with($px, '7') => $res[$r->mois]['produits'] -= $s,
                str_starts_with($px, '6') => $res[$r->mois]['charges'] += $s,
                default => null,
            };
            if ($px === '70') $res[$r->mois]['ca'] -= $s;
            if (str_starts_with($px, '5') && $px !== '59') $res[$r->mois]['flux_treso'] += $s;
        }
        return array_map(fn ($m) => array_map(fn ($v) => round($v, 2), $m), $res);
    }

    /**
     * Helper: Obtenir le nom du mois en français
     */
    private function getNomMois($numero)
    {
        $mois = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
        ];
        return $mois[$numero] ?? '';
    }
}
