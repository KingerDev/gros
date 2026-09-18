<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\EventService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class EventController extends Controller
{
    public function index(Request $request, EventService $events): Response
    {
        return Inertia::render('gros/Events', [
            'events' => $events->overview($request->user()),
        ]);
    }

    public function show(Request $request, Event $event, EventService $events): Response
    {
        abort_unless($event->user_id === $request->user()->id, 403);

        return Inertia::render('gros/EventDetail', [
            ...$events->detail($event),
            'accounts' => $request->user()->accounts()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $event = $request->user()->events()->create($this->validated($request));

        // rovno na detail — ďalší krok je priradiť transakcie
        return redirect()->route('events.show', $event)->with('success', 'Udalosť vytvorená.');
    }

    public function update(Request $request, Event $event): RedirectResponse
    {
        abort_unless($event->user_id === $request->user()->id, 403);

        $event->update($this->validated($request));

        return back()->with('success', 'Udalosť upravená.');
    }

    /** Zmaže udalosť; transakcie ostanú, len sa vrátia medzi bežné výdavky. */
    public function destroy(Request $request, Event $event): RedirectResponse
    {
        abort_unless($event->user_id === $request->user()->id, 403);

        $event->delete();

        return redirect()->route('events.index')->with('success', 'Udalosť zmazaná. Transakcie ostali.');
    }

    /** Výdavky za rozsah dátumov na výber do udalosti (JSON). */
    public function candidates(Request $request, Event $event, EventService $events): JsonResponse
    {
        abort_unless($event->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : CarbonImmutable::parse($event->starts_on);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : CarbonImmutable::parse($event->ends_on);

        return response()->json(['transactions' => $events->candidates($event, $from, $to)]);
    }

    /** Pridá a odoberie transakcie udalosti naraz (okno „Pridať transakcie"). */
    public function sync(Request $request, Event $event): RedirectResponse
    {
        abort_unless($event->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'add' => ['array'],
            'add.*' => ['integer'],
            'remove' => ['array'],
            'remove.*' => ['integer'],
        ]);

        $user = $request->user();

        // priradiť sa dá len výdavok; vrátenie ide so svojím nákupom
        $added = $user->transactions()
            ->whereIn('id', $data['add'] ?? [])
            ->where('type', 'expense')
            ->whereNull('refund_for_id')
            ->update(['event_id' => $event->id]);

        $removed = $user->transactions()
            ->whereIn('id', $data['remove'] ?? [])
            ->where('event_id', $event->id)
            ->update(['event_id' => null]);

        return back()->with('success', $this->syncMessage($added, $removed));
    }

    protected function syncMessage(int $added, int $removed): string
    {
        $parts = [];
        if ($added) {
            $parts[] = "pridané: $added";
        }
        if ($removed) {
            $parts[] = "odobrané: $removed";
        }

        return $parts ? 'Transakcie udalosti upravené ('.implode(', ', $parts).').' : 'Bez zmeny.';
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'icon' => ['nullable', 'string', 'max:16'],
            'note' => ['nullable', 'string', 'max:191'],
        ]);

        $data['budget'] = isset($data['budget']) && (float) $data['budget'] > 0 ? $data['budget'] : null;

        return $data;
    }
}
