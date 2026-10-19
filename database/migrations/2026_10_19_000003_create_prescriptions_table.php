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
        // Назначение препарата. Каждая запись — версия: изменение действующего назначения
        // закрывает запись и создаёт новую, плановые приёмы остаются привязаны к своей версии.
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            // Назначил врач или пациент добавил препарат сам.
            $table->string('source', 16);
            // Врач, который создал эту версию.
            $table->foreignId('prescriber_id')->nullable()->constrained('users');
            $table->foreignId('previous_id')->nullable()->unique()->constrained('prescriptions');
            $table->foreignId('drug_id')->constrained();
            $table->foreignId('drug_strength_id')->constrained();
            $table->string('kind', 16);
            // Только у приёма «по необходимости»: таблеток за раз и сколько раз в сутки самое большее.
            // Дозы регулярного приёма лежат в prescription_intakes.
            $table->decimal('tablets', 3, 1)->nullable();
            $table->unsignedSmallInteger('max_daily_doses')->nullable();
            $table->text('instruction')->nullable();
            // Даты по часовому поясу пациента.
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by_id')->nullable()->constrained('users');
            $table->string('close_reason', 16)->nullable();
            $table->string('cancel_reason', 32)->nullable();
            $table->text('cancel_comment')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'closed_at']);
        });

        DB::statement('ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_period_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_regular_dose_check CHECK (kind <> 'regular' OR (tablets IS NULL AND max_daily_doses IS NULL))");
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_doctor_as_needed_check CHECK (source <> 'doctor' OR kind <> 'as_needed' OR (tablets IS NOT NULL AND max_daily_doses IS NOT NULL))");
        // Таблетка делится только пополам.
        DB::statement('ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_tablets_check CHECK (tablets > 0 AND tablets * 2 = trunc(tablets * 2))');
        DB::statement('ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_max_daily_doses_check CHECK (max_daily_doses > 0)');
        DB::statement('ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_closed_check CHECK ((closed_at IS NULL) = (close_reason IS NULL))');
        DB::statement("ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_cancel_reason_check CHECK (close_reason = 'cancelled' OR (cancel_reason IS NULL AND cancel_comment IS NULL))");

        // У пациента не может быть двух незакрытых назначений врача на один препарат и вид
        // с пересекающимися датами. Приложение проверяет это само и объясняет врачу,
        // ограничение страхует от гонок.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement(<<<'SQL'
            ALTER TABLE prescriptions ADD CONSTRAINT prescriptions_doctor_overlap_excl
            EXCLUDE USING gist (patient_id WITH =, drug_id WITH =, kind WITH =, daterange(starts_on, ends_on, '[]') WITH &&)
            WHERE (source = 'doctor' AND closed_at IS NULL)
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prescriptions');
    }
};
