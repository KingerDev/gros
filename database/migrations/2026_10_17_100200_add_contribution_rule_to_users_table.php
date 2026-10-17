<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vklad ako pravidlo („25 % z príjmu po povinných platbách"), voliteľne iné
 * od roku, keď sa zmení príjem — a rok narodenia, z ktorého vychádza dĺžka
 * dôchodku a s ňou odporúčaná miera výberu.
 *
 * Miera výberu je odteraz nepovinná: prázdna = odporúčaná podľa dĺžky
 * dôchodku. Doterajšie východiskových 4 % sa preto vyprázdnia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('retire_contribution')->nullable()->after('retire_monthly');
            $table->smallInteger('retire_birth_year')->nullable()->after('retire_year');
            $table->decimal('retire_withdrawal', 5, 2)->nullable()->default(null)->change();
        });

        DB::table('users')->where('retire_withdrawal', 4.00)->update(['retire_withdrawal' => null]);
    }

    public function down(): void
    {
        DB::table('users')->whereNull('retire_withdrawal')->update(['retire_withdrawal' => 4.00]);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['retire_contribution', 'retire_birth_year']);
            $table->decimal('retire_withdrawal', 5, 2)->default(4.00)->change();
        });
    }
};
