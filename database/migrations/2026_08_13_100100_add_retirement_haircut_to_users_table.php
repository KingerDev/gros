<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Konzervatívna zrážka z historického výnosu (p.b. ročne). Historické
            // okná indexov sú mimoriadne priaznivé — zrážka simuluje horšie dekády.
            $table->decimal('retire_haircut', 5, 2)->default(2.00)->after('retire_fees');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('retire_haircut');
        });
    }
};
