<?php

namespace App\Http\Controllers;

use App\Services\FinanceService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class YoyController extends Controller
{
    public function __invoke(Request $request, FinanceService $finance): Response
    {
        return Inertia::render('gros/Yoy', [
            'years' => $finance->yearlyHistory($request->user()),
        ]);
    }
}
