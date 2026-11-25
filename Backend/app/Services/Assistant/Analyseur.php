<?php

namespace App\Services\Assistant;

use App\Models\exercice\ExerciceComptable;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Analyse une question : normalisation (minuscules, sans accents ni
 * ponctuation) et extraction des paramètres. Les codes de comptes et de
 * journaux ne sont retenus que s'ils existent réellement en base.
 */
class Analyseur
{
    private const MOIS = [
        'janvier' => 1, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'mai' => 5, 'juin' => 6,
        'juillet' => 7, 'aout' => 8, 'septembre' => 9, 'octobre' => 10, 'novembre' => 11, 'decembre' => 12,
    ];

    /** Mots sans valeur pour la recherche par libellé. */
    private const MOTS_VIDES = [
        'le', 'la', 'les', 'un', 'une', 'des', 'du', 'de', 'd', 'l', 'au', 'aux', 'a', 'et', 'ou', 'en', 'dans',
        'pour', 'par', 'sur', 'avec', 'sans', 'est', 'sont', 'quel', 'quelle', 'quels', 'quelles', 'qui', 'que',
        'quoi', 'mon', 'ma', 'mes', 'notre', 'nos', 'votre', 'vos', 'son', 'sa', 'ses', 'ce', 'cet', 'cette',
        'ces', 'il', 'elle', 'on', 'nous', 'vous', 'je', 'j', 'me', 'moi', 'y', 'ne', 'pas', 'plus', 'moins',
        'combien', 'donne', 'donner', 'montre', 'montrer', 'affiche', 'afficher', 'voir', 'veux', 'voudrais',
        'peux', 'pouvez', 'svp', 'stp', 'merci', 'bien', 'tout', 'tous', 'toutes', 'actuel', 'actuelle',
        'aujourd', 'hui', 'mois', 'annee', 'exercice', 'jour', 'date', 'fin', 'debut', 'entre', 'depuis', 'jusqu',
        'ai', 'as', 'avons', 'avez', 'ont', 'faut', 'faire', 'fait', 'liste', 'lister', 'cherche', 'chercher',
        'trouve', 'trouver', 'recherche', 'numero', 'code', 'compte', 'comptes', 'sous', 'souscompte',
        // Mots qui décrivent la demande, pas le compte recherché
        'solde', 'soldes', 'mouvement', 'mouvements', 'ecriture', 'ecritures', 'operation', 'operations',
        'dernier', 'derniers', 'derniere', 'dernieres', 'historique', 'grand', 'livre', 'detail', 'details',
        'montant', 'reste', 'releve', 'quel', 'libelle', 'nom', 'appelle', 'correspond',
    ];

    public static function normaliser(string $texte): string
    {
        $t = mb_strtolower(trim($texte));
        $t = strtr($t, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'œ' => 'oe', 'æ' => 'ae', '’' => ' ', "'" => ' ',
        ]);
        $t = preg_replace('/[^a-z0-9]+/', ' ', $t);
        return trim(preg_replace('/\s+/', ' ', $t));
    }

    public function analyser(string $texte): Question
    {
        $normalise = self::normaliser($texte);
        $mots = $normalise === '' ? [] : explode(' ', $normalise);

        $piece = $this->piece($texte);
        $periode = $this->periode($texte, $normalise);
        $annee = $this->annee($normalise);

        // Nombres déjà « utilisés » par une date, une année ou une pièce : ce ne sont pas des comptes
        $exclus = [];
        if ($piece) {
            preg_match_all('/\d+/', $piece['numero'] ?? (string) ($piece['brouillon'] ?? ''), $m);
            $exclus = array_merge($exclus, $m[0]);
        }
        preg_match_all('/\b\d{1,2}[\/.-]\d{1,2}[\/.-]\d{4}\b/', $texte, $dates);
        foreach ($dates[0] as $d) {
            preg_match_all('/\d+/', $d, $m);
            $exclus = array_merge($exclus, $m[0]);
        }

        $codes = [];
        foreach ($mots as $i => $mot) {
            if (!preg_match('/^\d{2,8}$/', $mot) || in_array($mot, $exclus, true) || self::estAnnee($mot)) {
                continue;
            }
            // « 31 mars » : le nombre devant un mois est un jour
            if (isset($mots[$i + 1]) && isset(self::MOIS[$mots[$i + 1]])) {
                continue;
            }
            if (DB::table('sous_comptes')->where('Code_sous_compte', 'like', $mot . '%')->exists()) {
                $codes[] = $mot;
            }
        }

        return new Question(
            texte: $texte,
            normalise: $normalise,
            mots: $mots,
            codesComptes: array_values(array_unique($codes)),
            journal: $this->journal($mots),
            piece: $piece,
            periode: $periode,
            termesLibres: $this->termesLibres($mots),
            annee: $annee,
        );
    }

    // ------------------------------------------------------------------

    private static function estAnnee(string $mot): bool
    {
        return (bool) preg_match('/^(19|20)\d{2}$/', $mot);
    }

    private function annee(string $normalise): ?int
    {
        if (preg_match('/\b((?:19|20)\d{2})\b/', $normalise, $m)) {
            return (int) $m[1];
        }
        if (str_contains(" $normalise ", ' annee derniere ') || str_contains(" $normalise ", ' exercice precedent ')) {
            return (int) date('Y') - 1;
        }
        return null;
    }

    /** Pièce citée : numéro définitif (BNI2025-0012) ou brouillon (BR-128). */
    private function piece(string $texte): ?array
    {
        if (preg_match('/\bBR\s*-?\s*(\d+)\b/i', $texte, $m)) {
            return ['brouillon' => (int) $m[1]];
        }
        if (preg_match('/\b([A-Za-z]{1,6}\d{4}-\d{3,6})\b/', $texte, $m)) {
            return ['numero' => strtoupper($m[1])];
        }
        return null;
    }

    /** Code journal cité : 3 lettres ou plus, ou n'importe quel code précédé de « journal ». */
    private function journal(array $mots): ?array
    {
        $codes = DB::table('journals')->select('Id_Journal', 'Code', 'Libelle')->get()
            ->keyBy(fn ($j) => mb_strtolower($j->Code));
        foreach ($mots as $i => $mot) {
            $j = $codes[$mot] ?? null;
            $apresJournal = $i > 0 && in_array($mots[$i - 1], ['journal', 'journaux'], true);
            if ($j && (strlen($mot) >= 3 || $apresJournal)) {
                return ['id' => $j->Id_Journal, 'code' => $j->Code, 'libelle' => $j->Libelle];
            }
        }
        return null;
    }

    /**
     * Période : date précise (« au 31/03/2025 », « au 31 mars 2025 »), mois
     * (« en mars 2025 », « ce mois-ci »), ou année / exercice (« 2025 »).
     */
    private function periode(string $texte, string $n): ?array
    {
        $n = " $n ";

        // Date précise : jj/mm/aaaa
        if (preg_match('/\b(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})\b/', $texte, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            $d = Carbon::create((int) $m[3], (int) $m[2], (int) $m[1])->toDateString();
            return $this->jusquAu($d);
        }
        // Date précise : 31 mars 2025
        $mois = implode('|', array_keys(self::MOIS));
        if (preg_match("/ (\d{1,2}) ($mois) ((?:19|20)\d{2}) /", $n, $m) && checkdate(self::MOIS[$m[2]], (int) $m[1], (int) $m[3])) {
            return $this->jusquAu(Carbon::create((int) $m[3], self::MOIS[$m[2]], (int) $m[1])->toDateString());
        }
        // Mois (avec ou sans année)
        if (preg_match("/ ($mois)(?: ((?:19|20)\d{2}))? /", $n, $m)) {
            $annee = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : (int) date('Y');
            return $this->mois($annee, self::MOIS[$m[1]]);
        }
        if (str_contains($n, ' ce mois ') || str_contains($n, ' mois en cours ')) {
            return $this->mois((int) date('Y'), (int) date('n'));
        }
        if (str_contains($n, ' mois dernier ') || str_contains($n, ' mois precedent ')) {
            $d = Carbon::now()->subMonthNoOverflow();
            return $this->mois($d->year, $d->month);
        }
        // Année ou exercice
        $annee = $this->annee(trim($n));
        if ($annee) {
            return self::periodeExercice($annee);
        }
        return null;
    }

    private function jusquAu(string $date): array
    {
        $ex = ExerciceComptable::getExerciceByDate($date);
        $debut = $ex ? Carbon::parse($ex->Date_debut)->toDateString() : Carbon::parse($date)->startOfYear()->toDateString();
        return ['debut' => $debut, 'fin' => $date, 'libelle' => 'au ' . Reponse::date($date), 'explicite' => true];
    }

    private function mois(int $annee, int $mois): array
    {
        $d = Carbon::create($annee, $mois, 1);
        return [
            'debut' => $d->toDateString(),
            'fin' => $d->copy()->endOfMonth()->toDateString(),
            'libelle' => $d->locale('fr')->translatedFormat('F Y'),
            'explicite' => true,
        ];
    }

    /** Exercice comptable de l'année demandée, sinon l'année civile. */
    public static function periodeExercice(int $annee): array
    {
        $ex = ExerciceComptable::where('Annee_fiscale', $annee)->first();
        if ($ex) {
            return [
                'debut' => Carbon::parse($ex->Date_debut)->toDateString(),
                'fin' => Carbon::parse($ex->Date_fin)->toDateString(),
                'libelle' => "exercice {$annee}",
                'explicite' => true,
            ];
        }
        return ['debut' => "{$annee}-01-01", 'fin' => "{$annee}-12-31", 'libelle' => "année {$annee}", 'explicite' => true];
    }

    /** Période par défaut : l'exercice qui contient aujourd'hui, sinon le dernier exercice ouvert. */
    public static function periodeParDefaut(): array
    {
        $ex = ExerciceComptable::getExerciceByDate(date('Y-m-d'))
            ?? ExerciceComptable::where('Statut', 'OUVERT')->orderByDesc('Annee_fiscale')->first();
        if (!$ex) {
            return self::periodeExercice((int) date('Y'));
        }
        $p = self::periodeExercice((int) $ex->Annee_fiscale);
        $p['explicite'] = false;
        return $p;
    }

    private function termesLibres(array $mots): array
    {
        $vides = array_flip(array_merge(self::MOTS_VIDES, array_keys(self::MOIS)));
        return array_values(array_unique(array_filter($mots, fn ($m) =>
            strlen($m) >= 3 && !ctype_digit($m) && !isset($vides[$m]))));
    }
}
