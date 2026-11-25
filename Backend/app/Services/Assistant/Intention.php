<?php

namespace App\Services\Assistant;

/**
 * Un type de question que l'assistant sait traiter.
 *
 * Chaque intention déclare des mots-clés pondérés ; l'Assistant retient
 * celle qui obtient le meilleur score. Les mots-clés sont comparés au texte
 * normalisé : expression exacte, mot de même racine (pluriel, conjugaison),
 * ou une faute de frappe près pour les mots longs.
 */
abstract class Intention
{
    /** @return array<string, float> mot-clé ou expression => poids */
    abstract protected function motsCles(): array;

    abstract public function repondre(Question $q): Reponse;

    /** Points ajoutés selon les paramètres reconnus (compte, pièce…). */
    protected function bonus(Question $q): float
    {
        return 0.0;
    }

    public function score(Question $q): float
    {
        $score = 0.0;
        foreach ($this->motsCles() as $cle => $poids) {
            if (self::trouve($q, $cle)) {
                $score += $poids;
            }
        }
        return $score > 0 ? $score + $this->bonus($q) : 0.0;
    }

    public static function trouve(Question $q, string $cle): bool
    {
        $cle = Analyseur::normaliser($cle);
        if (str_contains($cle, ' ')) {
            return str_contains(" {$q->normalise} ", " {$cle} ");
        }
        foreach ($q->mots as $mot) {
            if ($mot === $cle) {
                return true;
            }
            // Même racine : « valider » / « validees », « journal » / « journaux »
            if (strlen($cle) >= 5 && strlen($mot) >= 5 && substr($mot, 0, 5) === substr($cle, 0, 5)
                && abs(strlen($mot) - strlen($cle)) <= 3) {
                return true;
            }
            // Une faute de frappe sur un mot long : « tresorie », « brouilon »
            if (strlen($cle) >= 6 && abs(strlen($mot) - strlen($cle)) <= 1 && levenshtein($mot, $cle) <= 1) {
                return true;
            }
        }
        return false;
    }
}
