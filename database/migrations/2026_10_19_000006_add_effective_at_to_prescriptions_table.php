<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            // С какого момента действует версия: назначение, изменение или правка ещё не начавшегося
            // назначения. Плановые приёмы версии на более ранние моменты не создаются.
            $table->timestamp('effective_at')->nullable();
        });

        DB::statement('UPDATE prescriptions SET effective_at = created_at');
        DB::statement('ALTER TABLE prescriptions ALTER COLUMN effective_at SET NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn('effective_at');
        });
    }
};
