<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('income','expense','transfer') NOT NULL DEFAULT 'expense'");

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('to_account_id')->nullable()->after('account_id')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('to_account_id');
        });

        DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('income','expense') NOT NULL DEFAULT 'expense'");
    }
};
