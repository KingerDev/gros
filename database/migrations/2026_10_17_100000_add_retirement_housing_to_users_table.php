<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Odhad nákladov na bývanie v budúcnosti (dnešné €/mes.). Kto dnes za
 * bývanie neplatí (študent u rodičov, na internáte), toho dnešná spotreba
 * o výdavkoch v dôchodku nehovorí — bez bývania je podhodnotená.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('retire_housing', 12, 2)->nullable()->after('retire_spending');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('retire_housing');
        });
    }
};
