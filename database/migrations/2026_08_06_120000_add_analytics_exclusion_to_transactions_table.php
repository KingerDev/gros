<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('excluded_from_analytics')->default(false)->after('note');
            $table->string('exclusion_reason')->nullable()->after('excluded_from_analytics');

            $table->index(['user_id', 'excluded_from_analytics']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'excluded_from_analytics']);
            $table->dropColumn(['excluded_from_analytics', 'exclusion_reason']);
        });
    }
};
