<?php

namespace App\Services\Comptabilite;

use App\Models\PlanCompte\Classe;
use App\Models\PlanCompte\Compte;
use App\Models\PlanCompte\Rubrique;
use App\Models\PlanCompte\SousCompte;
use Illuminate\Support\Facades\DB;

/**
 * Import d'un plan comptable (liste « code ; libellé »).
 *
 * Le niveau se déduit de la longueur du code (PCG 2005) :
 *   1 chiffre = classe · 2 = rubrique · 3 = compte · 4 à 6 = sous-compte
 *   (un code de 4 ou 5 chiffres est complété à droite par des zéros : 6011 → 601100).
 * Les parents manquants (classe, rubrique, compte) sont créés automatiquement
 * avec un libellé provisoire, sauf s'ils figurent eux-mêmes dans le fichier.
 *
 * analyser() ne modifie rien (aperçu) ; importer() applique le même plan dans
 * une transaction. Une ligne en erreur est ignorée, les autres sont importées.
 * Les créations / modifications passent par les modèles : la piste d'audit
 * (PlanComptableObserver) les trace une à une.
 */
class ImportPlanComptable
{
    public const MAX_LIGNES = 10000;

    private const NIVEAUX = [1 => 'classe', 2 => 'rubrique', 3 => 'compte', 6 => 'sous_compte'];
    private const LIBELLE_PROVISOIRE = [
        'classe' => 'Classe %s',
        'rubrique' => 'Rubrique %s',
        'compte' => 'Compte %s',
    ];

    /** Codes existants par niveau : code => libellé. */
    private array $existants = [];

    /**
     * @param array<int, array{code?: mixed, libelle?: mixed}> $lignes
     * @return array{lignes: array, parents: array, resume: array}
     */
    public function analyser(array $lignes, bool $majLibelles): array
    {
        $this->chargerExistants();
        $resultat = [];
        $vus = [];

        foreach (array_values($lignes) as $i => $l) {
            $r = $this->analyserLigne($i + 1, $l, $majLibelles, $vus);
            if ($r['action'] !== 'erreur') {
                $vus[$r['code']] = $r['ligne'];
            }
            $resultat[] = $r;
        }

        // Parents à créer : absents en base et absents du fichier
        $parents = [];
        foreach ($resultat as $r) {
            if ($r['action'] !== 'creer') {
                continue;
            }
            foreach ($this->ancetres($r['code']) as $niveau => $code) {
                if (!isset($this->existants[$niveau][$code]) && !isset($vus[$code]) && !isset($parents[$code])) {
                    $parents[$code] = ['code' => $code, 'niveau' => $niveau, 'libelle' => sprintf(self::LIBELLE_PROVISOIRE[$niveau], $code)];
                }
            }
        }
        ksort($parents, SORT_STRING);

        $compter = fn ($a) => count(array_filter($resultat, fn ($r) => $r['action'] === $a));
        return [
            'lignes' => $resultat,
            'parents' => array_values($parents),
            'resume' => [
                'total' => count($resultat),
                'creer' => $compter('creer'),
                'maj' => $compter('maj'),
                'inchange' => $compter('inchange'),
                'erreur' => $compter('erreur'),
                'parents' => count($parents),
            ],
        ];
    }

    /** Applique l'import ; renvoie l'analyse (ce qui a été fait). */
    public function importer(array $lignes, bool $majLibelles): array
    {
        $analyse = $this->analyser($lignes, $majLibelles);

        DB::transaction(function () use ($analyse) {
            // Parents d'abord, du plus haut au plus bas niveau, puis les lignes
            $aCreer = array_merge(
                array_map(fn ($p) => $p + ['action' => 'creer'], $analyse['parents']),
                array_filter($analyse['lignes'], fn ($r) => in_array($r['action'], ['creer', 'maj'], true))
            );
            usort($aCreer, fn ($a, $b) => [strlen($a['code']), $a['code']] <=> [strlen($b['code']), $b['code']]);

            foreach ($aCreer as $r) {
                $r['action'] === 'creer' ? $this->creer($r) : $this->renommer($r);
            }
        });

        return $analyse;
    }

    // ------------------------------------------------------------------

    private function analyserLigne(int $num, array $l, bool $majLibelles, array $vus): array
    {
        $source = trim((string) ($l['code'] ?? ''));
        $libelle = trim(preg_replace('/\s+/u', ' ', (string) ($l['libelle'] ?? '')));
        $code = preg_replace('/[\s.\-_]/', '', $source);
        $base = ['ligne' => $num, 'code_source' => $source, 'code' => $code, 'libelle' => $libelle, 'niveau' => null, 'note' => null];
        $erreur = fn (string $m) => $base + ['action' => 'erreur', 'message' => $m];

        if ($code === '') {
            return $erreur('Code manquant.');
        }
        if (!ctype_digit($code)) {
            return $erreur('Le code ne doit contenir que des chiffres.');
        }
        if (strlen($code) > 6) {
            return $erreur('Code trop long : 6 chiffres au maximum (sous-compte).');
        }
        if ($code[0] === '0') {
            return $erreur('Un code ne peut pas commencer par 0.');
        }
        if (in_array(strlen($code), [4, 5], true)) {
            $base['note'] = "Complété en {$this->completer($code)}";
            $code = $this->completer($code);
            $base['code'] = $code;
        }
        if ($libelle === '') {
            return $erreur('Libellé manquant.');
        }
        if (mb_strlen($libelle) > 255) {
            return $erreur('Libellé trop long (255 caractères au maximum).');
        }
        if (isset($vus[$code])) {
            return $erreur("Code en double dans le fichier (déjà à la ligne {$vus[$code]}).");
        }

        $niveau = self::NIVEAUX[strlen($code)];
        $base['niveau'] = $niveau;
        $actuel = $this->existants[$niveau][$code] ?? null;

        if ($actuel === null) {
            return $base + ['action' => 'creer', 'message' => null];
        }
        if ($actuel === $libelle) {
            return $base + ['action' => 'inchange', 'message' => 'Déjà présent.'];
        }
        return $majLibelles
            ? $base + ['action' => 'maj', 'message' => "Libellé actuel : « {$actuel} »"]
            : $base + ['action' => 'inchange', 'message' => "Existe avec le libellé « {$actuel} » (non modifié)."];
    }

    private function completer(string $code): string
    {
        return str_pad($code, 6, '0', STR_PAD_RIGHT);
    }

    /** Ancêtres d'un code, du plus haut niveau au plus bas : niveau => code. */
    private function ancetres(string $code): array
    {
        $a = [];
        foreach ([1 => 'classe', 2 => 'rubrique', 3 => 'compte'] as $long => $niveau) {
            if (strlen($code) > $long) {
                $a[$niveau] = substr($code, 0, $long);
            }
        }
        return $a;
    }

    private function chargerExistants(): void
    {
        $this->existants = [
            'classe' => DB::table('classes')->pluck('Libelle', 'Code')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all(),
            'rubrique' => DB::table('rubriques')->pluck('Libelle', 'Code_rubrique')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all(),
            'compte' => DB::table('comptes')->pluck('Libelle', 'Code_compte')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all(),
            'sous_compte' => DB::table('sous_comptes')->pluck('Libelle', 'Code_sous_compte')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all(),
        ];
    }

    private function creer(array $r): void
    {
        $code = $r['code'];
        match ($r['niveau']) {
            'classe' => Classe::create(['Code' => $code, 'Libelle' => $r['libelle']]),
            'rubrique' => Rubrique::create([
                'Code_rubrique' => $code, 'Libelle' => $r['libelle'],
                'Id_Classe' => Classe::where('Code', $code[0])->value('Id_Classe'),
            ]),
            'compte' => Compte::create([
                'Code_compte' => $code, 'Libelle' => $r['libelle'],
                'Id_Rubrique' => Rubrique::where('Code_rubrique', substr($code, 0, 2))->value('Id_Rubrique'),
            ]),
            'sous_compte' => SousCompte::create([
                'Code_sous_compte' => $code, 'Libelle' => $r['libelle'],
                'Id_Compte' => Compte::where('Code_compte', substr($code, 0, 3))->value('Id_Compte'),
            ]),
        };
    }

    private function renommer(array $r): void
    {
        $modele = match ($r['niveau']) {
            'classe' => Classe::where('Code', $r['code']),
            'rubrique' => Rubrique::where('Code_rubrique', $r['code']),
            'compte' => Compte::where('Code_compte', $r['code']),
            'sous_compte' => SousCompte::where('Code_sous_compte', $r['code']),
        };
        // Via le modèle (et non une requête de masse) pour que l'audit trace le changement
        $modele->first()?->update(['Libelle' => $r['libelle']]);
    }
}
