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
        // Шкала врача может быть инструментом одного вида врача: HARS заполняет психиатр, HADS —
        // терапевт. Пусто — любой врач. У шкалы с подшкалами (HADS, тревога и депрессия) степени
        // свои у каждой подшкалы, а у вопроса могут быть свои варианты ответа (они в items).
        Schema::table('scales', function (Blueprint $table) {
            $table->string('doctor_kind', 16)->nullable();
            // Подшкалы {code, name, bands}; json, а не jsonb — как остальные поля шкалы.
            $table->json('subscales')->nullable();
            // Строка над вопросами для того, кто отвечает: HADS отвечает сам пациент на экране врача.
            $table->text('prompt')->nullable();
        });

        DB::statement("ALTER TABLE scales ADD CONSTRAINT scales_doctor_kind_check CHECK (doctor_kind IS NULL OR (rater = 'doctor' AND doctor_kind IN ('psychiatrist', 'therapist')))");

        // Суммы и степени подшкал на момент заполнения {code, total, band}. У шкал без подшкал пусто.
        Schema::table('assessments', function (Blueprint $table) {
            $table->json('subscores')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropColumn('subscores');
        });

        DB::statement('ALTER TABLE scales DROP CONSTRAINT IF EXISTS scales_doctor_kind_check');

        Schema::table('scales', function (Blueprint $table) {
            $table->dropColumn(['doctor_kind', 'subscales', 'prompt']);
        });
    }
};
