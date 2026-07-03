<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ticker');
            $table->string('name');
            $table->enum('kind', ['etf', 'stock', 'crypto'])->default('etf');
            $table->decimal('units', 18, 6)->default(0);
            $table->decimal('buy_price', 16, 4)->default(0);
            $table->decimal('current_price', 16, 4)->default(0);
            $table->string('color', 9)->default('#4c8dff');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investments');
    }
};
