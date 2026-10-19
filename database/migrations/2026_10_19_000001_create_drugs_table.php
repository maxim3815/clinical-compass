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
        // Справочник препаратов. Записи загружает команда app:drugs:import из database/data/drugs.yaml.
        Schema::create('drugs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('inn');
            $table->string('atc', 16);
            $table->string('dosage_form');
            $table->text('regimen');
            $table->text('patient_instruction');
            $table->text('missed_dose');
            // Пусто: действует правило окна по умолчанию.
            $table->unsignedSmallInteger('window_hours')->nullable();
            $table->string('source_trade_name');
            $table->string('source_document');
            $table->string('source_registration_number');
            $table->string('source_url', 2048);
            $table->string('source_edition');
            // Препарат, убранный из файла, остаётся для действующих назначений, но новые на него нельзя.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE drugs ADD CONSTRAINT drugs_window_hours_check CHECK (window_hours > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drugs');
    }
};
