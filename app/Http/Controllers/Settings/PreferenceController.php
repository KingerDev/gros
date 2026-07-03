<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Preferences');
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'accent' => ['required', 'string', Rule::in(config('gros.accent_options'))],
            'show_decimals' => ['required', 'boolean'],
            'privacy_mode' => ['required', 'boolean'],
        ]);

        $request->user()->update($data);

        return back()->with('success', 'Nastavenia uložené.');
    }
}
