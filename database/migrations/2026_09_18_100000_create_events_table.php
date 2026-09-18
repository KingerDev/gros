<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Udalosti — dovolenka, svadba, sťahovanie. Druhá os popri kategórii:
 * výdavok ostáva „Reštaurácie", len navyše patrí do „Dublin 2026". Vďaka
 * tomu má udalosť vlastný súčet a projekcie ju neberú ako bežné míňanie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('budget', 12, 2)->nullable();
            $table->string('color', 9)->default('#22b8cf');
            $table->string('icon', 16)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'starts_on']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('event_id')->nullable()->after('category_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
        });

        Schema::dropIfExists('events');
    }
};
