<?php

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
            ],
            'settings' => $user ? [
                'accent' => $user->accent,
                'showDecimals' => (bool) $user->show_decimals,
                'privacyMode' => (bool) $user->privacy_mode,
            ] : null,
            'summary' => $user ? $this->summary($user) : null,
            'categories' => $user ? $this->categories($user) : [],
            'recentCategoryIds' => $user ? $this->recentCategoryIds($user) : [],
            // POZOR: nesmie sa volať 'ref' — Inertia spreadne page-propy na vnode
            // stránky a Vue by 'ref' vyložil ako template ref (crash v prode).
            'catalog' => [
                'kindLabels' => config('gros.kind_labels'),
                'palette' => config('gros.palette'),
                'accentOptions' => config('gros.accent_options'),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /**
     * Lightweight net-worth summary shown in the sidebar on every page.
     *
     * @return array<string, mixed>
     */
    protected function summary(User $user): array
    {
        $cash = (float) $user->accounts()->sum('balance');

        $portfolio = 0.0;
        foreach ($user->investments()->get(['units', 'current_price']) as $inv) {
            $portfolio += (float) $inv->units * (float) $inv->current_price;
        }

        return [
            'cash' => $cash,
            'portfolio' => $portfolio,
            'netWorth' => $cash + $portfolio,
            'accountCount' => $user->accounts()->count(),
        ];
    }

    /**
     * Plochý zoznam kategórií používateľa (frontend si z neho postaví strom
     * aj mapu id → {name, color, icon} na zobrazovanie).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function categories(User $user): array
    {
        return $user->categories()
            ->orderBy('position')
            ->orderBy('name')
            ->get(['id', 'parent_id', 'name', 'type', 'color', 'icon'])
            ->toArray();
    }

    /**
     * ID naposledy použitých kategórií (na rýchly výber pri zápise transakcie).
     *
     * @return array<int, int>
     */
    protected function recentCategoryIds(User $user): array
    {
        return $user->transactions()
            ->whereNotNull('category_id')
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(40)
            ->pluck('category_id')
            ->unique()
            ->take(8)
            ->values()
            ->all();
    }
}
