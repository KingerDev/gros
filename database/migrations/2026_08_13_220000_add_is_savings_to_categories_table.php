<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kategórie, ktoré sú zaúčtované ako výdavok, ale nie sú spotreba — peniaze
 * poslané do portfólia. Doteraz sa rozpoznávali podľa názvu „Investície",
 * čo by po premenovaní kategórie ticho prestalo fungovať.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_savings')->default(false)->after('type');
        });

        // existujúce dáta: skupina „Investície" a všetko pod ňou
        $groups = DB::table('categories')
            ->whereRaw('LOWER(name) = ?', ['investície'])
            ->pluck('id');

        if ($groups->isNotEmpty()) {
            DB::table('categories')
                ->where(fn ($q) => $q->whereIn('id', $groups)->orWhereIn('parent_id', $groups))
                ->update(['is_savings' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_savings');
        });
    }
};
