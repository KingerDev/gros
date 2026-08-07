<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Vrátenie tovaru: príjem spárovaný s pôvodným výdavkom.
            // Zmazanie pôvodného výdavku vrátenie nezmaže — ostane ako bežný príjem.
            $table->foreignId('refund_for_id')->nullable()->after('to_account_id')->constrained('transactions')->nullOnDelete();

            // Denormalizovaný súčet vrátených súm; drží sa na pôvodnom výdavku,
            // aby sa dala čistá suma (amount − refunded_amount) rátať priamo v SQL.
            $table->decimal('refunded_amount', 14, 2)->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refund_for_id');
            $table->dropColumn('refunded_amount');
        });
    }
};
