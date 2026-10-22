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
        // Правила алертинга: условие по ответам опросников, уровень, текст для врача и источник.
        // Записи загружает app:alerts:import из database/data/alerts/<код>.yaml.
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('level', 32);
            $table->text('condition');
            $table->text('text');
            $table->string('source_document');
            $table->string('source_section');
            $table->string('source_url', 2048);
            $table->text('source_quote')->nullable();
            // Хеш содержимого правила: алерт и решение врача ссылаются на версию, по которой сработали.
            $table->string('version', 64);
            // Правило, убранное из файла, выключается: на него ссылаются алерты.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE alert_rules ADD CONSTRAINT alert_rules_level_check CHECK (level IN ('critical'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};
