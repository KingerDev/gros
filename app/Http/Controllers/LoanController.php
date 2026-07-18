<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LoanController extends Controller
{
    public function index(Request $request): Response
    {
        $loans = $request->user()->loans()->orderByDesc('balance')->get();

        return Inertia::render('gros/Loans', [
            'loans' => $loans,
            'accounts' => $request->user()->accounts()->orderBy('name')->get(['id', 'name']),
            'totals' => [
                'owed' => (float) $loans->where('kind', 'owe')->sum('balance'),
                'lent' => (float) $loans->where('kind', 'lent')->sum('balance'),
                'monthlyPayment' => (float) $loans->where('kind', 'owe')->sum('payment'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $count = $request->user()->loans()->count();
        $palette = config('gros.palette');
        $data['color'] = $data['kind'] === 'lent' ? '#2ba35a' : $palette[$count % count($palette)];

        $request->user()->loans()->create($data);

        return back()->with('success', 'Úver pridaný.');
    }

    public function update(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($loan->user_id === $request->user()->id, 403);

        $loan->update($this->validated($request));

        return back()->with('success', 'Úver upravený.');
    }

    public function destroy(Request $request, Loan $loan): RedirectResponse
    {
        abort_unless($loan->user_id === $request->user()->id, 403);

        $loan->delete();

        return back()->with('success', 'Úver zmazaný.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        $userId = $request->user()->id;

        return $request->validate([
            'kind' => ['required', Rule::in(['owe', 'lent'])],
            'name' => ['required', 'string', 'max:120'],
            'balance' => ['required', 'numeric', 'min:0'],
            'principal' => ['required', 'numeric', 'min:0'],
            'payment' => ['required', 'numeric', 'min:0'],
            'rate' => ['required', 'numeric', 'min:0'],
            'next_payment' => ['required', 'date'],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $userId)],
        ]);
    }
}
