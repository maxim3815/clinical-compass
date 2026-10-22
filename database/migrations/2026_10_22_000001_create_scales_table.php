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
        // Опросники, которые заполняет пациент: GAD-7, PHQ-9. Записи загружает app:scales:import
        // из database/data/scales/<код>.yaml: новая шкала добавляется файлом, без миграции.
        Schema::create('scales', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('title');
            $table->string('patient_title');
            $table->text('instruction');
            // Варианты ответа {score, label}, вопросы {text, highlight}, степени {min, max, label}.
            // json, а не jsonb: jsonb переставляет ключи, и повторная загрузка того же файла
            // видела бы изменение.
            $table->json('options');
            $table->json('items');
            $table->json('bands');
            // Сроки заполнения в днях от начала наблюдения у врача, дальше каждые then_every дней.
            $table->json('schedule_days');
            $table->unsignedSmallInteger('schedule_then_every');
            $table->text('source_original');
            $table->text('source_validation');
            $table->string('source_url', 2048);
            $table->text('source_license');
            $table->text('source_note')->nullable();
            // Хеш содержимого: заполнение ссылается на версию, по которой его заполнили.
            $table->string('version', 64);
            $table->unsignedSmallInteger('position');
            // Шкала, убранная из файла, выключается: на неё ссылаются заполнения.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE scales ADD CONSTRAINT scales_schedule_then_every_check CHECK (schedule_then_every > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scales');
    }
};
