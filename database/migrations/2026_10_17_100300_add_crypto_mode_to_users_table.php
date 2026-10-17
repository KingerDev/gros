<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ako sa v dôchodkovej projekcii počíta s kryptom. Scenáre 3/5/7 % platia
 * pre rozložené akcie — na krypto nie, preto o ňom rozhoduje používateľ:
 * nerátať, počítať s dnešnou hodnotou, alebo ako s akciami.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('retire_crypto_mode', 10)->nullable()->after('retire_contribution');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('retire_crypto_mode');
        });
    }
};
