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
        Schema::create('care_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained('users');
            $table->foreignId('patient_id')->constrained('users');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'ended_at']);
        });

        // Завершённые связи хранятся как история, активная у пациента может быть только одна.
        DB::statement('CREATE UNIQUE INDEX care_relationships_active_patient_unique ON care_relationships (patient_id) WHERE ended_at IS NULL');
        DB::statement('ALTER TABLE care_relationships ADD CONSTRAINT care_relationships_period_check CHECK (ended_at IS NULL OR ended_at >= started_at)');
        DB::statement('ALTER TABLE care_relationships ADD CONSTRAINT care_relationships_distinct_users_check CHECK (doctor_id <> patient_id)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('care_relationships');
    }
};
