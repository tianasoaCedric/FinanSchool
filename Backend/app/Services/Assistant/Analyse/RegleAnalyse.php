<?php

namespace App\Services\Assistant\Analyse;

use App\Http\Controllers\ChatBot\ChatUtilesController;
use App\Http\Controllers\ChatBot\DepensesProfitsController;
use App\Http\Controllers\ChatBot\DiagnosticController;
use App\Services\Assistant\Intention;
use App\Services\Assistant\Question;
use App\Services\Assistant\Reponse;

/**
 * Questions du module d'analyse financière (centres de coûts, diagnostic).
 *
 * Reprise à l'identique des règles de l'ancien chatbot d'analyse : mêmes
 * conditions, même ordre de priorité, mêmes réponses. Le module d'analyse
 * garde ainsi exactement le même comportement.
 */
class RegleAnalyse extends Intention
{
    public const SALUTATION = 'salutation';
    public const DEPENSES = 'depenses';
    public const DIAGNOSTIC = 'diagnostic';

    /** Priorité de l'ancien code : salutation, puis dépenses, puis diagnostic. */
    private const PRIORITE = [self::SALUTATION => 30, self::DEPENSES => 20, self::DIAGNOSTIC => 10];

    public function __construct(private string $regle) {}

    protected function motsCles(): array
    {
        return [];
    }

    public function score(Question $q): float
    {
        return $this->correspond(ChatUtilesController::normalizeText($q->texte)) ? self::PRIORITE[$this->regle] : 0.0;
    }

    private function correspond(string $t): bool
    {
        return match ($this->regle) {
            self::SALUTATION => self::contient($t, ['bonjour', 'salut', 'hello', 'hi']),
            self::DEPENSES => self::estDepense($t) && $this->sousRegleDepense($t) !== null,
            self::DIAGNOSTIC => self::contient($t, ['resume', 'bilan', 'analyse globale', 'état financier', 'santé financière']),
        };
    }

    public function repondre(Question $q): Reponse
    {
        $t = ChatUtilesController::normalizeText($q->texte);
        return new Reponse(match ($this->regle) {
            self::SALUTATION => "Bonjour ! Je suis votre assistant pour l'analyse financière scolaire. Comment puis-je vous aider ?",
            self::DEPENSES => $this->depenses($t),
            self::DIAGNOSTIC => $this->diagnostic($t),
        }, self::suggestions());
    }

    public static function suggestions(): array
    {
        return ['Un résumé des indicateurs financiers de cette année.'];
    }

    public static function texteParDefaut(): string
    {
        return "Je suis votre assistant financier pour établissements scolaires. Actuellement en cours de configuration, je pourrai bientôt vous aider avec :\n\n• Analyse des budgets\n• Suivi des dépenses  \n• Indicateurs par élève\n• Équilibre financier\n\nPosez-moi une question simple pour tester !";
    }

    // ------------------------------------------------------------------

    /** Comparaison brute, comme l'ancien code (les mots accentués ne correspondent donc jamais). */
    private static function contient(string $t, array $mots): bool
    {
        foreach ($mots as $m) {
            if (str_contains($t, $m)) {
                return true;
            }
        }
        return false;
    }

    private static function estDepense(string $t): bool
    {
        return str_contains($t, 'depense') || str_contains($t, 'charge') || str_contains($t, 'cout')
            || (str_contains($t, 'sorti') && str_contains($t, 'argent'));
    }

    /** Variante de la question sur les dépenses (null : aucune, comme l'ancien code). */
    private function sousRegleDepense(string $t): ?string
    {
        if ((str_contains($t, 'plus') && str_contains($t, 'grand')) || str_contains($t, 'max')) {
            return 'max';
        }
        if (str_contains($t, 'classement') || str_contains($t, 'top')) {
            return 'classement';
        }
        if (str_contains($t, 'tout') || str_contains($t, 'liste')) {
            return 'liste';
        }
        return null;
    }

    private function depenses(string $t): string
    {
        $year = ChatUtilesController::extractYearFromMessage($t);
        $centre = str_contains($t, 'centre');
        try {
            return match ($this->sousRegleDepense($t)) {
                'max' => $centre ? DepensesProfitsController::getBiggestCentre($year, 1) : DepensesProfitsController::getBiggestAffectation(1, $year, 1),
                'classement' => $centre ? DepensesProfitsController::getClassementCentre($year, 1, 5) : DepensesProfitsController::getBiggestAffectation(2, $year, 1),
                'liste' => $centre ? DepensesProfitsController::getClassementCentre($year, 1, 0) : DepensesProfitsController::getBiggestAffectation(3, $year, 1),
            };
        } catch (\Exception $e) {
            return "Désolé, une erreur s'est produite lors de l'accès aux données financières.";
        }
    }

    private function diagnostic(string $t): string
    {
        try {
            $year = ChatUtilesController::extractYearFromMessage($t) ?: date('Y');
            return DiagnosticController::getDiagnosticComplet("{$year}-01-01", "{$year}-12-31");
        } catch (\Exception $e) {
            return "Désolé, une erreur s'est produite lors de la génération du diagnostic financier.";
        }
    }
}
