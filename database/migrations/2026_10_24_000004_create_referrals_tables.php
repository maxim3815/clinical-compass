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
        // Вид врача: кабинет психиатра или небольшой кабинет терапевта для отбора и направления.
        // Специальность остаётся подписью для пациента.
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->string('kind', 16)->default('psychiatrist');
        });

        DB::statement("ALTER TABLE doctor_profiles ADD CONSTRAINT doctor_profiles_kind_check CHECK (kind IN ('psychiatrist', 'therapist'))");

        // Кто заполняет шкалу: пациент (GAD-7, PHQ-9) или врач на приёме (HARS). У шкалы врача
        // нет нейтрального названия для пациента и календаря.
        Schema::table('scales', function (Blueprint $table) {
            $table->string('rater', 16)->default('patient');
            $table->string('patient_title')->nullable()->change();
            $table->json('schedule_days')->nullable()->change();
            $table->unsignedSmallInteger('schedule_then_every')->nullable()->change();
        });

        DB::statement("ALTER TABLE scales ADD CONSTRAINT scales_rater_check CHECK (rater IN ('patient', 'doctor'))");
        DB::statement("ALTER TABLE scales ADD CONSTRAINT scales_patient_fields_check CHECK (rater = 'doctor' OR (patient_title IS NOT NULL AND schedule_days IS NOT NULL AND schedule_then_every IS NOT NULL))");

        // Врач, который заполнил шкалу на приёме. У шкал пациента пусто.
        Schema::table('assessments', function (Blueprint $table) {
            $table->foreignId('rated_by_id')->nullable()->constrained('users');
        });

        // Правила подсказки направления из database/data/referral-rules.yaml. Правило, которого
        // больше нет в файле, выключается.
        Schema::create('referral_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('category', 16);
            $table->text('condition');
            $table->text('text');
            // json, а не jsonb: jsonb переставляет ключи, и повторная загрузка того же файла
            // видела бы изменение.
            $table->json('source');
            $table->unsignedSmallInteger('position');
            $table->string('version', 64);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE referral_rules ADD CONSTRAINT referral_rules_category_check CHECK (category IN ('required', 'desirable'))");

        // Направление к психиатру от терапевта. Подсказка и основание сохраняются на момент
        // направления: правила и шкалы потом меняются.
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('from_doctor_id')->constrained('users');
            $table->foreignId('to_doctor_id')->constrained('users');
            $table->string('category', 16);
            $table->string('suggested_category', 16)->nullable();
            $table->json('basis');
            $table->text('comment')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamps();

            $table->index(['from_doctor_id', 'created_at']);
        });

        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referrals_category_check CHECK (category IN ('required', 'desirable'))");
        DB::statement("ALTER TABLE referrals ADD CONSTRAINT referrals_suggested_category_check CHECK (suggested_category IS NULL OR suggested_category IN ('required', 'desirable'))");
        DB::statement('ALTER TABLE referrals ADD CONSTRAINT referrals_state_check CHECK (accepted_at IS NULL OR withdrawn_at IS NULL)');
        // У пациента одно открытое направление: ещё не принято и не отозвано.
        DB::statement('CREATE UNIQUE INDEX referrals_open_patient_unique ON referrals (patient_id) WHERE accepted_at IS NULL AND withdrawn_at IS NULL');
        DB::statement('CREATE INDEX referrals_open_to_doctor_index ON referrals (to_doctor_id) WHERE accepted_at IS NULL AND withdrawn_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_rules');

        Schema::table('assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rated_by_id');
        });

        DB::statement('ALTER TABLE scales DROP CONSTRAINT scales_patient_fields_check');
        DB::statement('ALTER TABLE scales DROP CONSTRAINT scales_rater_check');
        DB::statement("DELETE FROM scales WHERE rater = 'doctor'");

        Schema::table('scales', function (Blueprint $table) {
            $table->dropColumn('rater');
            $table->string('patient_title')->nullable(false)->change();
            $table->json('schedule_days')->nullable(false)->change();
            $table->unsignedSmallInteger('schedule_then_every')->nullable(false)->change();
        });

        DB::statement('ALTER TABLE doctor_profiles DROP CONSTRAINT doctor_profiles_kind_check');

        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
