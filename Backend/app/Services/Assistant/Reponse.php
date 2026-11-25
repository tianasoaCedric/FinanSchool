<?php

namespace App\Services\Assistant;

/**
 * Réponse de l'assistant : texte (retours à la ligne et **gras**),
 * suggestions de questions cliquables et liens vers les écrans concernés.
 */
class Reponse
{
    /**
     * @param string[] $suggestions
     * @param array<int, array{libelle: string, url: string}> $liens
     */
    public function __construct(
        public string $texte,
        public array $suggestions = [],
        public array $liens = [],
    ) {}

    public function lien(string $libelle, string $url): self
    {
        $this->liens[] = ['libelle' => $libelle, 'url' => $url];
        return $this;
    }

    public static function montant(float $m): string
    {
        return number_format($m, 2, ',', ' ') . ' Ar';
    }

    public static function date(string $d): string
    {
        return \Carbon\Carbon::parse($d)->format('d/m/Y');
    }
}
