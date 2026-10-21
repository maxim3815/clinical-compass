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
        // Диагнозы пациента по МКБ-10. Диагноз не удаляется, а снимается с датой: история остаётся.
        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            $table->string('icd10_code', 8);
            $table->foreign('icd10_code')->references('code')->on('icd10_codes');
            // Основной или сопутствующий.
            $table->boolean('is_main');
            $table->date('established_on');
            $table->foreignId('created_by_id')->constrained('users');
            $table->date('resolved_on')->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['patient_id', 'resolved_on']);
        });

        DB::statement('ALTER TABLE diagnoses ADD CONSTRAINT diagnoses_resolved_check CHECK ((resolved_on IS NULL) = (resolved_by_id IS NULL) AND (resolved_on IS NULL OR resolved_on >= established_on))');
        // Действующий основной диагноз один, и один код не стоит дважды.
        DB::statement('CREATE UNIQUE INDEX diagnoses_main_unique ON diagnoses (patient_id) WHERE is_main AND resolved_on IS NULL');
        DB::statement('CREATE UNIQUE INDEX diagnoses_code_unique ON diagnoses (patient_id, icd10_code) WHERE resolved_on IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('diagnoses');
    }
};
