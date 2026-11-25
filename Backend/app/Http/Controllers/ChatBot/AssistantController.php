<?php

namespace App\Http\Controllers\ChatBot;

use App\Http\Controllers\Controller;
use App\Models\ChatBot\ChatMessage;
use App\Services\Assistant\Assistant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Assistant (chatbot) — routes protégées par auth:sanctum.
 *   POST /api/chat/sending : assistant de l'application comptable
 *   POST /api/chat/send    : assistant du module d'analyse financière
 *   GET  /api/chat/history/{sessionId}
 */
class AssistantController extends Controller
{
    public function comptabilite(Request $request)
    {
        return $this->envoyer($request, Assistant::PROFIL_COMPTABILITE);
    }

    public function analyse(Request $request)
    {
        return $this->envoyer($request, Assistant::PROFIL_ANALYSE);
    }

    private function envoyer(Request $request, string $profil)
    {
        $data = $request->validate([
            'message' => 'required|string|max:500',
            'session_id' => 'required|string|max:100',
        ]);
        $user = Auth::user();

        $reponse = (new Assistant($profil))->repondre($data['message']);

        ChatMessage::create([
            'message' => $data['message'],
            'session_id' => $data['session_id'],
            'user_id' => $user->id,
            'response' => $reponse->texte,
            'liens' => $reponse->liens ?: null,
            'profil' => $profil,
        ]);

        return response()->json([
            'response' => $reponse->texte,
            'session_id' => $data['session_id'],
            'suggestions' => $reponse->suggestions,
            'liens' => $reponse->liens,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
        ]);
    }

    /** Historique d'une conversation : uniquement les messages de l'utilisateur connecté. */
    public function historique(string $sessionId)
    {
        return response()->json(ChatMessage::where('session_id', $sessionId)
            ->where('user_id', Auth::id())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'message', 'response', 'liens', 'created_at']));
    }

    /**
     * GET /api/chat/conversations — conversations de l'utilisateur, les plus
     * récentes d'abord, titrées par leur première question.
     */
    public function conversations()
    {
        $sessions = ChatMessage::where('user_id', Auth::id())
            ->where('profil', Assistant::PROFIL_COMPTABILITE)
            ->groupBy('session_id')
            ->selectRaw('session_id, MIN(id) as premier, MAX(created_at) as derniere, COUNT(*) as nb')
            ->orderByDesc('derniere')
            ->limit(100)
            ->get();
        $titres = ChatMessage::whereIn('id', $sessions->pluck('premier'))->pluck('message', 'id');

        return response()->json($sessions->map(fn ($s) => [
            'session_id' => $s->session_id,
            'titre' => mb_strimwidth((string) ($titres[$s->premier] ?? 'Conversation'), 0, 60, '…'),
            'date' => $s->derniere,
            'messages' => (int) $s->nb,
        ]));
    }

    /** DELETE /api/chat/conversations/{sessionId} — supprime une conversation de l'utilisateur. */
    public function supprimer(string $sessionId)
    {
        $n = ChatMessage::where('session_id', $sessionId)->where('user_id', Auth::id())->delete();
        return response()->json(['supprimes' => $n]);
    }
}
