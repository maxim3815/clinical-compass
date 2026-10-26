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
        // Вид препарата: catalog — справочник, который врач назначает (database/data/drugs.yaml), known —
        // список препаратов от других врачей (database/data/known-drugs.yaml). Препарат списка пациент
        // добавляет сам, на него срабатывают правила препаратов справочника по группам. Дозировок,
        // режима, инструкции и окна приёма у него нет, регистрацию подтверждает номер РУ из ГРЛС
        // (source_trade_name и source_registration_number).
        Schema::table('drugs', function (Blueprint $table) {
            $table->string('kind', 16)->default('catalog')->after('code');
            // Торговые названия для поиска: «Трамал», «Кардиомагнил».
            $table->jsonb('trade_names')->default('[]');
            // Почему препарат в списке: какая инструкция препарата справочника его называет.
            $table->text('reason')->nullable();

            $table->string('dosage_form')->nullable()->change();
            $table->text('regimen')->nullable()->change();
            $table->text('patient_instruction')->nullable()->change();
            $table->text('missed_dose')->nullable()->change();
            $table->string('source_document')->nullable()->change();
            $table->string('source_url', 2048)->nullable()->change();
            $table->string('source_edition')->nullable()->change();
        });

        DB::statement("ALTER TABLE drugs ADD CONSTRAINT drugs_kind_check CHECK (kind IN ('catalog', 'known'))");
        DB::statement(<<<'SQL'
            ALTER TABLE drugs ADD CONSTRAINT drugs_kind_fields_check CHECK (
                (kind = 'catalog'
                    AND dosage_form IS NOT NULL AND regimen IS NOT NULL AND patient_instruction IS NOT NULL
                    AND missed_dose IS NOT NULL AND source_document IS NOT NULL AND source_url IS NOT NULL
                    AND source_edition IS NOT NULL AND reason IS NULL)
                OR (kind = 'known'
                    AND dosage_form IS NULL AND regimen IS NULL AND patient_instruction IS NULL
                    AND missed_dose IS NULL AND window_hours IS NULL AND source_document IS NULL
                    AND source_url IS NULL AND source_edition IS NULL AND reason IS NOT NULL)
            )
            SQL);
    }

    /**
     * Reverse the migrations. Препараты списка перед этим должны быть удалены.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE drugs DROP CONSTRAINT drugs_kind_fields_check');
        DB::statement('ALTER TABLE drugs DROP CONSTRAINT drugs_kind_check');

        Schema::table('drugs', function (Blueprint $table) {
            $table->dropColumn(['kind', 'trade_names', 'reason']);
            $table->string('dosage_form')->nullable(false)->change();
            $table->text('regimen')->nullable(false)->change();
            $table->text('patient_instruction')->nullable(false)->change();
            $table->text('missed_dose')->nullable(false)->change();
            $table->string('source_document')->nullable(false)->change();
            $table->string('source_url', 2048)->nullable(false)->change();
            $table->string('source_edition')->nullable(false)->change();
        });
    }
};
