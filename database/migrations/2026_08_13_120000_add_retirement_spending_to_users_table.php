<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Mesačné výdavky v dôchodku (dnešné €). null = merať z transakcií.
            $table->decimal('retire_spending', 12, 2)->nullable()->after('retire_target_income');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('retire_spending');
        });
    }
};
