<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Prijatá alebo vrátená pôžička nie je príjem — sú to tie isté peniaze, len
 * presunuté. Príznak is_savings („presun, nie spotreba ani príjem") ju vyradí
 * z príjmov a z miery úspor rovnako, ako vyraďuje vklady do portfólia.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('categories')
            ->where('type', 'income')
            ->where('name', 'Pôžičky, prenájom')
            ->update(['is_savings' => true]);
    }

    public function down(): void
    {
        DB::table('categories')
            ->where('type', 'income')
            ->where('name', 'Pôžičky, prenájom')
            ->update(['is_savings' => false]);
    }
};
