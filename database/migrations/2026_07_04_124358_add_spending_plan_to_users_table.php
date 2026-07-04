<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Mesačný plán míňania (safe-to-spend)
            $table->decimal('monthly_income', 12, 2)->nullable()->after('password');
            $table->decimal('savings_goal', 12, 2)->default(0)->after('monthly_income');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['monthly_income', 'savings_goal']);
        });
    }
};
