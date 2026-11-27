<?php

namespace App\Services\Comptabilite;

use App\Models\Saisie\MouvementEcriture;
use App\Models\Tiers\Tiers;
use App\Services\Parametres;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Règles et calculs liés aux comptes de tiers.
 *
 *  - un tiers ne se rattache qu'à une ligne de classe 4 ;
 *  - sur les comptes listés dans « tiers.comptes » (40, 41 par défaut), le
 *    tiers est exigé pour valider la pièce (si « tiers.obligatoire ») ;
 *  - les soldes par tiers suivent la même fenêtre que les états financiers :
 *    l'historique antérieur au dernier exercice ouvert par des à-nouveaux est
 *    représenté par les lignes d'à-nouveaux (détaillées par tiers).
 */
class TiersService
{
    /** Préfixes des comptes qui exigent un tiers (vide si la règle est désactivée). */
    public static function prefixesObligatoires(): array
    {
        if (!Parametres::get('tiers.obligatoire')) {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) Parametres::get('tiers.comptes')))));
    }

    public static function exigeTiers(?string $codeCompte): bool
    {
        foreach (self::prefixesObligatoires() as $p) {
            if (str_starts_with((string) $codeCompte, $p)) {
                return true;
            }
        }
        return false;
    }

    /** Un tiers n'a de sens que sur un compte de classe 4. */
    public static function accepteTiers(?string $codeCompte): bool
    {
        return str_starts_with((string) $codeCompte, '4');
    }

    /**
     * Motif bloquant la validation d'une pièce côté tiers, ou null.
     * Les pièces générées par l'application (à-nouveaux, clôture) sont exemptées.
     */
    public static function motifPiece(MouvementEcriture $piece): ?string
    {
        $piece->loadMissing('lignes.sousCompte');
        foreach ($piece->lignes->values() as $i => $l) {
            $code = $l->sousCompte?->Code_sous_compte;
            if ($l->id_tiers && !self::accepteTiers($code)) {
                return "Ligne " . ($i + 1) . " : un tiers ne peut être indiqué que sur un compte de classe 4 ({$code})";
            }
            if (!$l->id_tiers && self::exigeTiers($code)
                && !in_array($piece->type_piece, MouvementEcriture::TYPES_TECHNIQUES, true)) {
                return "Ligne " . ($i + 1) . " : indiquez le client ou le fournisseur du compte {$code}";
            }
        }
        $inactifs = Tiers::whereIn('id', $piece->lignes->pluck('id_tiers')->filter()->unique())->where('actif', false)->pluck('nom');
        if ($inactifs->isNotEmpty()) {
            return 'Tiers désactivé : ' . $inactifs->join(', ');
        }
        return null;
    }

    /**
     * Début de la fenêtre de calcul à une date (voir EtatsFinanciers::fenetre) ;
     * sans date : fenêtre courante (dernier exercice ouvert par des à-nouveaux).
     */
    public static function fenetre(?string $date = null): ?string
    {
        if ($date !== null) {
            return (new EtatsFinanciers())->fenetre(Carbon::parse($date)->toDateString());
        }
        $d = DB::table('exercice_comptable')->where('ouvert_par_a_nouveaux', true)->max('Date_debut');
        return $d ? Carbon::parse($d)->toDateString() : null;
    }

    /**
     * Soldes (débit − crédit) par compte et par tiers à une date (null : à ce
     * jour, toutes écritures), écritures validées, hors écriture de clôture.
     * Lignes : Id_Sous_compte, id_tiers, solde.
     */
    public static function soldesParTiers(?string $fin = null, ?array $sousComptes = null): array
    {
        $q = DB::table('ligne_ecritures as l')
            ->join('mouvement_ecritures as m', 'm.Id_Mouvement_ecriture', '=', 'l.Id_Mouvement_ecriture')
            ->where('l.statut', EcritureGuard::STATUT_VALIDE)
            ->where('m.type_piece', '!=', MouvementEcriture::TYPE_CLOTURE)
            ->groupBy('l.Id_Sous_compte', 'l.id_tiers')
            ->selectRaw('l."Id_Sous_compte" as id_sous_compte, l.id_tiers, ROUND(SUM(l."Debit" - l."Credit")::numeric, 2) as solde');
        if ($fin !== null) {
            $q->where('m.Date_mouvement', '<=', Carbon::parse($fin)->toDateString());
        }
        if ($debut = self::fenetre($fin)) {
            $q->where('m.Date_mouvement', '>=', $debut);
        }
        if ($sousComptes !== null) {
            $q->whereIn('l.Id_Sous_compte', $sousComptes);
        }
        return $q->get()->map(fn ($r) => [
            'Id_Sous_compte' => (int) $r->id_sous_compte,
            'id_tiers' => $r->id_tiers !== null ? (int) $r->id_tiers : null,
            'solde' => (float) $r->solde,
        ])->all();
    }

    /** Code proposé pour un nouveau tiers : C0001, F0001… */
    public static function prochainCode(string $type): string
    {
        $prefixe = Tiers::PREFIXES[$type] ?? 'T';
        $max = Tiers::where('code', 'like', $prefixe . '%')->pluck('code')
            ->map(fn ($c) => ctype_digit(substr($c, 1)) ? (int) substr($c, 1) : 0)
            ->max() ?? 0;
        return $prefixe . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }
}
