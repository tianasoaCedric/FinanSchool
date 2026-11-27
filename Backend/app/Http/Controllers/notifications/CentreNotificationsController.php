<?php

namespace App\Http\Controllers\notifications;

use App\Http\Controllers\Controller;
use App\Models\notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Centre de notifications de l'interface comptable (en-tête + page dédiée).
 *
 * Routes distinctes de /notifications (utilisées telles quelles par le module
 * d'analyse) : filtres, pagination, compteurs, catégorie et lien interne
 * calculés côté serveur, actions groupées.
 */
class CentreNotificationsController extends Controller
{
    /** Catégories affichées (icône et libellé côté interface). */
    private const CATEGORIES = ['import', 'cloture', 'analyse', 'systeme'];

    /** Anciens liens absolus → routes de l'application. */
    private const LIENS_CORRIGES = [
        '/ecritures-a-valider' => '/validation',
    ];

    public function index(Request $request)
    {
        $request->validate([
            'statut' => 'nullable|in:tous,non_lu,lu',
            'niveau' => 'nullable|in:info,avertissement,urgent',
            'categorie' => 'nullable|in:' . implode(',', self::CATEGORIES),
            'q' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:5|max:100',
        ]);

        $page = $this->requete($request)
            ->orderByDesc('notifications.created_at')
            ->orderByDesc('notifications.id')
            ->paginate($request->input('per_page', 20));
        $page->getCollection()->transform(fn ($n) => $this->presenter($n));

        $nonLues = fn () => $this->base()->whereNull('nu.lu_a');
        return response()->json($page->toArray() + [
            'compteurs' => [
                'tous' => $this->base()->count(),
                'non_lu' => $nonLues()->count(),
                'urgent_non_lu' => $nonLues()
                    ->whereHas('niveauUrgence', fn ($q) => $q->where('code', 'urgent'))->count(),
            ],
        ]);
    }

    public function marquerLue(int $id)
    {
        $this->etat($id, ['lu_a' => now()]);
        return response()->json(['message' => 'Notification marquée comme lue.']);
    }

    public function marquerNonLue(int $id)
    {
        $this->etat($id, ['lu_a' => null]);
        return response()->json(['message' => 'Notification marquée comme non lue.']);
    }

    public function toutLire()
    {
        $ids = $this->base()->whereNull('nu.lu_a')->pluck('notifications.id');
        $this->etatMasse($ids, ['lu_a' => now()]);
        $n = $ids->count();
        return response()->json(['message' => $n ? "{$n} notification(s) marquée(s) comme lue(s)." : 'Aucune notification non lue.']);
    }

    public function supprimer(int $id)
    {
        $this->etat($id, ['supprime_a' => now()]);
        return response()->json(['message' => 'Notification supprimée.']);
    }

    public function supprimerLues()
    {
        $ids = $this->base()->whereNotNull('nu.lu_a')->pluck('notifications.id');
        $this->etatMasse($ids, ['supprime_a' => now()]);
        $n = $ids->count();
        return response()->json(['message' => $n ? "{$n} notification(s) lue(s) supprimée(s)." : 'Aucune notification lue à supprimer.']);
    }

    // ------------------------------------------------------------------

    /**
     * Notifications visibles par l'utilisateur connecté, avec son état propre
     * (table notification_utilisateur, alias « nu » ; pas de ligne = non lue).
     */
    private function base()
    {
        $userId = auth()->id();
        return Notification::query()
            ->leftJoin('notification_utilisateur as nu', function ($j) use ($userId) {
                $j->on('nu.notification_id', '=', 'notifications.id')->where('nu.user_id', '=', $userId);
            })
            ->whereNull('nu.supprime_a')
            ->select('notifications.*', 'nu.lu_a as lu_utilisateur_a');
    }

    /** Met à jour l'état de l'utilisateur connecté pour une notification visible. */
    private function etat(int $id, array $valeurs): void
    {
        $this->base()->where('notifications.id', $id)->firstOrFail();
        $this->etatMasse(collect([$id]), $valeurs);
    }

    private function etatMasse($ids, array $valeurs): void
    {
        if ($ids->isEmpty()) {
            return;
        }
        $now = now();
        $userId = auth()->id();
        DB::table('notification_utilisateur')->upsert(
            $ids->map(fn ($id) => array_merge(['notification_id' => $id, 'user_id' => $userId,
                'lu_a' => null, 'supprime_a' => null, 'created_at' => $now, 'updated_at' => $now], $valeurs))->all(),
            ['notification_id', 'user_id'],
            array_merge(array_keys($valeurs), ['updated_at'])
        );
    }

    private function requete(Request $request)
    {
        $q = $this->base()->with(['niveauUrgence', 'evenement']);
        $statut = $request->input('statut', 'tous');
        if ($statut === 'lu') {
            $q->whereNotNull('nu.lu_a');
        } elseif ($statut === 'non_lu') {
            $q->whereNull('nu.lu_a');
        }
        if ($request->filled('niveau')) {
            $q->whereHas('niveauUrgence', fn ($n) => $n->where('code', $request->niveau));
        }
        if ($request->filled('q')) {
            $t = '%' . $request->q . '%';
            $q->where(fn ($w) => $w->where('titre', 'ILIKE', $t)->orWhere('message', 'ILIKE', $t));
        }
        if ($request->filled('categorie')) {
            // La catégorie se déduit des données de l'événement (clé caractéristique)
            $cle = match ($request->categorie) {
                'import' => 'nom_fichier',
                'cloture' => 'date_cloture',
                'analyse' => 'lien_redirection',
                default => null,
            };
            $q->whereHas('evenement', function ($e) use ($cle, $request) {
                if ($request->categorie === 'analyse') {
                    $e->whereRaw("donnees_evenement::jsonb ->> 'lien_redirection' LIKE '%non-affected%'");
                } elseif ($cle) {
                    // jsonb_exists() plutôt que l'opérateur « ? », pris pour un paramètre par PDO
                    $e->whereRaw('jsonb_exists(donnees_evenement::jsonb, ?)', [$cle]);
                } else {
                    $e->whereRaw("NOT jsonb_exists_any(donnees_evenement::jsonb, array['nom_fichier','date_cloture'])")
                      ->whereRaw("coalesce(donnees_evenement::jsonb ->> 'lien_redirection', '') NOT LIKE '%non-affected%'");
                }
            });
        }
        return $q;
    }

    private function presenter(Notification $n): array
    {
        $d = $n->evenement?->donnees_evenement ?? [];
        $categorie = match (true) {
            isset($d['nom_fichier']) => 'import',
            isset($d['date_cloture']) => 'cloture',
            str_contains((string) ($d['lien_redirection'] ?? ''), 'non-affected') => 'analyse',
            default => 'systeme',
        };

        return [
            'id' => $n->id,
            'titre' => $n->titre,
            'message' => $n->message,
            'statut' => $n->lu_utilisateur_a ? 'lu' : 'non_lu',
            'lu_a' => $n->lu_utilisateur_a ? \Illuminate\Support\Carbon::parse($n->lu_utilisateur_a)->toIso8601String() : null,
            'date' => $n->created_at?->toIso8601String(),
            'niveau' => $n->niveauUrgence->code ?? 'info',
            'niveau_libelle' => $n->niveauUrgence->nom ?? 'Information',
            'categorie' => $categorie,
            'lien' => $this->lien($categorie, $d),
            'details' => array_intersect_key($d, array_flip(['nom_fichier', 'nombre_mouvements', 'nombre_lignes', 'erreur', 'nombre_ecritures', 'date_cloture', 'annee_fiscale'])),
        ];
    }

    /** Lien interne (chemin de l'application) ou null. */
    private function lien(string $categorie, array $d): ?string
    {
        if ($categorie === 'import') {
            return '/import';
        }
        $url = (string) ($d['lien_redirection'] ?? '');
        if ($url === '') {
            return $categorie === 'cloture' ? '/validation' : null;
        }
        $chemin = parse_url($url, PHP_URL_PATH) ?: $url;
        return self::LIENS_CORRIGES[$chemin] ?? $chemin;
    }
}
