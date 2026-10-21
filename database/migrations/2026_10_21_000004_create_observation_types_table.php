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
        // Виды фактов о пациенте: беременность, СКФ, печёночная недостаточность и т. д. Записи
        // загружает команда app:rules:import из database/data/observation-types.yaml: новый вид
        // факта добавляется правкой файла, без миграции.
        Schema::create('observation_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            // boolean, number или choice.
            $table->string('value_type', 16);
            $table->string('unit', 64)->nullable();
            // Допустимый диапазон у вида number: защита от опечаток при вводе.
            $table->decimal('min', 8, 2)->nullable();
            $table->decimal('max', 8, 2)->nullable();
            // Варианты у вида choice: список {value, label}. json, а не jsonb: jsonb переставляет
            // ключи, и повторная загрузка того же файла видела бы изменение.
            $table->json('options')->nullable();
            // Беременность и лактация: только у женщин.
            $table->boolean('female_only')->default(false);
            $table->unsignedSmallInteger('position');
            // Вид, убранный из файла, остаётся для внесённых фактов, но новые по нему не вносятся.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE observation_types ADD CONSTRAINT observation_types_value_type_check CHECK (value_type IN ('boolean', 'number', 'choice'))");
        DB::statement("ALTER TABLE observation_types ADD CONSTRAINT observation_types_options_check CHECK ((value_type = 'choice') = (options IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('observation_types');
    }
};
