<?php

namespace App\Http\Controllers\Saisie;

use App\Http\Controllers\Controller;
use App\Services\Comptabilite\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Consultation de la piste d'audit comptable (lecture seule : aucune route
 * de modification ni de suppression).
 */
class AuditController extends Controller
{
    /**
     * GET /api/audit
     * Filtres : date_debut, date_fin, user_id, action, objet_type, objet_id, q ; per_page.
     */
    public function index(Request $request)
    {
        $request->validate([
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date',
            'user_id' => 'nullable|integer',
            'action' => 'nullable|string|max:50',
            'objet_type' => 'nullable|string|max:50',
            'objet_id' => 'nullable|integer',
            'q' => 'nullable|string|max:100',
            'per_page' => 'nullable|integer|min:10|max:200',
        ]);

        $query = DB::table('audit_comptable');

        if ($request->filled('date_debut')) {
            $query->where('date_action', '>=', $request->date_debut);
        }
        if ($request->filled('date_fin')) {
            $query->where('date_action', '<', \Carbon\Carbon::parse($request->date_fin)->addDay()->toDateString());
        }
        foreach (['user_id', 'action', 'objet_type', 'objet_id'] as $champ) {
            if ($request->filled($champ)) {
                $query->where($champ, $request->input($champ));
            }
        }
        if ($request->filled('q')) {
            $terme = '%' . $request->q . '%';
            $query->where(fn ($q) => $q->where('objet_libelle', 'ILIKE', $terme)
                ->orWhere('user_nom', 'ILIKE', $terme)
                ->orWhereRaw('details::text ILIKE ?', [$terme]));
        }

        $page = $query->orderByDesc('date_action')->orderByDesc('id')
            ->paginate($request->input('per_page', 50));

        $page->getCollection()->transform(fn ($e) => [
            'id' => $e->id,
            'date' => $e->date_action,
            'utilisateur' => $e->user_nom ?? 'Système',
            'action' => $e->action,
            'action_libelle' => Audit::LIBELLES[$e->action] ?? $e->action,
            'objet_type' => $e->objet_type,
            'objet_id' => $e->objet_id,
            'objet' => $e->objet_libelle,
            'avant' => $e->avant ? json_decode($e->avant, true) : null,
            'apres' => $e->apres ? json_decode($e->apres, true) : null,
            'details' => $e->details ? json_decode($e->details, true) : null,
            'ip' => $e->ip,
        ]);

        return response()->json($page);
    }

    /** GET /api/audit/options — valeurs des filtres (actions, utilisateurs). */
    public function options()
    {
        return response()->json([
            'actions' => collect(Audit::LIBELLES)->map(fn ($libelle, $code) => ['code' => $code, 'libelle' => $libelle])->values(),
            'utilisateurs' => DB::table('audit_comptable')
                ->whereNotNull('user_id')
                ->select('user_id as id', DB::raw('MAX(user_nom) as nom'))
                ->groupBy('user_id')->orderBy('nom')->get(),
        ]);
    }
}
