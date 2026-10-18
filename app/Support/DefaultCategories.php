<?php

namespace App\Support;

use App\Models\User;

class DefaultCategories
{
    /**
     * Naseeduje default strom kategórií (z config/gros.php) používateľovi.
     * Nič nespraví, ak už nejaké kategórie má. Názvy v jazyku používateľa
     * (users.locale) — preklady v lang/en/categories.php.
     */
    public static function seed(User $user): void
    {
        if ($user->categories()->exists()) {
            return;
        }

        $locale = $user->locale ?: 'sk';
        $names = $locale === 'sk' ? [] : trans('categories', [], $locale);
        $name = fn (string $sk) => is_array($names) ? ($names[$sk] ?? $sk) : $sk;

        $groupPos = 0;
        foreach (config('gros.default_categories', []) as $group) {
            $parent = $user->categories()->create([
                'parent_id' => null,
                'name' => $name($group['name']),
                'type' => $group['type'],
                // presuny do portfólia nie sú spotreba — pozri ExpenseClassifier
                'is_savings' => $group['is_savings'] ?? false,
                'color' => $group['color'],
                'icon' => $group['icon'] ?? null,
                'position' => $groupPos++,
            ]);

            $childPos = 0;
            foreach ($group['children'] ?? [] as $child) {
                $user->categories()->create([
                    'parent_id' => $parent->id,
                    'name' => $name($child['name']),
                    'type' => $group['type'],
                    'is_savings' => $child['is_savings'] ?? $group['is_savings'] ?? false,
                    'color' => $child['color'] ?? $group['color'],
                    'icon' => $child['icon'] ?? null,
                    'position' => $childPos++,
                ]);
            }
        }
    }
}
