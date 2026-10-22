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
        // Заполненные пациентом опросники: сумма и степень на момент заполнения. Опросники,
        // отправленные вместе, получают одно время отправки.
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users');
            $table->foreignId('scale_id')->constrained();
            $table->string('scale_version', 64);
            $table->unsignedSmallInteger('total');
            $table->string('band');
            // Дата заполнения по часовому поясу пациента: по ней закрываются сроки календаря.
            $table->date('local_date');
            // С миллисекундами: от этого момента считается время доставки алерта врачу.
            $table->timestamp('submitted_at', 3);

            $table->index(['patient_id', 'scale_id', 'submitted_at']);
        });

        // Ответ на каждый вопрос: номер вопроса с 1 и балл выбранного варианта.
        Schema::create('assessment_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained();
            $table->unsignedSmallInteger('item');
            $table->unsignedSmallInteger('score');

            $table->unique(['assessment_id', 'item']);
        });

        DB::statement('ALTER TABLE assessment_answers ADD CONSTRAINT assessment_answers_item_check CHECK (item >= 1)');

        // Заполненный опросник — медицинские данные: изменить или удалить его не даёт сама база.
        // Функция переживает migrate:fresh (он удаляет только таблицы), поэтому CREATE OR REPLACE.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION assessments_append_only() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: % is not allowed', TG_TABLE_NAME, TG_OP;
            END;
            $$;

            CREATE TRIGGER assessments_append_only
                BEFORE UPDATE OR DELETE ON assessments
                FOR EACH ROW EXECUTE FUNCTION assessments_append_only();

            CREATE TRIGGER assessment_answers_append_only
                BEFORE UPDATE OR DELETE ON assessment_answers
                FOR EACH ROW EXECUTE FUNCTION assessments_append_only();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assessment_answers');
        Schema::dropIfExists('assessments');
        DB::unprepared('DROP FUNCTION IF EXISTS assessments_append_only()');
    }
};
