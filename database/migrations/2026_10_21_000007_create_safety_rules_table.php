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
        // Правила проверки назначения: противопоказания, взаимодействия, «с осторожностью»
        // и показания. Записи загружает app:rules:import из database/data/rules/<код препарата>.yaml.
        Schema::create('safety_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 128)->unique();
            $table->foreignId('drug_id')->constrained();
            $table->string('type', 32);
            $table->string('level', 32);
            // Условие на языке выражений, у правила показаний вместо него коды МКБ-10.
            $table->text('condition')->nullable();
            $table->jsonb('icd10_codes')->nullable();
            $table->text('text');
            $table->text('advice')->nullable();
            $table->string('source_document');
            $table->string('source_section');
            $table->string('source_url', 2048);
            $table->text('source_quote')->nullable();
            // Хеш содержимого правила: находка и решение врача ссылаются на версию, которую видел врач.
            $table->string('version', 64);
            // Правило, убранное из файла, выключается: на него ссылаются находки и решения.
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
        });

        DB::statement("ALTER TABLE safety_rules ADD CONSTRAINT safety_rules_type_check CHECK (type IN ('contraindication', 'interaction', 'caution', 'indication'))");
        DB::statement("ALTER TABLE safety_rules ADD CONSTRAINT safety_rules_level_check CHECK (level IN ('contraindicated', 'caution'))");
        DB::statement("ALTER TABLE safety_rules ADD CONSTRAINT safety_rules_condition_check CHECK ((type = 'indication') = (condition IS NULL) AND (type = 'indication') = (icd10_codes IS NOT NULL))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('safety_rules');
    }
};
