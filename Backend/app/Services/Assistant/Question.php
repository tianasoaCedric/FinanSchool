<?php

namespace App\Services\Assistant;

/**
 * Question de l'utilisateur, analysée : texte normalisé, mots, et paramètres
 * reconnus (comptes, journal, pièce, période). Produite par l'Analyseur.
 */
class Question
{
    /**
     * @param string      $texte    Texte tel que saisi
     * @param string      $normalise Minuscules, sans accents ni ponctuation, espaces simples
     * @param string[]    $mots     Mots du texte normalisé
     * @param string[]    $codesComptes Codes de comptes cités et présents dans le plan comptable
     * @param array|null  $journal  ['id', 'code', 'libelle'] si un code journal est cité
     * @param array|null  $piece    ['numero' => ..] ou ['brouillon' => id] si une pièce est citée
     * @param array|null  $periode  ['debut', 'fin', 'libelle', 'explicite' => bool]
     * @param string[]    $termesLibres Mots significatifs restants (recherche par libellé)
     */
    public function __construct(
        public readonly string $texte,
        public readonly string $normalise,
        public readonly array $mots,
        public readonly array $codesComptes,
        public readonly ?array $journal,
        public readonly ?array $piece,
        public readonly ?array $periode,
        public readonly array $termesLibres,
        public readonly ?int $annee,
    ) {}

    /** Le texte contient-il ce mot ou cette expression (forme normalisée) ? */
    public function contient(string $expression): bool
    {
        return str_contains(" {$this->normalise} ", ' ' . Analyseur::normaliser($expression) . ' ');
    }

    public function compte(): ?string
    {
        return $this->codesComptes[0] ?? null;
    }
}
