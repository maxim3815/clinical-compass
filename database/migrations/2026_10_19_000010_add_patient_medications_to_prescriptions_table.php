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
        // Препарат, добавленный пациентом: из справочника без дозировки или «другой» с названием
        // текстом. Дозу пациент пишет текстом, таблеток у таких назначений нет.
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->string('drug_name', 200)->nullable();
            $table->string('dose', 100)->nullable();
            // Кто назначил препарат пациенту, со слов пациента.
            $table->string('prescribed_by', 200)->nullable();
        });

        DB::statement('ALTER TABLE prescriptions ALTER COLUMN drug_id DROP NOT NULL');
        DB::statement('ALTER TABLE prescriptions ALTER COLUMN drug_strength_id DROP NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_source_check CHECK (
                (source = 'doctor' AND drug_id IS NOT NULL AND drug_strength_id IS NOT NULL
                    AND drug_name IS NULL AND dose IS NULL AND prescribed_by IS NULL)
                OR (source = 'patient' AND drug_strength_id IS NULL AND (drug_id IS NULL) <> (drug_name IS NULL)
                    AND dose IS NOT NULL AND tablets IS NULL AND prescriber_id IS NULL)
            )
            SQL);

        DB::statement('ALTER TABLE prescription_intakes ALTER COLUMN tablets DROP NOT NULL');
        DB::statement('ALTER TABLE dose_events ALTER COLUMN tablets DROP NOT NULL');
        DB::statement('ALTER TABLE as_needed_doses ALTER COLUMN tablets DROP NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Без доз текстом препараты пациента не сохранить: они удаляются вместе с приёмами.
        $own = "SELECT id FROM prescriptions WHERE source = 'patient'";
        DB::statement("DELETE FROM dose_events WHERE prescription_id IN ({$own})");
        DB::statement("DELETE FROM as_needed_doses WHERE prescription_id IN ({$own})");
        DB::statement("DELETE FROM prescription_intakes WHERE prescription_id IN ({$own})");
        DB::statement("DELETE FROM prescriptions WHERE source = 'patient'");

        DB::statement('ALTER TABLE as_needed_doses ALTER COLUMN tablets SET NOT NULL');
        DB::statement('ALTER TABLE dose_events ALTER COLUMN tablets SET NOT NULL');
        DB::statement('ALTER TABLE prescription_intakes ALTER COLUMN tablets SET NOT NULL');

        DB::statement('ALTER TABLE prescriptions DROP CONSTRAINT prescriptions_source_check');
        DB::statement('ALTER TABLE prescriptions ALTER COLUMN drug_strength_id SET NOT NULL');
        DB::statement('ALTER TABLE prescriptions ALTER COLUMN drug_id SET NOT NULL');

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn(['drug_name', 'dose', 'prescribed_by']);
        });
    }
};
