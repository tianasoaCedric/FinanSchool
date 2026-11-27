<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\exercice\ExerciceComptable;
use App\Models\Saisie\LigneEcriture; // ← Adapter le namespace si besoin
use App\Models\notifications\Evenement;
use App\Models\notifications\Notification;
use App\Events\NotificationCreee;
use App\Services\Parametres;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class NotifClotureExerciceProche extends Command
{
    protected $signature = 'exercice:cloture-alertes';
    protected $description = "Alerte à l'approche de la clôture de l'exercice comptable (1 mois, 1 semaine, 1 jour avant) si des écritures à valider existent";

    public function handle()
    {
        if (!Parametres::get('notifications.cloture_active')) {
            $this->info('Alertes de clôture désactivées dans les paramètres.');
            return 0;
        }

        // Récupérer tous les exercices ouverts
        $exercices = ExerciceComptable::where('Statut', 'OUVERT')->get();
        $now = Carbon::now();

        // Jalons (jours avant la clôture) définis dans les paramètres ;
        // l'urgence croît à mesure que la clôture approche (1 = faible, 3 = forte).
        $jours = Parametres::get('notifications.cloture_jalons');
        rsort($jours);
        $jalons = array_map(fn ($d, $i) => [
            'diff' => $d,
            'message' => match (true) {
                $d === 1 => 'demain',
                $d === 7 => 'dans une semaine',
                $d === 30 => 'dans un mois',
                default => "dans {$d} jours",
            },
            'urgence' => min(3, max(1, 3 - (count($jours) - 1 - $i))),
        ], $jours, array_keys($jours));

        foreach ($exercices as $exercice) {
            $dateCloture = Carbon::parse($exercice->Date_fin);

            foreach ($jalons as $j) {
                // Vérifier si aujourd'hui correspond au jalon
                if ($now->toDateString() === $dateCloture->copy()->subDays($j['diff'])->toDateString()) {

                    // Sélectionne les lignes d'écriture liées à un mouvement entre Date_debut et Date_fin de l'exercice
                    $nbEcrituresAValider = DB::table('ligne_ecritures')
                        ->join('mouvement_ecritures', 'ligne_ecritures.Id_Mouvement_ecriture', '=', 'mouvement_ecritures.Id_Mouvement_ecriture')
                        ->whereBetween('mouvement_ecritures.Date_mouvement', [
                            $exercice->Date_debut, $exercice->Date_fin
                        ])
                        ->where('ligne_ecritures.statut', 'brouillon')
                        ->whereNull('ligne_ecritures.date_validation')
                        ->count();

                    if ($nbEcrituresAValider > 0) {
                        // Créer l'événement
                        $evenement = Evenement::create([
                            'type_evenement_id' => 5, // ← À adapter selon ta nomenclature
                            'donnees_evenement' => [
                                'nombre_ecritures' => $nbEcrituresAValider,
                                'date_cloture' => $dateCloture->toDateString(),
                                'exercice_id' => $exercice->Id_Exercice_comptable,
                                'annee_fiscale' => $exercice->Annee_fiscale,
                                'lien_redirection' => '/validation'
                            ]
                        ]);

                        // Créer la notification
                        $notification = Notification::create([
                            'evenement_id' => $evenement->id,
                            'niveau_urgence_id' => $j['urgence'],
                            'titre' => 'Clôture exercice comptable imminente',
                            'message' => "L'exercice {$exercice->Annee_fiscale} sera clôturé {$j['message']} ({$dateCloture->format('d/m/Y')}). {$nbEcrituresAValider} écriture(s) à valider.",
                            'statut' => 'non_lu'
                        ]);

                        event(new NotificationCreee($notification));
                        $this->info("Notification envoyée : Exercice {$exercice->Annee_fiscale} ({$j['message']}) - {$nbEcrituresAValider} écriture(s) à valider");
                    } else {
                        $this->info("Aucune écriture à valider pour l'exercice {$exercice->Annee_fiscale} ({$j['message']})");
                    }
                }
            }
        }

        return 0;
    }
}
