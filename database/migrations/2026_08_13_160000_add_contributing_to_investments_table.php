<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            // false = do pozície sa už neprispieva, takže chýbajúce nákupy
            // nie sú chyba a netreba na ne upozorňovať
            $table->boolean('contributing')->default(true)->after('quote_source');
        });
    }

    public function down(): void
    {
        Schema::table('investments', function (Blueprint $table) {
            $table->dropColumn('contributing');
        });
    }
};
