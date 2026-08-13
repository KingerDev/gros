<?php

namespace App\Support;

use App\Models\User;

class DefaultCategories
{
    /**
     * Naseeduje default strom kategórií (z config/gros.php) používateľovi.
     * Nič nespraví, ak už nejaké kategórie má.
     */
    public static function seed(User $user): void
    {
        if ($user->categories()->exists()) {
            return;
        }

        $groupPos = 0;
        foreach (config('gros.default_categories', []) as $group) {
            $parent = $user->categories()->create([
                'parent_id' => null,
                'name' => $group['name'],
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
                    'name' => $child['name'],
                    'type' => $group['type'],
                    'is_savings' => $group['is_savings'] ?? false,
                    'color' => $child['color'] ?? $group['color'],
                    'icon' => $child['icon'] ?? null,
                    'position' => $childPos++,
                ]);
            }
        }
    }
}
