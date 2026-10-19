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
        // Приёмы регулярного назначения: время суток и сколько таблеток.
        Schema::create('prescription_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_id')->constrained();
            $table->string('time_of_day', 16);
            $table->decimal('tablets', 3, 1);
            $table->timestamps();

            $table->unique(['prescription_id', 'time_of_day']);
        });

        // Таблетка делится только пополам.
        DB::statement('ALTER TABLE prescription_intakes ADD CONSTRAINT prescription_intakes_tablets_check CHECK (tablets > 0 AND tablets * 2 = trunc(tablets * 2))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescription_intakes');
    }
};
