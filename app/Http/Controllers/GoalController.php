<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->user()->goals()->create($this->validated($request));

        return back()->with('success', 'Cieľ pridaný.');
    }

    public function update(Request $request, Goal $goal): RedirectResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $goal->update($this->validated($request));

        return back()->with('success', 'Cieľ upravený.');
    }

    public function destroy(Request $request, Goal $goal): RedirectResponse
    {
        abort_unless($goal->user_id === $request->user()->id, 403);

        $goal->delete();

        return back()->with('success', 'Cieľ zmazaný.');
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'target_amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'saved_amount' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'deadline' => ['nullable', 'date'],
        ]);
    }
}
