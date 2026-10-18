<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobilná appka v App Store: jazyk používateľa (push notifikácie, kategórie),
 * Sign in with Apple a účty bez hesla (len cez Apple).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // existujúci používatelia zostávajú po slovensky
            $table->string('locale', 5)->default('sk')->after('email');
            $table->string('apple_id')->nullable()->unique()->after('locale'); // `sub` z Apple identity tokenu
            $table->text('apple_refresh_token')->nullable()->after('apple_id'); // šifrovaný, na revoke pri zmazaní účtu
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['apple_id']);
            $table->dropColumn(['locale', 'apple_id', 'apple_refresh_token']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
