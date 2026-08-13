<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\InvestmentController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReserveController;
use App\Http\Controllers\RetirementController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\YoyController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : Inertia::render('Welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // Účty
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::delete('accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

    // Transakcie
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('transactions/suggest-category', [TransactionController::class, 'suggestCategory'])->name('transactions.suggest-category');
    Route::put('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::patch('transactions/{transaction}/exclusion', [TransactionController::class, 'exclusion'])->name('transactions.exclusion');
    Route::post('transactions/{transaction}/refunds', [TransactionController::class, 'storeRefund'])->name('transactions.refunds.store');
    Route::patch('transactions/{transaction}/refund-link', [TransactionController::class, 'refundLink'])->name('transactions.refund-link');
    Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');

    // Investície
    Route::get('investments', [InvestmentController::class, 'index'])->name('investments.index');
    Route::post('investments', [InvestmentController::class, 'store'])->name('investments.store');
    Route::post('investments/refresh', [InvestmentController::class, 'refresh'])->name('investments.refresh');
    Route::get('investments/history', [InvestmentController::class, 'history'])->name('investments.history');
    Route::get('investments/analytics', [InvestmentController::class, 'analytics'])->name('investments.analytics');
    Route::put('investments/{investment}', [InvestmentController::class, 'update'])->name('investments.update');
    Route::delete('investments/{investment}', [InvestmentController::class, 'destroy'])->name('investments.destroy');
    Route::get('investments/{investment}/price', [InvestmentController::class, 'historicalPrice'])->name('investments.price');
    Route::patch('investments/{investment}/contributing', [InvestmentController::class, 'contributing'])->name('investments.contributing');
    Route::post('investments/{investment}/lots', [InvestmentController::class, 'storeLot'])->name('investments.lots.store');
    Route::delete('investments/{investment}/lots/{lot}', [InvestmentController::class, 'destroyLot'])->name('investments.lots.destroy');

    // Asistent
    Route::get('assistant', [AssistantController::class, 'index'])->name('assistant.index');
    Route::get('assistant/{chat}', [AssistantController::class, 'index'])->name('assistant.chat');
    Route::post('assistant/send', [AssistantController::class, 'send'])->name('assistant.send');
    Route::get('assistant-briefing', [AssistantController::class, 'briefing'])->name('assistant.briefing');
    Route::delete('assistant/{chat}', [AssistantController::class, 'destroy'])->name('assistant.destroy');

    // Oplatí sa mi to?
    Route::get('purchase', [PurchaseController::class, 'index'])->name('purchase.index');
    Route::get('purchase/calculate', [PurchaseController::class, 'calculate'])->name('purchase.calculate');

    // Núdzový fond
    Route::get('reserve', [ReserveController::class, 'index'])->name('reserve.index');
    Route::post('reserve', [ReserveController::class, 'store'])->name('reserve.store');

    // Dôchodok
    Route::get('retirement', [RetirementController::class, 'index'])->name('retirement.index');
    Route::get('retirement/simulate', [RetirementController::class, 'simulate'])->name('retirement.simulate');
    Route::post('retirement', [RetirementController::class, 'store'])->name('retirement.store');

    // Predplatné
    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::put('subscriptions/{subscription}', [SubscriptionController::class, 'update'])->name('subscriptions.update');
    Route::delete('subscriptions/{subscription}', [SubscriptionController::class, 'destroy'])->name('subscriptions.destroy');

    // Úvery
    Route::get('loans', [LoanController::class, 'index'])->name('loans.index');
    Route::post('loans', [LoanController::class, 'store'])->name('loans.store');
    Route::put('loans/{loan}', [LoanController::class, 'update'])->name('loans.update');
    Route::delete('loans/{loan}', [LoanController::class, 'destroy'])->name('loans.destroy');

    // Sporiace ciele
    Route::post('goals', [GoalController::class, 'store'])->name('goals.store');
    Route::put('goals/{goal}', [GoalController::class, 'update'])->name('goals.update');
    Route::delete('goals/{goal}', [GoalController::class, 'destroy'])->name('goals.destroy');

    // Rozpočty
    Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::post('budgets', [BudgetController::class, 'store'])->name('budgets.store');
    Route::get('budgets/{budget}/transactions', [BudgetController::class, 'transactions'])->name('budgets.transactions');
    Route::put('budgets/{budget}', [BudgetController::class, 'update'])->name('budgets.update');
    Route::delete('budgets/{budget}', [BudgetController::class, 'destroy'])->name('budgets.destroy');

    // Medziročne
    Route::get('yoy', YoyController::class)->name('yoy.index');

    // Analýzy
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('analytics/category', [AnalyticsController::class, 'category'])->name('analytics.category');

    // Kategórie (správa)
    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
