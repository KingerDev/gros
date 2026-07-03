<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $groups = $user->categories()
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('type')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        // Počet transakcií na kategóriu (na varovanie pri mazaní)
        $counts = $user->transactions()
            ->selectRaw('category_id, count(*) as c')
            ->groupBy('category_id')
            ->pluck('c', 'category_id');

        return Inertia::render('gros/Categories', [
            'groups' => $groups,
            'txnCounts' => $counts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(['income', 'expense'])],
            'color' => ['required', 'string', 'max:9'],
            'icon' => ['nullable', 'string', 'max:16'],
            'parent_id' => ['nullable', Rule::exists('categories', 'id')->where('user_id', $user->id)->whereNull('parent_id')],
        ]);

        // Ak je to podkategória, zdedí typ rodiča
        if (! empty($data['parent_id'])) {
            $parent = $user->categories()->findOrFail($data['parent_id']);
            $data['type'] = $parent->type;
        }

        $data['position'] = ($user->categories()->where('parent_id', $data['parent_id'] ?? null)->max('position') ?? -1) + 1;

        $user->categories()->create($data);

        return back()->with('success', 'Kategória pridaná.');
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        abort_unless($category->user_id === $request->user()->id, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['required', 'string', 'max:9'],
            'icon' => ['nullable', 'string', 'max:16'],
        ]);

        $category->update($data);

        return back()->with('success', 'Kategória upravená.');
    }

    public function destroy(Request $request, Category $category): RedirectResponse
    {
        abort_unless($category->user_id === $request->user()->id, 403);

        // Deti sa zmažú kaskádovo, transakciám sa category_id nastaví na null.
        $category->delete();

        return back()->with('success', 'Kategória zmazaná.');
    }
}
