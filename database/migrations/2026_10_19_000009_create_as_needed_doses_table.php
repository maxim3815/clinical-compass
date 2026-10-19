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
        // Приём «по необходимости»: плановых приёмов у таких назначений нет, пациент записывает
        // каждый приём сам. Время записи — created_at.
        Schema::create('as_needed_doses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            $table->foreignId('prescription_id')->constrained();
            // Доза на момент приёма.
            $table->decimal('tablets', 3, 1);
            // Дата по часовому поясу пациента: по ней считается лимит за сутки.
            $table->date('local_date');
            $table->timestamp('taken_at');
            $table->timestamps();

            $table->index(['patient_id', 'local_date']);
        });

        // Таблетка делится только пополам.
        DB::statement('ALTER TABLE as_needed_doses ADD CONSTRAINT as_needed_doses_tablets_check CHECK (tablets > 0 AND tablets * 2 = trunc(tablets * 2))');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('as_needed_doses');
    }
};
