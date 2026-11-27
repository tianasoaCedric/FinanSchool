<?php

namespace App\Services\Comptabilite;

use App\Models\Saisie\MouvementEcriture;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moteur unique des états financiers (PCG 2005 Madagascar).
 *
 * Principes :
 *  - seules les écritures VALIDÉES sont prises en compte ;
 *  - montants SIGNÉS (débit − crédit), jamais de valeur absolue ;
 *  - bilan = soldes CUMULÉS depuis l'origine jusqu'à la date de clôture ;
 *  - compte de résultat = mouvements de la période ;
 *  - chaque compte des classes 1 à 5 est classé dans un seul poste, et les
 *    classes 6/7 alimentent le résultat : Actif = Passif par construction ;
 *  - le résultat net est calculé à un seul endroit (resultatNet) et repris
 *    par le bilan, le tableau des flux et la variation des capitaux propres.
 *
 * Clôture (lot 4) : la pièce de clôture (type « cloture », 6/7 → 12) est
 * exclue de tous les soldes, de sorte que le compte de résultat et le bilan
 * de l'exercice clôturé restent ceux d'avant clôture. Dans N+1, le résultat
 * de N arrive au 12 par les à-nouveaux : le 12 est donc toujours présenté en
 * « résultats antérieurs non affectés », comme les résultats des exercices
 * jamais clôturés par écritures.
 */
class EtatsFinanciers
{
    private array $cache = [];

    // =====================================================================
    // Soldes
    // =====================================================================

    /**
     * Arrondit à 2 décimales et supprime les « -0 » (affichés « -0,00 »),
     * récursivement dans un tableau de lignes.
     */
    public static function nettoyer($v)
    {
        if (is_array($v)) {
            return array_map([self::class, 'nettoyer'], $v);
        }
        if (is_float($v)) {
            $v = round($v, 2);
            return $v == 0.0 ? 0.0 : $v;
        }
        return $v;
    }

    /** Normalise une date (accepte "2025-01-01T00:00:00.000000Z"). */
    public static function date($d): string
    {
        return Carbon::parse($d)->toDateString();
    }

    /**
     * Soldes signés (débit − crédit) par code de sous-compte, écritures validées,
     * entre $debut (inclus, null = origine) et $fin (inclus).
     */
    public function soldes(?string $debut, string $fin): array
    {
        $cle = ($debut ?? '-') . '|' . $fin;
        if (isset($this->cache[$cle])) {
            return $this->cache[$cle];
        }

        $q = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'l.Id_Sous_compte')
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->where('m.type_piece', '!=', MouvementEcriture::TYPE_CLOTURE)
            ->where('m.Date_mouvement', '<=', $fin)
            ->groupBy('s.Code_sous_compte')
            ->selectRaw('s."Code_sous_compte" as code, SUM(l."Debit" - l."Credit") as solde');
        if ($debut) {
            $q->where('m.Date_mouvement', '>=', $debut);
        }

        $soldes = [];
        foreach ($q->get() as $r) {
            $s = round((float) $r->solde, 2);
            if ($s != 0.0) {
                $soldes[(string) $r->code] = $s;
            }
        }
        return $this->cache[$cle] = $soldes;
    }

    /**
     * Début de la « fenêtre » des soldes cumulés à une date : début du dernier
     * exercice ouvert par des à-nouveaux (clôture). L'historique antérieur est
     * alors représenté par la pièce d'à-nouveaux. Null = depuis l'origine.
     */
    public function fenetre(string $date): ?string
    {
        $cle = "fenetre|{$date}";
        if (!array_key_exists($cle, $this->cache)) {
            $d = DB::table('exercice_comptable')
                ->where('ouvert_par_a_nouveaux', true)
                ->whereDate('Date_debut', '<=', $date)
                ->max('Date_debut');
            $this->cache[$cle] = $d ? Carbon::parse($d)->toDateString() : null;
        }
        return $this->cache[$cle];
    }

    /** Soldes cumulés (bilan) à une date, en tenant compte des à-nouveaux. */
    public function soldesCumules(string $date): array
    {
        return $this->soldes($this->fenetre($date), $date);
    }

    /** Somme des soldes dont le code commence par un des préfixes (hors exclusions). */
    public static function somme(array $soldes, array $prefixes, array $exclus = []): float
    {
        $t = 0.0;
        foreach ($soldes as $code => $s) {
            if (self::commencePar($code, $prefixes) && !self::commencePar($code, $exclus)) {
                $t += $s;
            }
        }
        return round($t, 2);
    }

    private static function commencePar(string $code, array $prefixes): bool
    {
        foreach ($prefixes as $p) {
            if (str_starts_with($code, (string) $p)) {
                return true;
            }
        }
        return false;
    }

    // =====================================================================
    // Compte de résultat
    // =====================================================================

    /** Résultat net de la période : produits (7) − charges (6). Source unique. */
    public function resultatNet(?string $debut, string $fin): float
    {
        return round(-self::somme($this->soldes($debut, $fin), ['6', '7']), 2);
    }

    /** Détail des postes du compte de résultat par nature (montants positifs = normaux). */
    public function postesResultat(string $debut, string $fin): array
    {
        $s = $this->soldes($debut, $fin);
        $produit = fn (array $p, array $x = []) => round(-self::somme($s, $p, $x), 2);
        $charge = fn (array $p, array $x = []) => round(self::somme($s, $p, $x), 2);

        $p = [
            'ca' => $produit(['70']),
            'prod_stockee' => $produit(['71']),
            'prod_immo' => $produit(['72']),
            'subventions' => $produit(['74']),
            // Tout autre produit opérationnel (75 et classes 7 hors plan : 73, 79…)
            'autres_produits' => $produit(['7'], ['70', '71', '72', '74', '76', '77', '78']),
            'reprises' => $produit(['78']),
            'prod_fin' => $produit(['76']),
            'prod_extra' => $produit(['77']),

            'achats' => $charge(['60']),
            'services' => $charge(['61', '62']),
            'impots_taxes' => $charge(['63']),
            'personnel' => $charge(['64']),
            'autres_charges' => $charge(['65']),
            'charges_fin' => $charge(['66']),
            'charges_extra' => $charge(['67']),
            'dotations' => $charge(['68']),
            'impots_differes' => $charge(['692', '693']),
            // Impôts sur le résultat + toute autre charge 69 (et classes 6 hors plan)
            'impots_exigibles' => $charge(['6'], ['60', '61', '62', '63', '64', '65', '66', '67', '68', '692', '693']),
        ];

        $p['production'] = round($p['ca'] + $p['prod_stockee'] + $p['prod_immo'], 2);
        $p['consommation'] = round($p['achats'] + $p['services'], 2);
        $p['valeur_ajoutee'] = round($p['production'] - $p['consommation'], 2);
        $p['ebe'] = round($p['valeur_ajoutee'] + $p['subventions'] - $p['personnel'] - $p['impots_taxes'], 2);
        $p['resultat_operationnel'] = round($p['ebe'] + $p['autres_produits'] - $p['autres_charges'] - $p['dotations'] + $p['reprises'], 2);
        $p['resultat_financier'] = round($p['prod_fin'] - $p['charges_fin'], 2);
        $p['resultat_avant_impots'] = round($p['resultat_operationnel'] + $p['resultat_financier'], 2);
        $p['total_produits_ao'] = round($p['production'] + $p['subventions'] + $p['autres_produits'] + $p['reprises'] + $p['prod_fin'], 2);
        $p['total_charges_ao'] = round($p['consommation'] + $p['personnel'] + $p['impots_taxes'] + $p['autres_charges'] + $p['dotations'] + $p['charges_fin'] + $p['impots_exigibles'] + $p['impots_differes'], 2);
        $p['resultat_ao'] = round($p['resultat_avant_impots'] - $p['impots_exigibles'] - $p['impots_differes'], 2);
        $p['resultat_extra'] = round($p['prod_extra'] - $p['charges_extra'], 2);
        $p['resultat_net'] = round($p['resultat_ao'] + $p['resultat_extra'], 2);

        return $p;
    }

    /** Lignes du compte de résultat par nature (PCG 2005). */
    public function compteResultatNature(string $debut, string $fin): array
    {
        $p = $this->postesResultat($debut, $fin);
        $l = fn ($label, $note, $montant, $flags = []) => ['label' => $label, 'note' => $note, 'montant' => $montant] + $flags;
        $st = ['isSubtotal' => true];

        return [
            $l('Chiffre d\'affaires', '70', $p['ca']),
            $l('Variation stocks produits finis et en cours', '71', $p['prod_stockee']),
            $l('Production immobilisée', '72', $p['prod_immo']),
            $l('I – Production de l\'exercice', '', $p['production'], $st),
            $l('Achats consommés', '60', $p['achats']),
            $l('Services extérieurs et autres consommations', '61, 62', $p['services']),
            $l('II – Consommation de l\'exercice', '', $p['consommation'], $st),
            $l('III – Valeur ajoutée d\'exploitation (I − II)', '', $p['valeur_ajoutee'], $st),
            $l('Subventions d\'exploitation', '74', $p['subventions']),
            $l('Charges de personnel', '64', $p['personnel']),
            $l('Impôts, taxes et versements assimilés', '63', $p['impots_taxes']),
            $l('IV – Excédent brut d\'exploitation', '', $p['ebe'], $st),
            $l('Autres produits opérationnels', '75', $p['autres_produits']),
            $l('Autres charges opérationnelles', '65', $p['autres_charges']),
            $l('Dotations aux amortissements, provisions et pertes de valeur', '68', $p['dotations']),
            $l('Reprises sur provisions et pertes de valeur', '78', $p['reprises']),
            $l('V – Résultat opérationnel', '', $p['resultat_operationnel'], $st),
            $l('Produits financiers', '76', $p['prod_fin']),
            $l('Charges financières', '66', $p['charges_fin']),
            $l('VI – Résultat financier', '', $p['resultat_financier'], $st),
            $l('VII – Résultat avant impôts (V + VI)', '', $p['resultat_avant_impots'], $st),
            $l('Impôts exigibles sur résultats', '695, 698', $p['impots_exigibles']),
            $l('Impôts différés (variations)', '692, 693', $p['impots_differes']),
            $l('Total des produits des activités ordinaires', '', $p['total_produits_ao'], $st),
            $l('Total des charges des activités ordinaires', '', $p['total_charges_ao'], $st),
            $l('VIII – Résultat net des activités ordinaires', '', $p['resultat_ao'], $st),
            $l('Éléments extraordinaires (produits)', '77', $p['prod_extra']),
            $l('Éléments extraordinaires (charges)', '67', $p['charges_extra']),
            $l('IX – Résultat extraordinaire', '', $p['resultat_extra'], $st),
            $l('X – Résultat net de l\'exercice', '', $p['resultat_net'], ['isTotal' => true]),
        ];
    }

    /**
     * Fonction d'une charge pour le CR par fonction :
     * 1) affectation explicite du sous-compte (catégories COUTVENTE, CHARGECOMM,
     *    CHARGEADM, AUTCHOP), sinon 2) règle par défaut selon le numéro de compte.
     */
    private function fonctionsCharges(): array
    {
        if (isset($this->cache['fonctions'])) {
            return $this->cache['fonctions'];
        }
        $rows = DB::table('compte_categories as cc')
            ->join('categorie_fonctionelles as cf', 'cf.id_categorie_fonctionelle', '=', 'cc.id_categorie_fonctionelle')
            ->join('sous_comptes as s', 's.Id_Sous_compte', '=', 'cc.id_sous_compte')
            ->where('cc.actif', true)
            ->whereIn('cf.code', ['COUTVENTE', 'CHARGECOMM', 'CHARGEADM', 'AUTCHOP'])
            ->pluck('cf.code', 's.Code_sous_compte');
        return $this->cache['fonctions'] = $rows->all();
    }

    public const FONCTIONS_PAR_DEFAUT = [
        // préfixe => fonction (le plus long préfixe l'emporte)
        '623' => 'CHARGECOMM',   // publicité, relations publiques
        '60' => 'COUTVENTE',     // achats consommés (fournitures pédagogiques…)
        '61' => 'COUTVENTE',     // locaux, entretien, assurances des activités
        '64' => 'COUTVENTE',     // personnel (enseignement majoritaire)
        '68' => 'COUTVENTE',     // dotations sur équipements
        '62' => 'CHARGEADM',     // honoraires, télécom, frais généraux
        '63' => 'CHARGEADM',     // impôts et taxes
        '65' => 'AUTCHOP',       // autres charges opérationnelles
    ];

    private function fonctionDe(string $code): string
    {
        $explicites = $this->fonctionsCharges();
        if (isset($explicites[$code])) {
            return $explicites[$code];
        }
        $meilleur = null;
        foreach (self::FONCTIONS_PAR_DEFAUT as $prefixe => $f) {
            if (str_starts_with($code, $prefixe) && ($meilleur === null || strlen($prefixe) > strlen($meilleur))) {
                $meilleur = $prefixe;
            }
        }
        return $meilleur ? self::FONCTIONS_PAR_DEFAUT[$meilleur] : 'AUTCHOP';
    }

    /** Lignes du compte de résultat par fonction (PCG 2005). */
    public function compteResultatFonction(string $debut, string $fin): array
    {
        $s = $this->soldes($debut, $fin);
        $p = $this->postesResultat($debut, $fin);

        $f = ['COUTVENTE' => 0.0, 'CHARGECOMM' => 0.0, 'CHARGEADM' => 0.0, 'AUTCHOP' => 0.0];
        foreach ($s as $code => $solde) {
            // Charges opérationnelles réparties par fonction : 60 à 65 et 68
            if (preg_match('/^6[0-58]/', $code)) {
                $f[$this->fonctionDe($code)] += $solde;
            }
        }
        $f = array_map(fn ($v) => round($v, 2), $f);

        $autresProduits = round($p['prod_stockee'] + $p['prod_immo'] + $p['subventions'] + $p['autres_produits'] + $p['reprises'], 2);
        $margeBrute = round($p['ca'] - $f['COUTVENTE'], 2);
        $resOp = round($margeBrute + $autresProduits - $f['CHARGECOMM'] - $f['CHARGEADM'] - $f['AUTCHOP'], 2);
        $rai = round($resOp + $p['resultat_financier'], 2);
        $rao = round($rai - $p['impots_exigibles'] - $p['impots_differes'], 2);
        $rn = round($rao + $p['resultat_extra'], 2);

        $l = fn ($label, $note, $montant, $total = false) => ['label' => $label, 'note' => $note, 'montant' => $montant, 'isTotal' => $total];
        return [
            $l('Chiffre d\'affaires', '70', $p['ca']),
            $l('Coût des ventes (coût des services d\'enseignement)', 'fonction', $f['COUTVENTE']),
            $l('MARGE BRUTE', '', $margeBrute, true),
            $l('Autres produits opérationnels', '71, 72, 74, 75, 78', $autresProduits),
            $l('Coûts commerciaux', 'fonction', $f['CHARGECOMM']),
            $l('Charges administratives', 'fonction', $f['CHARGEADM']),
            $l('Autres charges opérationnelles', 'fonction', $f['AUTCHOP']),
            $l('RÉSULTAT OPÉRATIONNEL', '', $resOp, true),
            $l('Produits financiers', '76', $p['prod_fin']),
            $l('Charges financières', '66', $p['charges_fin']),
            $l('RÉSULTAT AVANT IMPÔTS', '', $rai, true),
            $l('Impôts exigibles sur résultats', '695, 698', $p['impots_exigibles']),
            $l('Impôts différés (variations)', '692, 693', $p['impots_differes']),
            $l('RÉSULTAT NET DES ACTIVITÉS ORDINAIRES', '', $rao, true),
            $l('Charges extraordinaires', '67', $p['charges_extra']),
            $l('Produits extraordinaires', '77', $p['prod_extra']),
            $l('RÉSULTAT NET DE L\'EXERCICE', '', $rn, true),
        ];
    }

    // =====================================================================
    // Bilan
    // =====================================================================

    private const NATURE_IMMO = [
        '0' => 'incorporelles', '1' => 'corporelles', '2' => 'concession', '3' => 'encours',
        '4' => 'corporelles', '5' => 'corporelles', '6' => 'financieres', '7' => 'financieres',
    ];

    /**
     * Bilan à la date $fin (soldes cumulés). $debut = début de l'exercice,
     * pour isoler le résultat de l'exercice des résultats antérieurs.
     */
    public function bilan(string $debut, string $fin): array
    {
        $actif = [];   // poste => ['brut' => x, 'amort' => y]
        $passif = [];  // poste => montant
        $horsPlan = [];
        $ajA = function ($poste, $col, $v) use (&$actif) {
            $actif[$poste][$col] = ($actif[$poste][$col] ?? 0) + $v;
        };
        $ajP = function ($poste, $v) use (&$passif) {
            $passif[$poste] = ($passif[$poste] ?? 0) + $v;
        };

        $cumules = $this->soldesCumules($fin);
        foreach ($cumules as $code => $s) {
            $c = (string) $code;
            switch ($c[0]) {
                case '1':
                    if (str_starts_with($c, '133')) { $ajA('impots_diff_actif', 'brut', $s); break; }
                    $poste = match (true) {
                        self::commencePar($c, ['101', '108', '109']) => 'capital',
                        str_starts_with($c, '105') => 'ecarts_reeval',
                        str_starts_with($c, '107') => 'ecart_equiv',
                        str_starts_with($c, '10') => 'primes_reserves',
                        // 12 : résultat d'un exercice clôturé, en attente d'affectation
                        self::commencePar($c, ['11', '12']) => 'report',
                        str_starts_with($c, '134') => 'impots_diff_passif',
                        str_starts_with($c, '13') => 'subv_invest',
                        str_starts_with($c, '15') => 'provisions',
                        self::commencePar($c, ['16', '17']) => 'emprunts',
                        default => 'autres_pnc',
                    };
                    $ajP($poste, -$s);
                    break;

                case '2':
                    if (self::commencePar($c, ['28', '29'])) {
                        $nature = self::commencePar($c, ['2807', '2907']) ? 'goodwill' : (self::NATURE_IMMO[$c[2] ?? ''] ?? 'corporelles');
                        $ajA($nature, 'amort', -$s);
                    } else {
                        $nature = str_starts_with($c, '207') ? 'goodwill' : (self::NATURE_IMMO[$c[1] ?? ''] ?? 'corporelles');
                        $ajA($nature, 'brut', $s);
                    }
                    break;

                case '3':
                    str_starts_with($c, '39') ? $ajA('stocks', 'amort', -$s) : $ajA('stocks', 'brut', $s);
                    break;

                case '4':
                    if (str_starts_with($c, '49')) {
                        $ajA(str_starts_with($c, '491') ? 'clients' : 'autres_creances', 'amort', -$s);
                    } elseif ($s >= 0) {
                        $ajA(match (true) {
                            str_starts_with($c, '41') => 'clients',
                            str_starts_with($c, '44') => 'impots_actif',
                            default => 'autres_creances',
                        }, 'brut', $s);
                    } else {
                        $ajP(match (true) {
                            str_starts_with($c, '40') => 'fournisseurs',
                            str_starts_with($c, '44') => 'impots_passif',
                            default => 'autres_dettes',
                        }, -$s);
                    }
                    break;

                case '5':
                    if (str_starts_with($c, '59')) { $ajA('tresorerie', 'amort', -$s); }
                    elseif ($s >= 0) { $ajA('tresorerie', 'brut', $s); }
                    else { $ajP('tresorerie_passif', -$s); }
                    break;

                case '6':
                case '7':
                    break; // résultat, voir ci-dessous

                default:
                    $horsPlan[$c] = $s;
            }
        }

        // Résultat de l'exercice et résultats antérieurs non encore affectés
        // (classes 6/7 de la fenêtre antérieures au début de l'exercice)
        $resultatN = $this->resultatNet($debut, $fin);
        $resultatsAnterieurs = round(-self::somme($cumules, ['6', '7']) - $resultatN, 2);
        $ajP('resultat', $resultatN);
        $ajP('report', $resultatsAnterieurs);

        $a = fn ($poste) => [
            'brut' => round($actif[$poste]['brut'] ?? 0, 2),
            'amort' => round($actif[$poste]['amort'] ?? 0, 2),
        ];
        $p = fn ($poste) => round($passif[$poste] ?? 0, 2);

        return [
            'actif' => array_map($a, array_combine(
                $k = ['goodwill', 'incorporelles', 'corporelles', 'concession', 'encours', 'financieres', 'impots_diff_actif', 'stocks', 'clients', 'impots_actif', 'autres_creances', 'tresorerie'],
                $k
            )),
            'passif' => array_map($p, array_combine(
                $k = ['capital', 'primes_reserves', 'ecarts_reeval', 'ecart_equiv', 'resultat', 'report', 'subv_invest', 'impots_diff_passif', 'emprunts', 'provisions', 'autres_pnc', 'fournisseurs', 'impots_passif', 'autres_dettes', 'tresorerie_passif'],
                $k
            )),
            'resultat_exercice' => $resultatN,
            'resultats_anterieurs' => $resultatsAnterieurs,
            'hors_plan' => $horsPlan,
        ];
    }

    /** Lignes du bilan actif : label, note, brut, amort, net. */
    public function bilanActif(string $debut, string $fin): array
    {
        $b = $this->bilan($debut, $fin)['actif'];
        $ligne = function ($label, $note, $poste) use ($b) {
            $v = $b[$poste];
            return ['label' => $label, 'note' => $note, 'brut' => $v['brut'], 'amort' => $v['amort'], 'net' => round($v['brut'] - $v['amort'], 2)];
        };
        $total = function ($label, array $postes, $flags = ['isTotal' => true]) use ($b) {
            $brut = round(array_sum(array_map(fn ($p) => $b[$p]['brut'], $postes)), 2);
            $amort = round(array_sum(array_map(fn ($p) => $b[$p]['amort'], $postes)), 2);
            return ['label' => $label, 'note' => '', 'brut' => $brut, 'amort' => $amort, 'net' => round($brut - $amort, 2)] + $flags;
        };
        $titre = fn ($label) => ['label' => $label, 'note' => '', 'brut' => null, 'amort' => null, 'net' => null, 'isTitle' => true];
        $anc = ['goodwill', 'incorporelles', 'corporelles', 'concession', 'encours', 'financieres', 'impots_diff_actif'];
        $ac = ['stocks', 'clients', 'impots_actif', 'autres_creances', 'tresorerie'];

        return [
            $titre('ACTIFS NON COURANTS'),
            $ligne('Écart d\'acquisition (goodwill)', '207', 'goodwill'),
            $ligne('Immobilisations incorporelles', '20, 280, 290', 'incorporelles'),
            $ligne('Immobilisations corporelles', '21, 281, 291', 'corporelles'),
            $ligne('Immobilisations mises en concession', '22, 282, 292', 'concession'),
            $ligne('Immobilisations en cours', '23, 293', 'encours'),
            $ligne('Immobilisations financières', '26, 27, 296, 297', 'financieres'),
            $ligne('Impôts différés actif', '133', 'impots_diff_actif'),
            $total('TOTAL ACTIFS NON COURANTS', $anc),
            $titre('ACTIFS COURANTS'),
            $ligne('Stocks et en-cours', '3, 39', 'stocks'),
            ['label' => 'Créances et emplois assimilés', 'note' => '', 'brut' => null, 'amort' => null, 'net' => null, 'isSubtitle' => true],
            $ligne('Clients et autres débiteurs', '41, 491', 'clients'),
            $ligne('Impôts', '44 (débiteur)', 'impots_actif'),
            $ligne('Autres créances et actifs assimilés', '40, 42-48 (débiteurs), 49', 'autres_creances'),
            $ligne('Trésorerie et équivalents de trésorerie', '50-58 (débiteurs), 59', 'tresorerie'),
            $total('TOTAL ACTIFS COURANTS', $ac),
            $total('TOTAL GÉNÉRAL ACTIF', array_merge($anc, $ac)),
        ];
    }

    /** Lignes du bilan passif : label, note, montant. */
    public function bilanPassif(string $debut, string $fin): array
    {
        $p = $this->bilan($debut, $fin)['passif'];
        $ligne = fn ($label, $note, $poste) => ['label' => $label, 'note' => $note, 'montant' => $p[$poste]];
        $total = fn ($label, array $postes) => ['label' => $label, 'note' => '', 'montant' => round(array_sum(array_map(fn ($x) => $p[$x], $postes)), 2), 'isTotal' => true];
        $titre = fn ($label) => ['label' => $label, 'note' => '', 'montant' => null, 'isTitle' => true];
        $cp = ['capital', 'primes_reserves', 'ecarts_reeval', 'ecart_equiv', 'resultat', 'report'];
        $pnc = ['subv_invest', 'impots_diff_passif', 'emprunts', 'provisions', 'autres_pnc'];
        $pc = ['fournisseurs', 'impots_passif', 'autres_dettes', 'tresorerie_passif'];

        return [
            $titre('CAPITAUX PROPRES'),
            $ligne('Capital émis', '101, 108, 109', 'capital'),
            $ligne('Primes et réserves', '102-104, 106', 'primes_reserves'),
            $ligne('Écarts de réévaluation', '105', 'ecarts_reeval'),
            $ligne('Écart d\'équivalence', '107', 'ecart_equiv'),
            $ligne('Résultat net de l\'exercice', 'classes 6/7', 'resultat'),
            $ligne('Report à nouveau et résultats antérieurs non affectés', '11, 12', 'report'),
            $total('TOTAL CAPITAUX PROPRES', $cp),
            $titre('PASSIFS NON COURANTS'),
            $ligne('Produits différés : subventions d\'investissement', '13', 'subv_invest'),
            $ligne('Impôts différés passif', '134', 'impots_diff_passif'),
            $ligne('Emprunts et dettes financières', '16, 17', 'emprunts'),
            $ligne('Provisions et produits constatés d\'avance', '15', 'provisions'),
            $ligne('Autres passifs non courants', '14, 18, 19', 'autres_pnc'),
            $total('TOTAL PASSIFS NON COURANTS', $pnc),
            $titre('PASSIFS COURANTS'),
            $ligne('Fournisseurs et comptes rattachés', '40 (créditeur)', 'fournisseurs'),
            $ligne('Impôts', '44 (créditeur)', 'impots_passif'),
            $ligne('Autres dettes', '41, 42-48 (créditeurs)', 'autres_dettes'),
            $ligne('Trésorerie passif (découverts bancaires)', '5 (créditeur)', 'tresorerie_passif'),
            $total('TOTAL PASSIFS COURANTS', $pc),
            $total('TOTAL GÉNÉRAL PASSIF', array_merge($cp, $pnc, $pc)),
        ];
    }

    // =====================================================================
    // Tableau des flux de trésorerie (méthode indirecte)
    // =====================================================================

    public function fluxTresorerie(string $debut, string $fin): array
    {
        $veille = Carbon::parse($debut)->subDay()->toDateString();
        $sF = $this->soldesCumules($fin);
        $sO = $this->soldesCumules($veille);
        // Variation d'un groupe de comptes, présentée en effet sur la trésorerie : −Δ(débit − crédit)
        $effet = fn (array $p, array $x = []) => round(-(self::somme($sF, $p, $x) - self::somme($sO, $p, $x)), 2);

        $resultat = $this->resultatNet($debut, $fin);
        // 59 (dépréciation des placements) reste dans la trésorerie nette : non repris ici
        $amortProv = $effet(['28', '29', '39', '49', '15']);
        $impotsDiff = $effet(['133', '134']);
        $stocks = $effet(['3'], ['39']);
        $clients = $effet(['41']);
        $fournisseurs = $effet(['40']);
        $autres = $effet(['42', '43', '44', '45', '46', '47', '48']);
        $fluxA = round($resultat + $amortProv + $impotsDiff + $stocks + $clients + $fournisseurs + $autres, 2);

        $immoCorpIncorp = $effet(['20', '21', '22', '23', '24', '25']);
        $immoFin = $effet(['26', '27']);
        $fluxB = round($immoCorpIncorp + $immoFin, 2);

        // Capitaux propres hors résultat de l'exercice : à l'ouverture, tous les
        // résultats (même non encore reportés) sont des capitaux acquis. Le report
        // du résultat (12/6-7 → 11 par les à-nouveaux) n'est donc pas un flux.
        $cpHorsResultatFin = self::somme($sF, ['10', '11', '12']) + self::somme($sF, ['6', '7']) + $resultat;
        $cpOuverture = self::somme($sO, ['10', '11', '12']) + self::somme($sO, ['6', '7']);
        $capitaux = round(-($cpHorsResultatFin - $cpOuverture), 2);
        $subventions = $effet(['13'], ['133', '134']);
        $emprunts = $effet(['16', '17']);
        $autresFin = $effet(['14', '18', '19']);
        $fluxC = round($capitaux + $subventions + $emprunts + $autresFin, 2);

        $variation = round($fluxA + $fluxB + $fluxC, 2);
        $tresoOuverture = self::somme($sO, ['5']);
        $tresoCloture = self::somme($sF, ['5']);

        $l = fn ($label, $montant, $flags = []) => ['label' => $label, 'note' => '', 'montant' => $montant] + $flags;
        $titre = fn ($label) => ['label' => $label, 'note' => '', 'montant' => null, 'isTitle' => true];

        return [
            $titre('Flux de trésorerie liés à l\'activité'),
            $l('Résultat net de l\'exercice', $resultat),
            ['label' => 'Ajustements pour :', 'note' => '', 'montant' => null, 'isSubtitle' => true],
            $l('Amortissements, dépréciations et provisions (nets)', $amortProv),
            $l('Variation des impôts différés', $impotsDiff),
            $l('Variation des stocks', $stocks),
            $l('Variation des clients', $clients),
            $l('Variation des fournisseurs', $fournisseurs),
            $l('Variation des autres créances et dettes', $autres),
            $l('Flux de trésorerie générés par l\'activité (A)', $fluxA, ['isTotal' => true]),
            $titre('Flux de trésorerie liés aux opérations d\'investissement'),
            $l('Acquisitions nettes d\'immobilisations corporelles et incorporelles', $immoCorpIncorp),
            $l('Variation des immobilisations financières', $immoFin),
            $l('Flux de trésorerie liés aux opérations d\'investissement (B)', $fluxB, ['isTotal' => true]),
            $titre('Flux de trésorerie liés aux opérations de financement'),
            $l('Augmentations de capital, réserves et dividendes', $capitaux),
            $l('Subventions d\'investissement reçues', $subventions),
            $l('Emprunts reçus nets des remboursements', $emprunts),
            $l('Autres flux de financement', $autresFin),
            $l('Flux de trésorerie liés aux opérations de financement (C)', $fluxC, ['isTotal' => true]),
            $l('Variation de trésorerie de la période (A + B + C)', $variation, ['isTotal' => true]),
            $l('Trésorerie d\'ouverture', $tresoOuverture),
            $l('Trésorerie de clôture', $tresoCloture),
            $l('Variation de trésorerie constatée (clôture − ouverture)', round($tresoCloture - $tresoOuverture, 2), ['isSubtitle' => true]),
        ];
    }

    // =====================================================================
    // Tableau de variation des capitaux propres
    // =====================================================================

    /** Capitaux propres par colonne à une date (exercice commençant à $debutExercice). */
    private function capitauxPropres(string $debutExercice, string $date): array
    {
        // Mêmes définitions que le passif du bilan (cohérence garantie)
        $p = $this->bilan($debutExercice, $date)['passif'];
        $c = [
            'capital' => $p['capital'],
            'reserves' => round($p['primes_reserves'] + $p['ecarts_reeval'] + $p['ecart_equiv'], 2),
            'result' => $p['resultat'],
            'report' => $p['report'],
        ];
        $c['total'] = round(array_sum($c), 2);
        return $c;
    }

    public function variationsCapitaux(string $debut, string $fin): array
    {
        $debutN = Carbon::parse($debut);
        $debutN1 = $debutN->copy()->subYear();
        $debutN2 = $debutN->copy()->subYears(2);
        $finN2 = $debutN1->copy()->subDay();
        $finN1 = $debutN->copy()->subDay();

        $soldeN2 = $this->capitauxPropres($debutN2->toDateString(), $finN2->toDateString());
        $soldeN1 = $this->capitauxPropres($debutN1->toDateString(), $finN1->toDateString());
        $soldeN = $this->capitauxPropres($debut, $fin);

        $ligne = fn ($label, $v, $flags) => ['label' => $label] + array_map(fn ($x) => round($x, 2), $v) + $flags;
        $mouvements = function (array $avant, array $apres, float $resultatAnnee, int $annee) use ($ligne) {
            // Report automatique du résultat précédent (exercices non clôturés par écritures)
            $affectation = ['capital' => 0, 'reserves' => 0, 'result' => -$avant['result'], 'report' => $avant['result']];
            $resultat = ['capital' => 0, 'reserves' => 0, 'result' => $resultatAnnee, 'report' => 0];
            $autres = [];
            foreach (['capital', 'reserves', 'result', 'report'] as $k) {
                $autres[$k] = $apres[$k] - $avant[$k] - $affectation[$k] - $resultat[$k];
            }
            $avecTotal = fn ($v) => $v + ['total' => array_sum($v)];
            $precedente = $annee - 1;
            return [
                $ligne("Affectation du résultat {$precedente}", $avecTotal($affectation), ['isDetail' => true]),
                $ligne("Augmentations de capital et autres mouvements {$annee}", $avecTotal($autres), ['isDetail' => true]),
                $ligne("Résultat net {$annee}", $avecTotal($resultat), ['isDetail' => true]),
            ];
        };

        $an = fn (Carbon $d) => $d->format('d/m/Y');
        return array_merge(
            [$ligne('Solde au ' . $an($finN2), $soldeN2, ['isTotal' => true])],
            [$ligne('Changement de méthode comptable', ['capital' => 0, 'reserves' => 0, 'result' => 0, 'report' => 0, 'total' => 0], ['isDetail' => true])],
            [$ligne('Correction d\'erreurs significatives', ['capital' => 0, 'reserves' => 0, 'result' => 0, 'report' => 0, 'total' => 0], ['isDetail' => true])],
            $mouvements($soldeN2, $soldeN1, $this->resultatNet($debutN1->toDateString(), $finN1->toDateString()), (int) $debutN1->year),
            [$ligne('Solde au ' . $an($finN1), $soldeN1, ['isTotal' => true])],
            $mouvements($soldeN1, $soldeN, $this->resultatNet($debut, $fin), (int) $debutN->year),
            [$ligne('Solde au ' . $an(Carbon::parse($fin)), $soldeN, ['isTotal' => true])],
        );
    }

    // =====================================================================
    // Contrôles de cohérence
    // =====================================================================

    public function controles(string $debut, string $fin): array
    {
        $actif = collect($this->bilanActif($debut, $fin))->firstWhere('label', 'TOTAL GÉNÉRAL ACTIF')['net'];
        $passif = collect($this->bilanPassif($debut, $fin))->firstWhere('label', 'TOTAL GÉNÉRAL PASSIF')['montant'];
        $bilan = $this->bilan($debut, $fin);
        $resCR = $this->postesResultat($debut, $fin)['resultat_net'];
        $resFonction = collect($this->compteResultatFonction($debut, $fin))->last()['montant'];
        $flux = collect($this->fluxTresorerie($debut, $fin))->keyBy('label');
        $variationFlux = $flux['Variation de trésorerie de la période (A + B + C)']['montant'];
        $variationReelle = $flux['Variation de trésorerie constatée (clôture − ouverture)']['montant'];
        $cpVariations = collect($this->variationsCapitaux($debut, $fin))->last()['total'];
        $cpBilan = collect($this->bilanPassif($debut, $fin))->firstWhere('label', 'TOTAL CAPITAUX PROPRES')['montant'];

        $desequilibrees = DB::table('ligne_ecritures')
            ->where('statut', EcritureGuard::STATUT_VALIDE)
            ->groupBy('Id_Mouvement_ecriture')
            ->havingRaw('ABS(SUM("Debit") - SUM("Credit")) >= ?', [EcritureGuard::tolerance()])
            ->select('Id_Mouvement_ecriture')
            ->get()->count();

        $brouillons = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->where('l.statut', '!=', EcritureGuard::STATUT_VALIDE)
            ->whereBetween('m.Date_mouvement', [$debut, $fin])
            ->selectRaw('COUNT(*) as lignes, COUNT(DISTINCT m."Id_Mouvement_ecriture") as pieces')
            ->first();

        $ok = fn ($a, $b) => abs($a - $b) < EcritureGuard::tolerance();
        return [
            'equilibre_bilan' => ['ok' => $ok($actif, $passif), 'actif' => $actif, 'passif' => $passif, 'ecart' => round($actif - $passif, 2)],
            'resultat' => [
                'ok' => $ok($resCR, $resFonction) && $ok($resCR, $bilan['resultat_exercice']),
                'compte_resultat' => $resCR,
                'par_fonction' => $resFonction,
                'bilan' => $bilan['resultat_exercice'],
            ],
            'tresorerie' => ['ok' => $ok($variationFlux, $variationReelle), 'flux' => $variationFlux, 'constatee' => $variationReelle, 'ecart' => round($variationFlux - $variationReelle, 2)],
            'capitaux_propres' => ['ok' => $ok($cpVariations, $cpBilan), 'variations' => $cpVariations, 'bilan' => $cpBilan],
            'pieces_validees_desequilibrees' => $desequilibrees,
            'brouillons_exclus' => ['lignes' => (int) $brouillons->lignes, 'pieces' => (int) $brouillons->pieces],
            'comptes_hors_plan' => array_keys($bilan['hors_plan']),
        ];
    }
}
