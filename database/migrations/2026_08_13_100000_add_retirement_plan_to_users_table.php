<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Dôchodkový plán — vstupy pre Monte Carlo projekciu portfólia
            $table->smallInteger('retire_year')->nullable()->after('savings_goal');
            $table->decimal('retire_monthly', 12, 2)->default(0)->after('retire_year');
            $table->boolean('retire_index_contributions')->default(true)->after('retire_monthly');
            $table->decimal('retire_inflation', 5, 2)->nullable()->after('retire_index_contributions'); // null = historický priemer
            $table->decimal('retire_fees', 5, 2)->default(0.25)->after('retire_inflation');
            $table->decimal('retire_withdrawal', 5, 2)->default(4.00)->after('retire_fees');
            $table->string('retire_engine', 20)->default('world')->after('retire_withdrawal');
            $table->decimal('retire_target_income', 12, 2)->nullable()->after('retire_engine');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'retire_year', 'retire_monthly', 'retire_index_contributions', 'retire_inflation',
                'retire_fees', 'retire_withdrawal', 'retire_engine', 'retire_target_income',
            ]);
        });
    }
};
