<?php

namespace App\Http\Controllers;

use App\Models\Chat;
use App\Services\Ai\Assistant;
use App\Services\Ai\MonthlyBriefing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AssistantController extends Controller
{
    public function index(Request $request, ?Chat $chat = null): Response
    {
        $user = $request->user();

        if ($chat) {
            abort_unless($chat->user_id === $user->id, 403);
        }

        return Inertia::render('gros/Assistant', [
            'chats' => $user->chats()->orderByDesc('last_message_at')->orderByDesc('id')->limit(50)
                ->get(['id', 'title', 'last_message_at'])
                ->map(fn (Chat $c) => [
                    'id' => $c->id,
                    'title' => $c->title ?? 'Nový chat',
                    'at' => $c->last_message_at?->toIso8601String(),
                ]),
            'chat' => $chat ? ['id' => $chat->id, 'title' => $chat->title] : null,
            'messages' => $chat ? $this->visible($chat) : [],
            'configured' => (bool) config('services.openai.key'),
            // otázka predvyplnená z inej stránky — pošle sa hneď po otvorení
            'prefill' => $request->filled('q') ? mb_strimwidth((string) $request->query('q'), 0, 2000) : null,
            'suggestions' => [
                'Prečo som tento mesiac minul viac ako minulý?',
                'Na čom by som vedel najviac ušetriť?',
                'Ako som na tom s investíciami?',
                'Koľko mi reálne mesačne ostáva?',
            ],
        ]);
    }

    /** Pošle otázku a vráti celú aktualizovanú konverzáciu. */
    public function send(Request $request, Assistant $assistant): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'chat_id' => ['nullable', 'integer'],
        ]);

        $user = $request->user();

        $chat = ! empty($data['chat_id'])
            ? $user->chats()->findOrFail($data['chat_id'])
            : $user->chats()->create(['last_message_at' => now()]);

        try {
            $assistant->ask($chat, $data['message']);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'chat_id' => $chat->id,
                'messages' => $this->visible($chat),
                'error' => $e->getMessage(),
            ], 200);
        }

        return response()->json([
            'ok' => true,
            'chat_id' => $chat->id,
            'title' => $chat->fresh()->title,
            'messages' => $this->visible($chat),
        ]);
    }

    /** Mesačný komentár pre prehľad (JSON, lazy — nesmie brzdiť načítanie stránky). */
    public function briefing(Request $request, MonthlyBriefing $briefing): JsonResponse
    {
        return response()->json($briefing->forUser($request->user()));
    }

    public function destroy(Request $request, Chat $chat): RedirectResponse
    {
        abort_unless($chat->user_id === $request->user()->id, 403);
        $chat->delete();

        return redirect('/assistant')->with('success', 'Chat zmazaný.');
    }

    /**
     * Správy pre zobrazenie: technické volania nástrojov sa neukazujú ako
     * bubliny, len ako poznámka, z čoho asistent čerpal.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function visible(Chat $chat): array
    {
        $out = [];
        $tools = [];

        foreach ($chat->messages()->get() as $m) {
            if ($m->role === 'tool') {
                $tools[] = $m->name;

                continue;
            }
            if ($m->role === 'assistant' && ! $m->content) {
                continue; // správa, ktorá len volala nástroje
            }

            $out[] = [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'tools' => $m->role === 'assistant' ? array_values(array_unique($tools)) : [],
                'at' => $m->created_at?->toIso8601String(),
            ];

            if ($m->role === 'assistant') {
                $tools = [];
            }
        }

        return $out;
    }
}
