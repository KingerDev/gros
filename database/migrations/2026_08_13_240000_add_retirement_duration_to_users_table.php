<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ako dlho má dôchodok trvať. Bez toho sa nedá odpovedať na otázku „vydrží
 * mi to" — a práve dĺžka rozhoduje o tom, či je miera výberu bezpečná.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->smallInteger('retire_duration')->default(35)->after('retire_year');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('retire_duration');
        });
    }
};
