<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->string('quote_symbol')->nullable()->after('kind');
            $table->enum('quote_source', ['manual', 'yahoo', 'coingecko'])->default('manual')->after('quote_symbol');
            $table->timestamp('last_price_at')->nullable()->after('current_price');
        });
    }

    public function down(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->dropColumn(['quote_symbol', 'quote_source', 'last_price_at']);
        });
    }
};
