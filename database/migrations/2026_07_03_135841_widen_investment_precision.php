<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MODIFY je MySQL syntax; sqlite (testy) desatinné miesta nevynucuje
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Krypto (napr. BTC) má 8 desatinných miest — units na 8 miest, ceny tiež
        // (kvôli lacným tokenom).
        DB::statement('ALTER TABLE investments MODIFY units DECIMAL(28,8) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE investments MODIFY buy_price DECIMAL(24,8) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE investments MODIFY current_price DECIMAL(24,8) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE investment_transactions MODIFY units DECIMAL(28,8) NOT NULL');
        DB::statement('ALTER TABLE investment_transactions MODIFY price DECIMAL(24,8) NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE investments MODIFY units DECIMAL(18,6) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE investments MODIFY buy_price DECIMAL(16,4) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE investments MODIFY current_price DECIMAL(16,4) NOT NULL DEFAULT 0');
        DB::statement('ALTER TABLE investment_transactions MODIFY units DECIMAL(18,6) NOT NULL');
        DB::statement('ALTER TABLE investment_transactions MODIFY price DECIMAL(16,4) NOT NULL');
    }
};
