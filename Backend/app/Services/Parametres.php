<?php

namespace App\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Paramètres de l'application : point d'accès unique.
 *
 * DEFINITIONS décrit chaque paramètre (valeur par défaut + règles de
 * validation Laravel). Les valeurs enregistrées dans la table `parametres`
 * surchargent les défauts ; une clé jamais enregistrée vaut son défaut.
 *
 *   Parametres::get('compta.tolerance_equilibre')   // 0.01
 *   Parametres::get('societe.nom')
 */
class Parametres
{
    public const DEFINITIONS = [
        // ---- Entreprise (en-têtes des exports) ----
        'societe.nom' => ['defaut' => 'Mon entreprise', 'regles' => 'required|string|max:150'],
        'societe.forme_juridique' => ['defaut' => '', 'regles' => 'nullable|string|max:60'],
        'societe.activite' => ['defaut' => '', 'regles' => 'nullable|string|max:150'],
        'societe.adresse' => ['defaut' => '', 'regles' => 'nullable|string|max:255'],
        'societe.ville' => ['defaut' => 'Antananarivo', 'regles' => 'nullable|string|max:100'],
        'societe.pays' => ['defaut' => 'Madagascar', 'regles' => 'nullable|string|max:100'],
        'societe.telephone' => ['defaut' => '', 'regles' => 'nullable|string|max:50'],
        'societe.email' => ['defaut' => '', 'regles' => 'nullable|email|max:150'],
        'societe.site_web' => ['defaut' => '', 'regles' => 'nullable|string|max:150'],
        'societe.nif' => ['defaut' => '', 'regles' => 'nullable|string|max:50'],
        'societe.stat' => ['defaut' => '', 'regles' => 'nullable|string|max:50'],
        'societe.rcs' => ['defaut' => '', 'regles' => 'nullable|string|max:50'],
        // Logo en data URL (PNG / JPEG / SVG / WebP, ~512 Ko max)
        'societe.logo' => ['defaut' => '', 'regles' => ['nullable', 'string', 'max:700000', 'regex:/^data:image\/(png|jpeg|svg\+xml|webp);base64,/']],

        // ---- Comptabilité ----
        'compta.devise_code' => ['defaut' => 'MGA', 'regles' => 'required|string|max:10'],
        'compta.devise_symbole' => ['defaut' => 'Ar', 'regles' => 'required|string|max:10'],
        'compta.decimales' => ['defaut' => 2, 'regles' => 'required|integer|min:0|max:4'],
        'compta.tolerance_equilibre' => ['defaut' => 0.01, 'regles' => 'required|numeric|min:0|max:1'],
        'compta.taux_tva' => ['defaut' => 20.0, 'regles' => 'required|numeric|min:0|max:100'],

        // ---- Saisie et pièces ----
        'saisie.numero_separateur' => ['defaut' => '-', 'regles' => ['required', 'string', 'regex:/^[-\/_.]$/']],
        'saisie.numero_chiffres' => ['defaut' => 4, 'regles' => 'required|integer|min:3|max:8'],
        'saisie.justificatif_taille_max' => ['defaut' => 5, 'regles' => 'required|integer|min:1|max:20'],

        // ---- Tiers ----
        // Lignes de ces comptes (préfixes) : tiers exigé pour valider la pièce
        'tiers.obligatoire' => ['defaut' => true, 'regles' => 'required|boolean'],
        'tiers.comptes' => ['defaut' => '40,41', 'regles' => ['required', 'string', 'regex:/^4\d{0,5}(,4\d{0,5})*$/'], 'message' => 'Préfixes de comptes de classe 4 séparés par des virgules (ex. 40,41).'],

        // ---- Import d'écritures ----
        'import.separateur_csv' => ['defaut' => ';', 'regles' => ['required', 'string', 'regex:/^(;|,|\||tab)$/']],
        'import.taille_max' => ['defaut' => 10, 'regles' => 'required|integer|min:1|max:50'],

        // ---- Sécurité et affichage ----
        'securite.inscription_ouverte' => ['defaut' => true, 'regles' => 'required|boolean'],
        'securite.role_inscription' => ['defaut' => 'comptable', 'regles' => ['required', 'string', 'regex:/^(comptable|lecteur)$/']],
        'securite.mot_de_passe_min' => ['defaut' => 8, 'regles' => 'required|integer|min:6|max:32'],
        'securite.duree_session' =>['defaut' => 0, 'regles' => 'required|integer|min:0|max:10080'],
        'affichage.lignes_par_page' => ['defaut' => 25, 'regles' => 'required|integer|in:10,25,50,100'],

        // ---- Comptes et journaux utilisés par les traitements automatiques ----
        'systeme.compte_report_crediteur' => ['defaut' => '110001', 'regles' => 'required|regex:/^11\d{4}$/', 'message' => 'Doit être un compte à 6 chiffres de la classe 11 (ex. 110001).'],
        'systeme.compte_report_debiteur' => ['defaut' => '119001', 'regles' => 'required|regex:/^11\d{4}$/', 'message' => 'Doit être un compte à 6 chiffres de la classe 11 (ex. 119001).'],
        'systeme.compte_resultat_benefice' => ['defaut' => '120000', 'regles' => 'required|regex:/^12\d{4}$/', 'message' => 'Doit être un compte à 6 chiffres de la classe 12 (ex. 120000).'],
        'systeme.compte_resultat_perte' => ['defaut' => '129000', 'regles' => 'required|regex:/^12\d{4}$/', 'message' => 'Doit être un compte à 6 chiffres de la classe 12 (ex. 129000).'],
        'systeme.compte_dotations' => ['defaut' => '681000', 'regles' => 'required|regex:/^68\d{4}$/', 'message' => 'Doit être un compte à 6 chiffres de la classe 68 (ex. 681000).'],
        'systeme.journal_a_nouveaux' => ['defaut' => 'AN', 'regles' => 'required|string|max:10'],
        'systeme.journal_cloture' => ['defaut' => 'CL', 'regles' => 'required|string|max:10'],
        'systeme.journal_od' => ['defaut' => 'OD', 'regles' => 'required|string|max:10'],

        // ---- Notifications ----
        'notifications.cloture_active' => ['defaut' => true, 'regles' => 'required|boolean'],
        'notifications.cloture_jalons' => ['defaut' => [30, 7, 1], 'regles' => 'required|array|min:1|max:6', 'element' => 'integer|min:1|max:365'],

        // ---- Tableau de bord : seuils des ratios (bon / à surveiller) ----
        'dashboard.liquidite_bon' => ['defaut' => 1.5, 'regles' => 'required|numeric|min:0'],
        'dashboard.liquidite_moyen' => ['defaut' => 1.0, 'regles' => 'required|numeric|min:0'],
        'dashboard.autonomie_bon' => ['defaut' => 50.0, 'regles' => 'required|numeric|min:0|max:100'],
        'dashboard.autonomie_moyen' => ['defaut' => 25.0, 'regles' => 'required|numeric|min:0|max:100'],
        'dashboard.marge_bon' => ['defaut' => 10.0, 'regles' => 'required|numeric|min:-100|max:100'],
        'dashboard.marge_moyen' => ['defaut' => 0.0, 'regles' => 'required|numeric|min:-100|max:100'],
        'dashboard.delai_clients_bon' => ['defaut' => 60, 'regles' => 'required|integer|min:0|max:365'],
        'dashboard.delai_clients_moyen' => ['defaut' => 90, 'regles' => 'required|integer|min:0|max:365'],
    ];

    /** Messages d'erreur en français (règles génériques). */
    private const MESSAGES = [
        'required' => 'Ce champ est obligatoire.',
        'string' => 'Texte attendu.',
        'integer' => 'Nombre entier attendu.',
        'numeric' => 'Nombre attendu.',
        'boolean' => 'Valeur oui / non attendue.',
        'email' => 'Adresse e-mail invalide.',
        'array' => 'Liste attendue.',
        'in' => 'Valeur non autorisée.',
        'regex' => 'Format invalide.',
        'min.numeric' => 'La valeur doit être au moins :min.',
        'max.numeric' => 'La valeur ne doit pas dépasser :max.',
        'min.string' => 'Au moins :min caractères.',
        'max.string' => 'Au plus :max caractères.',
        'min.array' => 'Au moins :min élément(s).',
        'max.array' => 'Au plus :max éléments.',
        // Repli (champs entiers, éléments de liste)
        'min' => 'La valeur doit être au moins :min.',
        'max' => 'La valeur ne doit pas dépasser :max.',
    ];

    private static ?array $cache = null;

    /** Valeur d'un paramètre (défaut si jamais enregistré). */
    public static function get(string $cle): mixed
    {
        $tout = self::tout();
        if (!array_key_exists($cle, $tout)) {
            throw new \InvalidArgumentException("Paramètre inconnu : {$cle}");
        }
        return $tout[$cle];
    }

    /** Tous les paramètres (défauts surchargés par la base). */
    public static function tout(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $valeurs = array_map(fn ($d) => $d['defaut'], self::DEFINITIONS);
        // Table absente (base pas encore migrée) : on reste sur les défauts.
        if (Schema::hasTable('parametres')) {
            foreach (DB::table('parametres')->get() as $r) {
                if (array_key_exists($r->cle, $valeurs)) {
                    $valeurs[$r->cle] = self::normaliser($r->cle, json_decode($r->valeur, true));
                }
            }
        }
        return self::$cache = $valeurs;
    }

    /**
     * Valide puis enregistre des paramètres. Retourne [avant, après] pour les
     * seules clés réellement modifiées.
     */
    public static function enregistrer(array $valeurs): array
    {
        $inconnues = array_diff(array_keys($valeurs), array_keys(self::DEFINITIONS));
        if ($inconnues) {
            throw ValidationException::withMessages(['parametres' => 'Paramètre(s) inconnu(s) : ' . implode(', ', $inconnues)]);
        }

        // Validation (les clés « a.b » sont imbriquées pour le validateur Laravel)
        $regles = [];
        $donnees = [];
        $messages = [];
        foreach ($valeurs as $cle => $v) {
            $champ = str_replace('.', '__', $cle);
            $donnees[$champ] = $v;
            $regles[$champ] = self::DEFINITIONS[$cle]['regles'];
            if (isset(self::DEFINITIONS[$cle]['element'])) {
                $regles[$champ . '.*'] = self::DEFINITIONS[$cle]['element'];
            }
            if (isset(self::DEFINITIONS[$cle]['message'])) {
                $messages[$champ . '.regex'] = self::DEFINITIONS[$cle]['message'];
            }
        }
        $validateur = validator($donnees, $regles, $messages + self::MESSAGES);
        if ($validateur->fails()) {
            $erreurs = [];
            foreach ($validateur->errors()->messages() as $champ => $msg) {
                $erreurs[str_replace('__', '.', explode('.', $champ)[0])] = $msg;
            }
            throw ValidationException::withMessages($erreurs);
        }

        // Cohérence des seuils « bon » / « à surveiller »
        $fusion = array_merge(self::tout(), $valeurs);
        foreach (['liquidite', 'autonomie', 'marge'] as $r) {
            if ($fusion["dashboard.{$r}_bon"] < $fusion["dashboard.{$r}_moyen"]) {
                throw ValidationException::withMessages(["dashboard.{$r}_bon" => ['Le seuil « bon » doit être supérieur ou égal au seuil « à surveiller ».']]);
            }
        }
        if ($fusion['dashboard.delai_clients_bon'] > $fusion['dashboard.delai_clients_moyen']) {
            throw ValidationException::withMessages(['dashboard.delai_clients_bon' => ['Le délai « bon » doit être inférieur ou égal au délai « à surveiller ».']]);
        }

        $actuel = self::tout();
        $avant = [];
        $apres = [];
        DB::transaction(function () use ($valeurs, $actuel, &$avant, &$apres) {
            foreach ($valeurs as $cle => $v) {
                $v = self::normaliser($cle, $v);
                if ($actuel[$cle] === $v) {
                    continue;
                }
                $avant[$cle] = $actuel[$cle];
                $apres[$cle] = $v;
                DB::table('parametres')->updateOrInsert(
                    ['cle' => $cle],
                    ['valeur' => json_encode($v, JSON_UNESCAPED_UNICODE), 'modifie_par' => Auth::id(), 'modifie_le' => now()]
                );
            }
        });
        self::$cache = null;
        return [$avant, $apres];
    }

    /** Ramène la valeur au type du défaut (les formulaires envoient parfois des chaînes). */
    private static function normaliser(string $cle, mixed $v): mixed
    {
        $defaut = self::DEFINITIONS[$cle]['defaut'];
        return match (true) {
            is_bool($defaut) => (bool) $v,
            is_int($defaut) => (int) $v,
            is_float($defaut) => (float) $v,
            is_array($defaut) => array_values(array_unique(array_map('intval', $v))),
            default => $v === null ? '' : trim((string) $v),
        };
    }

    /** Vide le cache (tests, longues commandes). */
    public static function oublier(): void
    {
        self::$cache = null;
    }
}
