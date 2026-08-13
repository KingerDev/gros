<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nastavenie núdzového fondu: rizikový profil, zdroj rezervy,
            // vlastný výber nevyhnutných kategórií. Blob, lebo je to čisto
            // konfigurácia jednej stránky — nič sa cez to nefiltruje.
            $table->json('reserve_profile')->nullable()->after('retire_spending');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('reserve_profile');
        });
    }
};
