<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['owe', 'lent'])->default('owe');
            $table->string('name');
            $table->decimal('balance', 14, 2)->default(0);
            $table->decimal('principal', 14, 2)->default(0);
            $table->decimal('payment', 12, 2)->default(0);
            $table->decimal('rate', 6, 2)->default(0);
            $table->date('next_payment');
            $table->string('color', 9)->default('#4c8dff');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loans');
    }
};
