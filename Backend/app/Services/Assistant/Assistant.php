<?php

namespace App\Services\Assistant;

use App\Services\Assistant\Analyse\RegleAnalyse;
use App\Services\Assistant\Comptabilite;
use Illuminate\Support\Facades\DB;

/**
 * Assistant (chatbot) sans IA : la question est analysée, chaque intention
 * calcule un score, la meilleure répond avec les vrais chiffres calculés par
 * les services comptables. Aucune donnée ne sort du serveur.
 *
 * Deux profils :
 *  - comptabilite : l'assistant de l'application comptable ;
 *  - analyse : le module d'analyse financière (réponses inchangées).
 */
class Assistant
{
    public const PROFIL_COMPTABILITE = 'comptabilite';
    public const PROFIL_ANALYSE = 'analyse';

    /** Score minimal pour considérer la question comprise. */
    private const SEUIL = 2.0;

    public function __construct(private string $profil = self::PROFIL_COMPTABILITE) {}

    /** @return Intention[] Par ordre de priorité en cas d'égalité */
    private function intentions(): array
    {
        if ($this->profil === self::PROFIL_ANALYSE) {
            return [
                new RegleAnalyse(RegleAnalyse::SALUTATION),
                new RegleAnalyse(RegleAnalyse::DEPENSES),
                new RegleAnalyse(RegleAnalyse::DIAGNOSTIC),
            ];
        }
        return [
            new Comptabilite\EtatPiece(),
            new Comptabilite\SoldeCompte(),
            new Comptabilite\MouvementsCompte(),
            new Comptabilite\PiecesAValider(),
            new Comptabilite\PreparationCloture(),
            new Comptabilite\Tresorerie(),
            new Comptabilite\Resultat(),
            new Comptabilite\Journaux(),
            new Comptabilite\RechercheCompte(),
            new Comptabilite\Aide(),
        ];
    }

    public function repondre(string $texte): Reponse
    {
        $question = (new Analyseur())->analyser($texte);

        $meilleure = null;
        $meilleurScore = 0.0;
        foreach ($this->intentions() as $intention) {
            $score = $intention->score($question);
            if ($score > $meilleurScore) {
                $meilleure = $intention;
                $meilleurScore = $score;
            }
        }

        if (!$meilleure || $meilleurScore < self::SEUIL) {
            return $this->incompris();
        }

        try {
            // Savepoint : une erreur SQL n'interrompt pas la transaction en cours
            return DB::transaction(fn () => $meilleure->repondre($question));
        } catch (\Throwable $e) {
            report($e);
            return new Reponse("Désolé, je n'ai pas pu calculer la réponse. Réessayez ou consultez directement l'écran concerné.",
                Comptabilite\Aide::EXEMPLES);
        }
    }

    /** Une intention précise, utilisée pour les tests. */
    public function intentionRetenue(string $texte): ?string
    {
        $question = (new Analyseur())->analyser($texte);
        $scores = [];
        foreach ($this->intentions() as $i) {
            $scores[] = [$i, $i->score($question)];
        }
        usort($scores, fn ($a, $b) => $b[1] <=> $a[1]);
        return ($scores[0][1] ?? 0) >= self::SEUIL ? class_basename($scores[0][0]) : null;
    }

    private function incompris(): Reponse
    {
        if ($this->profil === self::PROFIL_ANALYSE) {
            return new Reponse(RegleAnalyse::texteParDefaut(), RegleAnalyse::suggestions());
        }
        return new Reponse(
            "Je n'ai pas compris votre question. Voici des exemples de ce que je sais faire ; tapez « aide » pour la liste complète.",
            Comptabilite\Aide::EXEMPLES
        );
    }
}
