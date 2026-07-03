<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('investment_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['buy', 'sell'])->default('buy');
            $table->decimal('units', 18, 6);
            $table->decimal('price', 16, 4); // cena za kus v EUR
            $table->date('date');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['investment_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_transactions');
    }
};
