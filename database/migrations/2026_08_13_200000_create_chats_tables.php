<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 191)->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_message_at']);
        });

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            // user | assistant | tool — presne ako to očakáva rozhranie modelu
            $table->string('role', 16);
            $table->longText('content')->nullable();
            // volania nástrojov a ich výsledky, aby sa dala konverzácia prehrať
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id', 64)->nullable();
            $table->string('name', 64)->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chats');
    }
};
