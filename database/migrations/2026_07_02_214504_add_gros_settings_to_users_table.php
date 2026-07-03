<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('accent', 9)->default('#6c5ce7');
            $table->boolean('show_decimals')->default(true);
            $table->boolean('privacy_mode')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['accent', 'show_decimals', 'privacy_mode']);
        });
    }
};
