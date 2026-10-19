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
        Schema::table('prescription_intakes', function (Blueprint $table) {
            // Время приёма, которое выбрал пациент: местное, по его часовому поясу.
            $table->time('time')->nullable();
            // Когда время выбрано. Плановые приёмы на более ранние моменты не создаются.
            $table->timestamp('time_set_at')->nullable();
        });

        DB::statement('ALTER TABLE prescription_intakes ADD CONSTRAINT prescription_intakes_time_check CHECK ((time IS NULL) = (time_set_at IS NULL))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE prescription_intakes DROP CONSTRAINT prescription_intakes_time_check');

        Schema::table('prescription_intakes', function (Blueprint $table) {
            $table->dropColumn(['time', 'time_set_at']);
        });
    }
};
