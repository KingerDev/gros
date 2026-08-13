<?php

namespace App\Http\Controllers;

use App\Models\Subscription;
use App\Services\OpportunityCostService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(Request $request, OpportunityCostService $cost): Response
    {
        $user = $request->user();
        $subs = $user->subscriptions()->orderBy('next_payment')->get();

        $monthly = 0.0;
        $yearly = 0.0;
        foreach ($subs as $s) {
            $monthly += $s->monthly_amount;
            $yearly += $s->cycle === 'yearly' ? (float) $s->amount : (float) $s->amount * 12;
        }

        $ctx = $cost->context($user);

        return Inertia::render('gros/Subscriptions', [
            'subscriptions' => $subs,
            'accounts' => $user->accounts()->orderBy('name')->get(['id', 'name']),
            'totals' => [
                'monthly' => $monthly,
                'yearly' => $yearly,
                'count' => $subs->count(),
            ],
            // čím by tie isté peniaze boli, keby šli do portfólia
            'opportunity' => [
                'context' => $ctx,
                'per_subscription' => $cost->subscriptions($user),
                'total' => $cost->fromMonthly($monthly, $ctx['years'], $ctx['real_return']),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $count = $request->user()->subscriptions()->count();
        $palette = config('gros.palette');
        $data['color'] = $palette[$count % count($palette)];

        $request->user()->subscriptions()->create($data);

        return back()->with('success', 'Predplatné pridané.');
    }

    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);

        $subscription->update($this->validated($request));

        return back()->with('success', 'Predplatné upravené.');
    }

    public function destroy(Request $request, Subscription $subscription): RedirectResponse
    {
        abort_unless($subscription->user_id === $request->user()->id, 403);

        $subscription->delete();

        return back()->with('success', 'Predplatné zrušené.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $userId = $request->user()->id;

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'cycle' => ['required', Rule::in(['monthly', 'yearly'])],
            'next_payment' => ['required', 'date'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $userId)],
        ]);
    }
}
