<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Predvolený motor projekcie sa mení na najdlhší dostupný historický rad
 * (S&P 500 od 1985) a konzervatívna zrážka stúpa na 2,5 p.b.
 *
 * Dôvod: pôvodný predvolený rad (MSCI World, od 2009) začína krátko po dne
 * krízy a obsahuje takmer výhradne rast, takže dával ~12,7 % ročne. So zrážkou
 * 2,5 sedí nové nastavenie na dlhodobo merané svetové akcie — 5,2 % reálne
 * ročne za 125 rokov a 35 trhov (Dimson–Marsh–Staunton, UBS Yearbook).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('retire_engine', 20)->default('us_long')->change();
            $table->decimal('retire_haircut', 5, 2)->default(2.50)->change();
        });

        // Prepnú sa len tie účty, ktoré držia pôvodné predvolené hodnoty —
        // kto si motor či zrážku vedome zmenil, ostáva nedotknutý.
        DB::table('users')
            ->where('retire_engine', 'world')
            ->where('retire_haircut', 2.00)
            ->update(['retire_engine' => 'us_long', 'retire_haircut' => 2.50]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('retire_engine', 20)->default('world')->change();
            $table->decimal('retire_haircut', 5, 2)->default(2.00)->change();
        });

        DB::table('users')
            ->where('retire_engine', 'us_long')
            ->where('retire_haircut', 2.50)
            ->update(['retire_engine' => 'world', 'retire_haircut' => 2.00]);
    }
};
