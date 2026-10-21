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
        // Справочник МКБ-10 из НСИ Минздрава. Записи загружает команда app:icd10:import
        // из database/data/icd10.csv: рубрики и подрубрики, без блоков и классов.
        Schema::create('icd10_codes', function (Blueprint $table) {
            $table->string('code', 8)->primary();
            $table->text('name');
        });

        // Поиск диагноза по названию при вводе.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        DB::statement('CREATE INDEX icd10_codes_name_trgm ON icd10_codes USING gin (name gin_trgm_ops)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('icd10_codes');
    }
};
